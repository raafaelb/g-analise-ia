<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiFlowWizard
{
    public const ACTIONS = ['create_source_flow', 'test_source_flow', 'create_source_table', 'load_source_table'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private BiFlowSource $sources, private TenantPolicy $policy, private BiFlowExecution $execution) {}

    public function prepare(User $user, string $tenant, array $input): array
    {
        if (array_diff(array_keys($input), ['action', 'name', 'source', 'project_id', 'request_id']) !== []) {
            throw ValidationException::withMessages(['flow' => 'Confira os campos permitidos desta etapa do assistente.']);
        }
        $data = Validator::make($input, ['action' => ['required', 'in:'.implode(',', self::ACTIONS)], 'name' => ['required_if:action,create_source_flow', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'source' => ['required_if:action,create_source_flow', 'nullable', 'array'], 'project_id' => ['required_unless:action,create_source_flow', 'nullable', 'uuid'], 'request_id' => ['required', 'uuid']])->validate();
        $creating = $data['action'] === 'create_source_flow';
        if (($creating && ! empty($data['project_id'])) || (! $creating && (isset($data['source']) || isset($data['name'])))) {
            throw ValidationException::withMessages(['flow' => 'A etapa não aceita outra origem ou projeto. Abra uma nova criação para escolher outra fonte.']);
        }
        $action = $creating ? ['action' => $data['action'], 'name' => trim($data['name']), 'source' => $this->sources->normalize($data['source'])] : ['action' => $data['action'], 'project_id' => $data['project_id']];
        abort_if($creating && $action['name'] === '', 422, 'Informe um nome para este ETL.');
        [$connection, $environment] = $this->access($user, $tenant);
        $hash = $this->sources->digest([$tenant, $action]);

        return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $environment, $connection, $data, $action, $creating, $hash): array {
            $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $hash), 409, 'Esta solicitação já foi usada para outra configuração. Confira a operação existente.');

                return ['project' => Project::where('user_id', $user->id)->findOrFail($existing->project_id), 'approval' => $existing, 'reused' => true];
            }
            $project = $creating ? new Project(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'tenant_id' => $tenant, 'tenant_name' => $environment['name'], 'name' => $action['name'], 'description' => 'ETL preparado pelo assistente do catálogo.', 'instructions' => 'Este projeto contém o ETL criado pelo assistente. Confira as revisões e os recursos já criados antes de repetir uma criação. Publicação e agendamento exigem suas próprias revisões.']) : $this->project($user, $tenant, $data['project_id']);
            $build = function () use ($user, $connection, $project, $action, $creating, $data, $hash): array {
                if (! $creating && Approval::where('project_id', $project->id)->where(fn ($query) => $query->whereIn('status', ['approved', 'executing'])->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '>', now())))->whereIn('payload->action->action', self::ACTIONS)->exists()) {
                    throw new RuntimeException('Já há uma revisão ou execução deste assistente em andamento. Confira a operação antes de preparar outra.');
                }
                $revision = $creating ? $this->creationRevision($user, $project, $action, ['source' => (string) Str::uuid(), 'sink' => (string) Str::uuid()]) : $this->stageRevision($user, $connection, $project, $action['action']);
                $approval = DB::transaction(function () use ($user, $connection, $project, $action, $revision, $data, $hash): Approval {
                    $this->assertCurrent($connection, $project, $revision['connection_generation']);
                    if (! $project->exists) {
                        $project->save();
                    } else {
                        $current = $this->project($user, (string) $project->tenant_id, $project->id);
                        abort_unless($current->source_ref === $project->source_ref, 409, 'O recurso do projeto mudou. Confira novamente.');
                    }
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => null, 'type' => 'manual', 'title' => match ($action['action']) {
                        'create_source_flow' => 'Criar ETL a partir da conexão', 'test_source_flow' => 'Testar extração do ETL', 'create_source_table' => 'Criar tabela do ETL', 'load_source_table' => 'Carregar tabela do ETL'
                    }, 'description' => 'Confira a origem, os campos e o efeito desta etapa antes de autorizar.', 'payload' => ['origin' => 'manual', 'action' => $action, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $hash], 'fingerprint' => $this->fingerprint($user->id, $project, $action, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.flow_wizard.review_created', 'Etapa do assistente de ETL preparada para revisão.', $project->id, ['approval_id' => $approval->id, 'tenant_id' => $project->tenant_id, 'action' => $action['action']]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['project' => $project->refresh(), 'approval' => $approval->refresh(), 'reused' => false];
            };

            return $creating ? $build() : Cache::lock('project:'.$project->id, 630)->block(3, $build);
        });
    }

    public function status(User $user, string $projectId): array
    {
        $project = Project::where('user_id', $user->id)->findOrFail($projectId);
        $this->access($user, (string) $project->tenant_id);
        $steps = Approval::where('user_id', $user->id)->where('project_id', $project->id)->whereIn('payload->action->action', self::ACTIONS)->orderBy('created_at')->orderBy('id')->get();
        $creation = $steps->first(fn (Approval $approval): bool => ($approval->payload['action']['action'] ?? '') === 'create_source_flow');
        abort_unless($creation, 404, 'Este projeto não foi criado pelo assistente de ETLs.');
        $resource = $creation->payload['flow_wizard_attempt']['resource'] ?? null;
        $tableStep = $steps->last(fn (Approval $approval): bool => ($approval->payload['action']['action'] ?? '') === 'create_source_table' && isset($approval->payload['flow_wizard_attempt']['table']));

        return ['project' => $project, 'creation' => $creation, 'steps' => $steps, 'flow' => $resource, 'table' => $tableStep?->payload['flow_wizard_attempt']['table'] ?? null];
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        $action = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $project = $this->project($user, (string) ($revision['tenant_id'] ?? ''), (string) $approval->project_id);
        if (! $approval->permitsUserAccess($user) || $approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || $approval->type !== 'manual' || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($action['action'] ?? '', self::ACTIONS, true) || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $project, $action, $revision))) {
            throw new RuntimeException('Esta revisão do ETL não está autorizada para execução.');
        }
        if (isset($approval->payload['flow_wizard_attempt']['started_at'])) {
            throw new RuntimeException('Uma tentativa desta etapa já foi iniciada. Confira o recurso e o resultado antes de repetir.');
        }
        [$connection] = $this->access($user, (string) $project->tenant_id);
        $this->assertCurrent($connection, $project, $revision['connection_generation'] ?? null);
        if ($action['action'] === 'create_source_flow') {
            if ($project->source_ref !== null) {
                throw new RuntimeException('O projeto já contém um recurso. Confira o ETL existente antes de criar outro.');
            }

            return $this->sources->withSelection($user, (string) $project->tenant_id, $action['source'], function (Connection $sourceConnection, array $source) use ($approval, $project, $action, $revision): array {
                $current = $this->createRevision($sourceConnection, $project, $action, $source, $revision['flow_wizard']['nodes']);
                if (! hash_equals($this->sources->digest($current), $this->sources->digest($revision))) {
                    throw new RuntimeException('A conexão ou os campos selecionados mudaram. Prepare outra revisão antes de criar o ETL.');
                }

                return $this->create($sourceConnection, $project, $approval, $action, $source, $revision);
            });
        }
        $current = $this->stageRevision($user, $connection, $project, $action['action']);
        if (! hash_equals($this->sources->digest($current), $this->sources->digest($revision))) {
            throw new RuntimeException('A configuração ou o resultado anterior do ETL mudou. Confira uma nova revisão.');
        }
        $flow = $revision['resource'];
        $this->attempt($approval, ['started_at' => now()->toIso8601String(), 'resource' => $flow]);
        if ($action['action'] === 'create_source_table') {
            return $this->createTable($user, $connection, $project, $approval, $revision);
        }
        $result = $this->execution->run($connection, $project, ['resource' => $flow, 'node' => $revision['etl']['node']], $approval);
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        [, $saved] = $this->document($project, $flow);
        $node = $this->node($saved, $revision['flow_wizard']['nodes']['source']);
        $fields = $node['metadata']['Fields'] ?? null;
        if (($result['workspace_refresh']['verified'] ?? false) !== true || ! is_array($fields) || $fields === []) {
            throw new RuntimeException('O comando terminou, mas o esquema atual do ETL precisa de conferência. Atualize o recurso no projeto antes de executar outra etapa.');
        }
        $safe = ['managed_resource' => $flow, 'flow_wizard' => ['step' => $action['action'], 'native_command_finished' => true, 'source_fields_sha256' => $this->sources->digest($fields), 'captured_fields' => count($fields), 'row_limit' => $revision['flow_wizard']['source']['row_limit'], 'message' => $action['action'] === 'test_source_flow' ? 'Extração concluída e esquema capturado. A tabela e a carga ainda têm etapas próprias.' : 'O comando de carga terminou. Confira a tabela e os indicadores antes de considerar os dados corretos.']];
        if ($action['action'] === 'load_source_table') {
            $captured = $this->completedStep($project, 'create_source_table')->payload['execution']['result']['flow_wizard']['source_fields_sha256'] ?? '';
            if (! hash_equals((string) $captured, $this->sources->digest($fields))) {
                throw new RuntimeException('A carga terminou, mas o esquema da fonte mudou. Confira a tabela sem reenviar automaticamente a carga.');
            }
            $safe['flow_wizard']['table'] = $revision['flow_wizard']['table'];
            $safe['flow_wizard']['data_check'] = $this->checkData($connection, $project, $revision['flow_wizard']['table']);
        }
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        Audit::record($user->id, 'bi.flow_wizard.step_completed', 'Etapa de ETL concluída pelo agente; dados exigem conferência própria.', $project->id, ['resource' => $flow, 'step' => $action['action']]);

        return $safe;
    }

    private function creationRevision(User $user, Project $project, array $action, array $nodes): array
    {
        return $this->sources->withSelection($user, (string) $project->tenant_id, $action['source'], fn (Connection $connection, array $source): array => $this->createRevision($connection, $project, $action, $source, $nodes));
    }

    private function createRevision(Connection $connection, Project $project, array $action, array $source, array $nodes): array
    {
        return ['tenant_id' => (string) $project->tenant_id, 'resource' => null, 'connection_generation' => $connection->metadata['session_generation'] ?? null, 'info' => ['data' => ['nome' => $action['name']]], 'flow_wizard' => ['step' => 'create_source_flow', 'name' => $action['name'], 'source' => $source['public'], 'source_sha256' => $source['source_sha256'], 'sql' => $source['sql'], 'nodes' => $nodes, 'load_type' => 'Total', 'write_mode' => 'replace_all', 'impact' => 'Será criado um novo ETL privado em Minha Mesa, com extração dos campos escolhidos e uma futura tabela própria. A configuração não executa a consulta, não carrega dados, não publica e não cria agenda. O teste de extração e a carga terão revisões separadas.']];
    }

    private function stageRevision(User $user, Connection $connection, Project $project, string $action): array
    {
        $creation = $this->creation($project);
        $flow = $creation->payload['flow_wizard_attempt']['resource'];
        $base = $creation->payload['revision']['flow_wizard'];
        $this->lumo->initialize($connection, $project);
        abort_unless(collect($this->catalog->rows($connection, $project, 'flow'))->contains(fn (array $row): bool => 'flow:'.$row['id'] === $flow), 404, 'O ETL não está mais disponível para sua sessão.');
        $diff = $this->lumo->run($connection, ['diff', $flow, '--include-remote'], $project);
        if (($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
            throw new RuntimeException('O ETL tem alterações que precisam de conferência. Use Atualizar do BI no projeto e confira a configuração antes de continuar.');
        }
        [, $body] = $this->document($project, $flow);
        $this->assertDraft($body);
        if (! hash_equals((string) $creation->payload['flow_wizard_attempt']['definition_sha256'], $this->definition($body))) {
            throw new RuntimeException('A origem ou as etapas deste ETL mudaram. Continue a autoria no projeto ou confira sua configuração antes de usar o assistente.');
        }
        $source = $this->node($body, $base['nodes']['source']);
        $sink = $this->node($body, $base['nodes']['sink']);
        $table = $this->managedTable($project);
        $bound = $sink['options']['TableID'] ?? null;
        if (($table === null && ! in_array($bound, [null, '', 0, '0'], true)) || ($table !== null && 'table:'.$bound !== $table)) {
            throw new RuntimeException('O destino não corresponde à tabela criada por este assistente. Confira o ETL no projeto.');
        }
        $wizard = ['step' => $action, 'name' => $body['nome'], 'source' => $base['source'], 'nodes' => $base['nodes'], 'table' => $table, 'load_type' => 'Total', 'write_mode' => 'replace_all'];
        if ($action === 'create_source_table') {
            if ($table !== null) {
                throw new RuntimeException('Este ETL já tem tabela. Confira o destino existente; o assistente não recria tabelas nem apaga seus dados.');
            }
            if (Approval::where('project_id', $project->id)->where('payload->action->action', 'create_source_table')->whereNotNull('payload->flow_wizard_attempt->started_at')->where('status', '!=', 'completed')->exists()) {
                throw new RuntimeException('Uma criação de tabela já foi iniciada e precisa de conferência. Confira o ETL e o recibo existente antes de solicitar outra criação.');
            }
            $test = $this->completedStep($project, 'test_source_flow');
            $fields = $source['metadata']['Fields'] ?? null;
            if (! is_array($fields) || $fields === [] || ! hash_equals((string) ($test->payload['execution']['result']['flow_wizard']['source_fields_sha256'] ?? ''), $this->sources->digest($fields))) {
                throw new RuntimeException('Teste a extração e confira o esquema capturado antes de criar a tabela.');
            }
            $this->execution->assertTableCreation($connection, $project, ['flow' => $flow, 'node' => $source['internalId']]);
            $wizard['source_fields_sha256'] = $this->sources->digest($fields);
            $wizard['captured_fields'] = count($fields);
            $wizard['impact'] = 'Será criada uma nova tabela privada a partir do esquema já capturado, e o BI vinculará o destino ao ETL. Esta etapa não executa carga nem recria uma tabela existente.';

            return ['tenant_id' => (string) $project->tenant_id, 'resource' => $flow, 'connection_generation' => $connection->metadata['session_generation'] ?? null, 'info' => ['data' => ['nome' => $body['nome']]], 'flow_wizard' => $wizard, 'flow_sha256' => $this->sources->digest($body)];
        }
        if ($action === 'load_source_table') {
            if ($table === null) {
                throw new RuntimeException('Crie a tabela a partir do teste de extração antes de carregar os dados.');
            }
            $tableStep = $this->completedStep($project, 'create_source_table');
            if (! hash_equals((string) ($tableStep->payload['execution']['result']['flow_wizard']['source_fields_sha256'] ?? ''), $this->sources->digest($source['metadata']['Fields'] ?? null))) {
                throw new RuntimeException('O esquema capturado mudou após a criação da tabela. Confira a estrutura no projeto antes de carregar.');
            }
            $detail = $this->catalog->detail($user, (string) $project->tenant_id, 'table', substr($table, 6), false);
            if (($detail['resource']['state'] ?? null) !== 'draft') {
                throw new RuntimeException('A tabela está compartilhada ou sem estado confirmado. A carga pelo assistente exige seu destino privado.');
            }
        }
        $node = $action === 'test_source_flow' ? $source : $sink;
        $revision = $this->execution->revision($connection, $project, ['resource' => $flow, 'node' => $node['internalId']]);
        $wizard['impact'] = $action === 'test_source_flow' ? 'O agente consultará os registros dos campos escolhidos e capturará seu esquema. Esta etapa não grava a tabela de destino.' : 'O agente executará a extração e substituirá o conteúdo da tabela privada criada por este assistente. Confira o limite de teste e os campos. Publicação e agenda permanecem separadas.';
        $this->assertCurrent($connection, $project, $revision['connection_generation']);

        return [...$revision, 'flow_wizard' => $wizard];
    }

    private function create(Connection $connection, Project $project, Approval $approval, array $action, array $source, array $revision): array
    {
        $this->lumo->initialize($connection, $project);
        $before = array_map(fn (array $row): string => 'flow:'.$row['id'], $this->catalog->rows($connection, $project, 'flow'));
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $this->attempt($approval, ['started_at' => now()->toIso8601String()]);
        $result = $this->lumo->run($connection, ['new', 'flow', '--name', $action['name'], '--load-type', 'Total'], $project, 90);
        $ref = $this->createdRef('flow', $result);
        if ($ref === null || in_array($ref, $before, true)) {
            throw new RuntimeException('A criação foi iniciada, mas o novo ETL não foi identificado com segurança. Confira o catálogo antes de solicitar outra criação.');
        }
        $this->attempt($approval, ['resource' => $ref]);
        ManagedBiResource::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'tenant_id' => $project->tenant_id, 'resource' => $ref, 'origin' => 'flow_wizard', 'status' => 'config_pending', 'receipt' => ['approval_id' => $approval->id, 'created_at' => now()->toIso8601String()]]);
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $project->update(['source_ref' => $ref]);
        [$path, $body, $header] = $this->document($project, $ref);
        $this->assertDraft($body);
        if (($body['nome'] ?? '') !== $action['name'] || ! empty($body['nodes']['Processors'])) {
            throw new RuntimeException('O novo ETL não foi confirmado vazio com o nome revisado. Confira o recurso criado antes de continuar.');
        }
        $ids = $revision['flow_wizard']['nodes'];
        $body['tokenId'] = $source['public']['agent']['id'];
        $body['load_type'] = 'Total';
        $body['write_mode'] = 'replace_all';
        $body['nodes'] = ['Processors' => [['internalId' => $ids['source'], 'kind' => match ($source['public']['driver']) {
            'postgres' => 'ExtractPostgreSQL', 'mysql' => 'ExtractMySQL', 'sqlserver' => 'ExtractSQLServer'
        }, 'description' => 'Consultar os campos selecionados da origem', 'inputs' => [], 'options' => ['Credential' => $source['credential_key'], 'SQL' => $source['sql']]], ['internalId' => $ids['sink'], 'kind' => 'InsertDatawarehouse', 'description' => 'Carregar a tabela própria deste ETL', 'inputs' => [$ids['source']], 'options' => ['TableID' => 0, 'Mode' => 'Datawarehouse']]], 'Connections' => [['from' => $ids['source'], 'to' => $ids['sink']]]];
        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($body, 30, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP), 0600);
        $validation = app(BiResourceValidation::class)->inspect($connection, $project, $ref);
        if (! $validation['passed']) {
            throw new RuntimeException('O ETL criado ainda não passou na validação da configuração. Confira esse rascunho no projeto antes de criar outro.');
        }
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $this->attempt($approval, ['push_started' => true]);
        $this->lumo->run($connection, ['push', $ref], $project, 90);
        [, $saved] = $this->document($project, $ref);
        $this->assertDraft($saved);
        if (! hash_equals($this->definition($body), $this->definition($saved))) {
            throw new RuntimeException('A configuração do rascunho não foi confirmada pelo BI. O ETL já existe; confira-o antes de repetir a criação.');
        }
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $this->attempt($approval, ['definition_sha256' => $this->definition($saved), 'configured' => true]);
        ManagedBiResource::where('project_id', $project->id)->where('resource', $ref)->update(['status' => 'draft']);
        $this->catalog->invalidate((string) $project->tenant_id, $connection);

        return ['managed_resource' => $ref, 'confirmed_name' => $saved['nome'], 'flow_wizard' => ['step' => 'create_source_flow', 'configured' => true, 'requires_extraction_test' => true, 'message' => 'ETL criado em Minha Mesa. Teste a extração, crie sua tabela e autorize a carga para conferir os dados.']];
    }

    private function createTable(User $user, Connection $connection, Project $project, Approval $approval, array $revision): array
    {
        $flow = $revision['resource'];
        $before = array_map(fn (array $row): string => 'table:'.$row['id'], $this->catalog->rows($connection, $project, 'table'));
        $this->execution->assertTableCreation($connection, $project, ['flow' => $flow, 'node' => $revision['flow_wizard']['nodes']['source']]);
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $result = $this->lumo->run($connection, ['new', 'table', '--from-flow', $flow, '--node', $revision['flow_wizard']['nodes']['source'], '--name', $revision['flow_wizard']['name'], '--table-type', 'table'], $project, 120);
        $table = $this->createdRef('table', $result);
        if ($table === null || in_array($table, $before, true)) {
            throw new RuntimeException('A criação da tabela foi iniciada sem um recibo confirmado. Confira o destino no ETL antes de repetir.');
        }
        $this->attempt($approval, ['table' => $table]);
        if (ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $project->tenant_id)->where('resource', $table)->exists()) {
            throw new RuntimeException('A tabela retornada já estava registrada. Confira o destino antes de continuar.');
        }
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => $project->tenant_id, 'resource' => $table, 'origin' => 'flow_wizard_table', 'status' => 'draft', 'receipt' => ['approval_id' => $approval->id, 'created_at' => now()->toIso8601String()]]);
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $this->lumo->run($connection, ['pull', $flow], $project, 90);
        [, $body] = $this->document($project, $flow);
        $sink = $this->node($body, $revision['flow_wizard']['nodes']['sink']);
        $detail = $this->catalog->detail($user, (string) $project->tenant_id, 'table', substr($table, 6), false);
        if ('table:'.($sink['options']['TableID'] ?? '') !== $table || ($detail['resource']['state'] ?? null) !== 'draft') {
            throw new RuntimeException('A nova tabela ou seu vínculo privado não foi confirmado. Confira o ETL sem criar outra tabela.');
        }
        $creation = $this->creation($project);
        if (! hash_equals($creation->payload['flow_wizard_attempt']['definition_sha256'], $this->definition($body))) {
            throw new RuntimeException('O ETL mudou durante a criação da tabela. Confira o destino já criado antes de continuar.');
        }
        $this->assertCurrent($connection, $project, $revision['connection_generation']);
        $this->catalog->invalidate((string) $project->tenant_id, $connection);

        return ['managed_resource' => $flow, 'flow_wizard' => ['step' => 'create_source_table', 'table' => $table, 'source_fields_sha256' => $revision['flow_wizard']['source_fields_sha256'], 'message' => 'Tabela privada criada e vinculada ao ETL. A estrutura existe; a carga ainda precisa ser autorizada.']];
    }

    private function checkData(Connection $connection, Project $project, string $table): array
    {
        try {
            $data = $this->lumo->run($connection, ['table', 'preview', $table, '--limit', '20'], $project, 60)['data'] ?? null;
            $rows = is_array($data) ? ($data['rows'] ?? null) : null;
            $verified = is_array($rows) && array_is_list($rows) && $rows !== [] && ($data['ok'] ?? true) === true && ($data['Success'] ?? true) === true;

            return ['preview_confirmed' => $verified, 'visible_rows' => $verified ? count($rows) : null, 'message' => $verified ? 'A prévia confirmou registros na tabela. Confira o conteúdo e os indicadores antes de publicar.' : 'A carga terminou, mas a prévia não confirmou registros. Confira a tabela sem repetir automaticamente a carga.'];
        } catch (Throwable) {
            return ['preview_confirmed' => false, 'visible_rows' => null, 'message' => 'A conferência da tabela continua pendente. Consulte a prévia sem reenviar a carga.'];
        }
    }

    private function creation(Project $project): Approval
    {
        $creation = Approval::where('user_id', $project->user_id)->where('project_id', $project->id)->where('payload->action->action', 'create_source_flow')->where('status', 'completed')->sole();
        $ref = $creation->payload['flow_wizard_attempt']['resource'] ?? null;
        abort_unless(is_string($ref) && preg_match('/^flow:[1-9][0-9]*$/D', $ref) && $project->source_ref === $ref && ($creation->payload['flow_wizard_attempt']['configured'] ?? false) === true && ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('origin', 'flow_wizard')->where('status', 'draft')->exists(), 422, 'O rascunho criado por este assistente precisa de conferência.');

        return $creation;
    }

    private function completedStep(Project $project, string $action): Approval
    {
        $approval = Approval::where('user_id', $project->user_id)->where('project_id', $project->id)->where('payload->action->action', $action)->where('status', 'completed')->latest('created_at')->first();
        if (! $approval) {
            throw new RuntimeException('A etapa anterior deste assistente ainda não foi concluída. Confira o acompanhamento antes de continuar.');
        }

        return $approval;
    }

    private function managedTable(Project $project): ?string
    {
        $rows = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('origin', 'flow_wizard_table')->where('status', 'draft')->get();
        if ($rows->count() > 1) {
            throw new RuntimeException('Há mais de um destino registrado. Confira o ETL no projeto.');
        }

        return $rows->first()?->resource;
    }

    private function node(array $body, string $id): array
    {
        $matches = array_values(array_filter($body['nodes']['Processors'] ?? [], fn (mixed $node): bool => is_array($node) && ($node['internalId'] ?? null) === $id));
        if (count($matches) !== 1) {
            throw new RuntimeException('A etapa do assistente não foi identificada no ETL.');
        }

        return $matches[0];
    }

    private function definition(array $body): string
    {
        $nodes = $body['nodes']['Processors'] ?? [];
        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) !== 2 || array_any($nodes, fn (mixed $node): bool => ! is_array($node) || ! is_string($node['internalId'] ?? null) || ! is_string($node['kind'] ?? null) || ! is_array($node['options'] ?? null) || ! is_array($node['inputs'] ?? []) || array_any($node['inputs'] ?? [], fn (mixed $id): bool => ! is_string($id)))) {
            throw new RuntimeException('As etapas do ETL mudaram. Confira a estrutura no projeto antes de continuar.');
        }
        $aliases = [];
        foreach ($nodes as $node) {
            foreach ([$node['internalId'] ?? null, $node['complete_id'] ?? null] as $id) {
                if (is_string($id)) {
                    $aliases[$id] = $node['internalId'];
                }
            }
        }
        $processors = [];
        foreach ($nodes as $node) {
            $options = $node['options'] ?? [];
            if (($node['kind'] ?? '') === 'InsertDatawarehouse') {
                unset($options['TableID']);
            }
            $processors[] = ['id' => $node['internalId'], 'kind' => $node['kind'], 'description' => $node['description'] ?? '', 'inputs' => array_map(fn (string $id): string => $aliases[$id] ?? $id, $node['inputs'] ?? []), 'options' => $options];
        }

        return $this->sources->digest(['name' => $body['nome'] ?? null, 'agent' => $body['tokenId'] ?? null, 'load_type' => $body['load_type'] ?? null, 'write_mode' => $body['write_mode'] ?? (($body['load_type'] ?? null) === 'Total' ? 'replace_all' : null), 'load_type_column' => $body['load_type_column'] ?? null, 'nodes' => $processors]);
    }

    private function document(Project $project, string $ref): array
    {
        $folder = app(WorkspaceFiles::class)->path($project).'/flows';
        $found = [];
        foreach (is_dir($folder) ? File::allFiles($folder) : [] as $file) {
            if ($file->isLink() || $file->getSize() > 2000000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                continue;
            }
            $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $header = Yaml::parse($parts[0]);
            if (is_array($header) && ($header['kind'] ?? '') === 'flow' && (string) ($header['tenantId'] ?? '') === (string) $project->tenant_id && 'flow:'.($header['id'] ?? '') === $ref) {
                $body = Yaml::parse($parts[1]);
                if (is_array($body)) {
                    $found[] = [$file->getPathname(), $body, $header];
                }
            }
        }
        if (count($found) !== 1) {
            throw new RuntimeException('O arquivo canônico deste ETL não foi identificado no projeto.');
        }

        return $found[0];
    }

    private function assertDraft(array $body): void
    {
        if (($body['deskId'] ?? null) !== null || ($body['_state'] ?? null) === 'published' || (! array_key_exists('deskId', $body) && ($body['_state'] ?? null) !== 'draft')) {
            throw new RuntimeException('Este assistente opera somente um rascunho privado confirmado.');
        }
    }

    private function createdRef(string $kind, array $result): ?string
    {
        $data = $result['data'] ?? [];
        foreach (is_array($data) ? [$data, $data['payload'] ?? [], $data['resource'] ?? [], $data['created'] ?? [], $data[$kind] ?? []] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['ref', 'resource_ref', 'resource', $kind.'_ref'] as $key) {
                if (is_string($item[$key] ?? null) && preg_match('/^'.preg_quote($kind, '/').':[1-9][0-9]*$/D', $item[$key])) {
                    return $item[$key];
                }
            }
            foreach (['new_id', $kind.'_id', 'id'] as $key) {
                if (is_int($item[$key] ?? null) || is_string($item[$key] ?? null)) {
                    if (preg_match('/^[1-9][0-9]*$/D', (string) $item[$key])) {
                        return $kind.':'.$item[$key];
                    }
                }
            }
        }

        return null;
    }

    private function attempt(Approval $approval, array $data): void
    {
        $approval->update(['payload' => [...$approval->payload, 'flow_wizard_attempt' => [...($approval->payload['flow_wizard_attempt'] ?? []), ...$data]]]);
    }

    private function fingerprint(int $user, Project $project, array $action, array $revision): string
    {
        return $this->sources->digest(['flow-wizard-v1', $user, $project->id, $project->tenant_id, $action, $revision]);
    }

    private function project(User $user, string $tenant, string $id): Project
    {
        return Project::where('user_id', $user->id)->where('tenant_id', $tenant)->where('status', '!=', 'archived')->findOrFail($id);
    }

    private function access(User $user, string $tenant): array
    {
        $current = User::findOrFail($user->id);
        abort_unless($current->canStartWork() && $current->role !== 'viewer', 403, 'Seu perfil não permite operar ETLs.');
        [$connection, $environment] = $this->catalog->access($current, $tenant);
        $this->policy->assertCommandAllowed($tenant, ['new', 'flow'], $connection);

        return [$connection, $environment];
    }

    private function assertCurrent(Connection $connection, Project $project, ?string $generation): void
    {
        $user = User::findOrFail($connection->user_id);
        [$current] = $this->access($user, (string) $project->tenant_id);
        abort_unless($user->access_version === $connection->operationAccessVersion && $current->id === $connection->id && ($current->metadata['session_generation'] ?? null) === $generation, 403, 'O acesso à fonte mudou. Confira uma nova revisão.');
        if ($project->exists) {
            $fresh = Project::find($project->id);
            abort_unless($fresh && $fresh->user_id === $project->user_id && $fresh->tenant_id === $project->tenant_id && $fresh->source_ref === $project->source_ref && $fresh->status !== 'archived', 409, 'O contexto do projeto mudou. Confira a operação antes de continuar.');
        }
    }
}
