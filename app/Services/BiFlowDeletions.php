<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiFlowDeletions
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    public function revision(Connection $connection, Project $project, string $ref): array
    {
        $this->managed($project, $ref);
        [$context, $user] = $this->context($connection, $project);
        $generation = $connection->metadata['session_generation'] ?? null;
        $snapshot = $this->catalog->snapshot($user, (string) $project->tenant_id, true);
        $metadata = $this->catalog->workingMetadata($context);
        foreach (['table', 'app', 'schedule'] as $kind) {
            if (($snapshot['metadata_fresh'] ?? false) !== true || ($snapshot['capabilities'][$kind]['status'] ?? '') !== 'available') {
                throw new RuntimeException('Não foi possível conferir todas as tabelas, aplicações e agendas autorizadas. A exclusão não será preparada com vínculos incompletos.');
            }
            foreach ($snapshot['resources'][$kind] as $item) {
                if (! is_array($metadata[$item['ref']] ?? null)) {
                    throw new RuntimeException('Faltam metadados de vínculos para conferir esta exclusão. Nenhum recurso será removido.');
                }
            }
        }
        $body = $this->body($user, $context, $ref);
        $refs = $this->tables($body);
        foreach ($snapshot['resources']['table'] as $item) {
            $owner = $metadata[$item['ref']]['originFlowId'] ?? null;
            if ((string) $owner === substr($ref, 5)) {
                $refs[] = $item['ref'];
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs);
        foreach ($snapshot['resources']['app'] as $item) {
            $bindings = $metadata[$item['ref']]['tables'] ?? null;
            if (! is_array($bindings) || ! array_is_list($bindings)) {
                throw new RuntimeException('As fontes de uma aplicação não foram identificadas. Confira os vínculos antes de excluir o ETL.');
            }
            foreach ($bindings as $binding) {
                if (! is_array($binding) || ! isset($binding['tableId']) || (! is_string($binding['tableId']) && ! is_int($binding['tableId'])) || ! preg_match('/^[1-9][0-9]*$/D', (string) $binding['tableId'])) {
                    throw new RuntimeException('Uma aplicação contém uma fonte não identificada. A exclusão foi interrompida.');
                }
                if (in_array('table:'.$binding['tableId'], $refs, true)) {
                    throw new RuntimeException('Uma aplicação ainda usa uma tabela deste ETL. Remova ou substitua esse vínculo antes de preparar a exclusão.');
                }
            }
        }
        foreach ($snapshot['resources']['schedule'] as $item) {
            $bindings = $metadata[$item['ref']]['flows'] ?? null;
            if (! is_array($bindings) || ! array_is_list($bindings)) {
                throw new RuntimeException('As fontes de uma agenda não foram identificadas. Confira os vínculos antes de excluir o ETL.');
            }
            foreach ($bindings as $binding) {
                if (! is_array($binding) || ! isset($binding['flow_etl_id']) || (! is_string($binding['flow_etl_id']) && ! is_int($binding['flow_etl_id'])) || ! preg_match('/^[1-9][0-9]*$/D', (string) $binding['flow_etl_id'])) {
                    throw new RuntimeException('Uma agenda contém uma fonte não identificada. A exclusão foi interrompida.');
                }
                if ('flow:'.$binding['flow_etl_id'] === $ref) {
                    throw new RuntimeException('Uma agenda ainda atualiza este ETL. Remova esse vínculo antes de preparar a exclusão.');
                }
            }
        }
        $tables = [];
        foreach ($refs as $tableRef) {
            if (ManagedBiResource::where('user_id', $project->user_id)->where('tenant_id', $project->tenant_id)->where('resource', $tableRef)->where('project_id', '!=', $project->id)->exists()) {
                throw new RuntimeException('Uma tabela pertence a outro projeto do portal. Confira os vínculos antes de excluir este ETL.');
            }
            $table = $this->body($user, $context, $tableRef);
            if ((string) ($table['originFlowId'] ?? '') !== substr($ref, 5)) {
                throw new RuntimeException('Uma tabela não foi confirmada como destino privado deste ETL. A exclusão foi interrompida.');
            }
            $tables[] = ['resource' => $tableRef, 'name' => $this->name($table, $tableRef), 'sha256' => $this->digest($table), 'column_count' => count($table['columns'] ?? [])];
        }
        foreach ([$ref, ...$refs] as $target) {
            $this->lumo->run($connection, ['pull', $target], $project, 90);
            $diff = $this->lumo->run($connection, ['diff', $target, '--include-remote'], $project);
            if (($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
                throw new RuntimeException('Há alterações locais ou remotas não conferidas. Revise o rascunho antes de preparar a exclusão.');
            }
        }
        $this->context($connection, $project);
        if (($connection->fresh()?->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A sessão BI mudou durante a preparação. Atualize os acessos e prepare uma nova revisão.');
        }

        return ['tenant_id' => $project->tenant_id, 'resource' => $ref, 'info' => ['data' => ['id' => substr($ref, 5), 'nome' => $this->name($body, $ref)]], 'changes' => [], 'flow_deletion' => ['kind' => 'flow', 'resource' => $ref, 'name' => $this->name($body, $ref), 'sha256' => $this->digest($body), 'tables' => $tables, 'cascade' => false, 'impact' => 'O ETL em rascunho e suas tabelas de destino serão excluídos, incluindo dados de teste já carregados. A conexão e a versão original serão preservadas. Dependências recusadas pelo BI não serão removidas em cascata. Uma revisão local preserva a configuração, mas não os dados das tabelas.']];
    }

    public function execute(Approval $approval, Connection $connection, Project $project, array $plan): array
    {
        $ref = $plan['resource'] ?? '';
        $managed = $this->managed($project, $ref);
        if ($approval->status !== 'executing' || isset($approval->payload['deletion_attempt']) || ($approval->payload['revision']['flow_deletion'] ?? null) !== $plan) {
            throw new RuntimeException('Esta exclusão exige uma revisão atual ainda não enviada. Confira o resultado existente antes de repetir.');
        }
        $checkpoint = $this->checkpoint($project, $plan);
        DB::transaction(function () use ($approval, $managed, $plan, $checkpoint): void {
            $current = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $resource = ManagedBiResource::whereKey($managed->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'executing' || isset($current->payload['deletion_attempt']) || $resource->status !== 'draft') {
                throw new RuntimeException('O estado da exclusão mudou. Confira a operação antes de continuar.');
            }
            $attempt = ['started_at' => now()->toIso8601String(), 'plan_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), 'checkpoint' => $checkpoint];
            $current->update(['payload' => [...$current->payload, 'deletion_attempt' => $attempt]]);
            $resource->update(['status' => 'deletion_pending', 'receipt' => [...($resource->receipt ?? []), 'deletion_attempt' => ['approval_id' => $approval->id, ...$attempt]]]);
            $approval->setRawAttributes($current->getAttributes(), true);
        });
        try {
            $native = $this->lumo->run($connection, ['rm', $ref, '--force'], $project, 90);
        } catch (Throwable $error) {
            if (in_array($error->getCode(), [3, 4, 8, 13, 14], true)) {
                $managed->refresh()->update(['status' => 'draft']);
            }
            if ($error->getCode() === 13) {
                throw new RuntimeException('O BI recusou a exclusão porque há dependências. Nenhuma exclusão em cascata foi autorizada. Confira os vínculos antes de preparar outra revisão.', 13);
            }
            throw $error;
        }
        $approval->refresh()->update(['payload' => [...$approval->payload, 'deletion_attempt' => [...$approval->payload['deletion_attempt'], 'native_accepted_at' => now()->toIso8601String()]]]);
        $confirmation = $this->verify($connection, $project, $plan);
        $this->recordVerified($project, $confirmation);
        Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$project->tenant_id);
        Audit::record($project->user_id, 'bi.flow_draft.deleted', 'ETL de teste e seus destinos removidos após revisão e conferência, sem cascata.', $project->id, ['resource' => $ref, 'tables' => array_column($plan['tables'], 'resource'), 'approval_id' => $approval->id]);

        return ['exit_code' => $native['exit_code'], 'deletion' => $confirmation];
    }

    public function reconcile(User $user, string $id, ?AgentRun $run = null): array
    {
        abort_if($user->role === 'viewer' || ! $user->canStartWork(), 403, 'Seu acesso não permite conferir esta operação.');
        $approval = Approval::where('user_id', $user->id)->when($run, fn ($query) => $query->where('project_id', $run->conversation->project_id))->findOrFail($id);
        $operation = function () use ($approval, $user, $run): array {
            $approval->refresh();
            $project = Project::where('user_id', $user->id)->findOrFail($approval->project_id);
            $arguments = $approval->payload['action'] ?? [];
            $revision = $approval->payload['revision'] ?? [];
            $plan = $revision['flow_deletion'] ?? null;
            abort_unless(($run === null || $run->fresh()?->status === 'running') && $approval->permitsUserAccess($user) && $approval->decided_at !== null && ($arguments['action'] ?? null) === 'delete_draft' && is_array($plan) && ($plan['resource'] ?? null) === ($arguments['resource'] ?? null), 422, 'Esta revisão não contém uma exclusão de ETL autorizada para conferir.');
            abort_unless(in_array($approval->status, ['failed', 'completed'], true), 409, 'Aguarde a execução terminar antes de conferir o resultado.');
            $fingerprint = hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR));
            $attempt = $approval->payload['deletion_attempt'] ?? [];
            abort_unless(hash_equals($approval->fingerprint, $fingerprint) && ! empty($attempt['started_at']) && hash_equals(hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), (string) ($attempt['plan_sha256'] ?? '')), 409, 'Não há registro íntegro de envio desta exclusão. Confira a operação sem reenviá-la.');
            [$connection] = $this->catalog->access($user, (string) $project->tenant_id);
            $confirmation = $this->verify($connection, $project, $plan);
            DB::transaction(function () use ($approval, $project, $confirmation, $fingerprint): void {
                $current = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->status === $approval->status && $current->payload === $approval->payload && hash_equals($current->fingerprint, $fingerprint), 409, 'A operação mudou durante a conferência. Consulte novamente.');
                $this->recordVerified($project, $confirmation);
                $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => ['deletion' => $confirmation]], 'reconciliation' => ['previous_status' => $approval->status, 'previous_message' => $approval->payload['execution']['message'] ?? null, 'checked_at' => now()->toIso8601String(), 'remote_read_only' => true]]]);
            });
            Audit::record($user->id, 'bi.flow_draft.deletion_reconciled', 'Exclusão de ETL conferida por leitura, sem novo envio.', $project->id, ['approval_id' => $approval->id, 'resource' => $plan['resource']]);
            Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$project->tenant_id);

            return ['project' => $project->refresh(), 'approval' => $approval->refresh()];
        };
        try {
            $result = $run ? $operation() : Cache::lock('project:'.$approval->project_id, 630)->block(3, $operation);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['deletion' => 'Outra tarefa deste projeto está em andamento. Aguarde para conferir a exclusão.']);
        }
        try {
            event(new WorkspaceChanged($user->id));
        } catch (Throwable) {
        }

        return $result;
    }

    private function verify(Connection $connection, Project $project, array $plan): array
    {
        [$context] = $this->context($connection, $project);
        $generation = $connection->metadata['session_generation'] ?? null;
        foreach (['flow' => [$plan['resource']], 'table' => array_column($plan['tables'], 'resource')] as $kind => $refs) {
            if ($refs === []) {
                continue;
            }
            $ids = array_map(fn (array $row): string => (string) $row['id'], $this->catalog->rows($connection, $context, $kind));
            foreach ($refs as $ref) {
                if (! preg_match('/^'.preg_quote($kind, '/').':([1-9][0-9]*)$/D', $ref, $match) || in_array($match[1], $ids, true)) {
                    throw new RuntimeException('A remoção do ETL e de todas as tabelas ainda não foi confirmada. Confira a exclusão sem reenviar.');
                }
                try {
                    $this->lumo->run($connection, ['info', $ref], $context);
                } catch (RuntimeException $error) {
                    if ($error->getCode() === 5) {
                        continue;
                    }
                    throw $error;
                }
                throw new RuntimeException('O recurso ainda responde no BI. A exclusão não está confirmada; não repita o envio.');
            }
        }
        $this->context($connection, $project);
        if (($connection->fresh()?->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A sessão BI mudou durante a confirmação. Consulte o resultado novamente.');
        }

        return ['verified' => true, 'kind' => 'flow', 'resource' => $plan['resource'], 'name' => $plan['name'], 'table_refs' => array_column($plan['tables'], 'resource'), 'checked_at' => now()->toIso8601String(), 'message' => 'ETL de teste e tabelas de destino excluídos e conferidos. A configuração foi preservada em uma revisão local; os dados das tabelas não fazem parte desse checkpoint.'];
    }

    private function recordVerified(Project $project, array $confirmation): void
    {
        DB::transaction(function () use ($project, $confirmation): void {
            $current = Project::where('user_id', $project->user_id)->lockForUpdate()->findOrFail($project->id);
            abort_unless($current->tenant_id === $project->tenant_id, 409, 'O ambiente do projeto mudou durante a conferência.');
            $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $confirmation['resource'])->lockForUpdate()->firstOrFail();
            $managed->update(['status' => 'deleted', 'receipt' => [...($managed->receipt ?? []), 'deletion' => $confirmation]]);
            ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->whereIn('resource', $confirmation['table_refs'])->update(['status' => 'deleted']);
            foreach ($confirmation['table_refs'] as $ref) {
                ManagedBiResource::firstOrCreate(['user_id' => $project->user_id, 'tenant_id' => $project->tenant_id, 'resource' => $ref], ['project_id' => $project->id, 'origin' => 'flow_deletion', 'source_ref' => $confirmation['resource'], 'status' => 'deleted', 'receipt' => ['deletion' => $confirmation]]);
            }
            if ($current->source_ref === $confirmation['resource']) {
                $current->update(['source_ref' => null]);
            }
        });
    }

    private function managed(Project $project, string $ref): ManagedBiResource
    {
        if (! preg_match('/^flow:[1-9][0-9]*$/D', $ref)) {
            throw new RuntimeException('Escolha um ETL em rascunho criado pelo portal.');
        }

        return ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('status', 'draft')->first() ?? throw new RuntimeException('A exclusão permite somente ETLs em rascunho criados e registrados por este projeto. Recursos preexistentes não podem ser excluídos.');
    }

    private function context(Connection $connection, Project $project): array
    {
        $user = User::findOrFail($project->user_id);
        if (! $user->canStartWork() || $user->role === 'viewer' || $connection->user_id !== $project->user_id) {
            throw new RuntimeException('Seu acesso não permite excluir este ETL.');
        }
        [$current, $tenant] = $this->catalog->access($user, (string) $project->tenant_id);
        if ($current->id !== $connection->id || ($current->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A sessão BI mudou durante a conferência. Confira os acessos e consulte novamente.');
        }

        return [$this->catalog->context($current, $tenant), $user];
    }

    private function body(User $user, Project $context, string $ref): array
    {
        [$kind, $id] = explode(':', $ref, 2);
        $detail = $this->catalog->detail($user, (string) $context->tenant_id, $kind, $id, false);
        $body = $this->catalog->workingMetadata($context, [$ref])[$ref] ?? null;
        if (($detail['metadata_complete'] ?? false) !== true || ($detail['resource']['state'] ?? null) !== 'draft' || ! is_array($body) || (string) ($detail['resource']['id'] ?? '') !== $id || (string) ($detail['tenant']['id'] ?? '') !== (string) $context->tenant_id) {
            throw new RuntimeException('O BI não confirmou a configuração atual e privada do ETL e de suas tabelas. Nenhuma exclusão foi preparada.');
        }
        $known = false;
        foreach ([$body, is_array($body['_state'] ?? null) ? $body['_state'] : []] as $state) {
            foreach (['deskId', 'desk_id'] as $key) {
                if (array_key_exists($key, $state)) {
                    $known = true;
                    if ($state[$key] !== null) {
                        throw new RuntimeException('Um recurso já está publicado ou possui estado divergente. A exclusão de rascunhos foi interrompida.');
                    }
                }
            }
        }
        if (! $known) {
            throw new RuntimeException('O estado de rascunho não foi confirmado pelo BI.');
        }

        return $body;
    }

    private function tables(array $body): array
    {
        $nodes = $body['nodes']['Processors'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new RuntimeException('As etapas deste ETL não foram identificadas para conferir a exclusão.');
        }
        $refs = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['kind'] ?? null)) {
                throw new RuntimeException('Uma etapa do ETL não foi reconhecida.');
            }
            if ($node['kind'] !== 'InsertDatawarehouse') {
                continue;
            }
            $id = $node['options']['TableID'] ?? null;
            if ($id === null || $id === '' || $id === 0) {
                continue;
            }
            if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                throw new RuntimeException('Uma tabela de destino não foi identificada com segurança.');
            }
            $refs[] = 'table:'.$id;
        }
        foreach ([$body['table']['id'] ?? null, $body['tableId'] ?? null] as $primary) {
            if ($primary !== null && $primary !== '' && $primary !== 0 && ((! is_string($primary) && ! is_int($primary)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $primary) || ! in_array('table:'.$primary, $refs, true))) {
                throw new RuntimeException('A tabela principal diverge das etapas do ETL. Confira os vínculos antes de excluir.');
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs);

        return $refs;
    }

    private function checkpoint(Project $project, array $plan): string
    {
        $root = app(WorkspaceFiles::class)->path($project);
        $expected = [$plan['resource'] => $plan['sha256']];
        foreach ($plan['tables'] as $table) {
            $expected[$table['resource']] = $table['sha256'];
        }
        $paths = [];
        foreach ($expected as $ref => $hash) {
            [$kind, $id] = explode(':', $ref, 2);
            $matches = [];
            foreach (File::files($root.'/'.($kind === 'flow' ? 'flows' : 'tables')) as $file) {
                if ($file->isLink() || $file->getSize() > 2_000_000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                    continue;
                }
                $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $header = Yaml::parse($parts[0]);
                if (! is_array($header) || (string) ($header['id'] ?? '') !== $id || ($header['kind'] ?? null) !== $kind || (string) ($header['tenantId'] ?? '') !== (string) $project->tenant_id) {
                    continue;
                }
                if (! hash_equals($hash, $this->digest(Yaml::parse($parts[1])))) {
                    throw new RuntimeException('A configuração local mudou após a revisão. Prepare outra exclusão.');
                }
                $matches[] = ($kind === 'flow' ? 'flows' : 'tables').'/'.$file->getFilename();
            }
            if (count($matches) !== 1) {
                throw new RuntimeException('O checkpoint da exclusão está ausente ou ambíguo. Confira o rascunho.');
            }
            $paths[] = $matches[0];
        }
        $environment = array_merge(ProcessEnvironment::isolated(), ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => '/tmp', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']);
        $prefix = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', '-c', 'user.name=G-Analise IA', '-c', 'user.email=local@g-analise.invalid'];
        $commit = '';
        foreach ([['init', '--quiet'], ['add', '--force', '--', ...$paths], ['commit', '--quiet', '--allow-empty', '-m', 'Antes de excluir rascunho '.$plan['resource'], '--only', '--', ...$paths], ['rev-parse', 'HEAD']] as $command) {
            $process = new Process([...$prefix, ...$command], $root, $environment);
            $process->setTimeout(15);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('A exclusão foi interrompida porque não foi possível preservar sua configuração local.');
            }
            $commit = trim($process->getOutput());
        }

        return $commit;
    }

    private function name(array $body, string $fallback): string
    {
        return is_string($body['nome'] ?? null) && trim($body['nome']) !== '' ? $body['nome'] : $fallback;
    }

    private function digest(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
