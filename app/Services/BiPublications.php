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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BiPublications
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    /** @return array<string, mixed> */
    public function plan(Connection $connection, Project $project, array $arguments): array
    {
        if (str_starts_with((string) ($arguments['resource'] ?? ''), 'flow:')) {
            return app(BiFlowPublications::class)->plan($connection, $project, $arguments);
        }
        $generation = $connection->metadata['session_generation'] ?? null;
        $ref = $arguments['resource'] ?? '';
        $mode = $arguments['publish_mode'] ?? 'new';
        $desk = $arguments['desk'] ?? null;
        if (! preg_match('/^app:[1-9][0-9]*$/D', $ref) || ! is_int($desk) || $desk < 1 || ! in_array($mode, ['new', 'replace'], true)) {
            throw new RuntimeException('Escolha a aplicação, a mesa e o modo de publicação.');
        }
        $this->policy->assertCommandAllowed((string) $project->tenant_id, ['app', 'publish', $ref], $connection);
        [$context, $apps, $desks] = $this->context($connection, $project);
        if (! $this->listed($apps, $ref)) {
            throw new RuntimeException('A aplicação não está disponível neste tenant para sua sessão.');
        }
        $destination = collect($desks)->first(fn (array $row): bool => (string) $row['id'] === (string) $desk);
        if (! $destination) {
            throw new RuntimeException('A mesa de aplicações não está disponível neste tenant. Atualize o catálogo e escolha outra mesa.');
        }
        $source = $this->body($connection, $context, $ref);
        if ($this->desk($source) !== null) {
            throw new RuntimeException('Esta aplicação já foi publicada. Confira a versão atual antes de preparar outra publicação.');
        }
        if (! is_array($source['dashboards'] ?? null) || $source['dashboards'] === []) {
            throw new RuntimeException('A aplicação precisa ter ao menos um dashboard antes de publicar.');
        }
        $target = ['resource' => $ref, 'name' => $this->name($source), 'sha256' => null];
        if ($mode === 'replace') {
            $origin = $source['cloned_from'] ?? $source['_state']['cloned_from'] ?? null;
            if (! is_numeric($origin) || (int) $origin < 1 || ! $this->listed($apps, 'app:'.$origin) || 'app:'.$origin === $ref) {
                throw new RuntimeException('Não foi possível identificar uma aplicação original autorizada para substituir. Publique como nova ou confira a origem da cópia.');
            }
            $original = $this->body($connection, $context, 'app:'.$origin);
            if ($this->desk($original) !== $desk) {
                throw new RuntimeException('A substituição exige a mesma mesa da aplicação original. Atualize o catálogo e confira o destino.');
            }
            $target = ['resource' => 'app:'.$origin, 'name' => $this->name($original), 'sha256' => $this->digest($original)];
        }
        $this->assertSession($connection, $project);
        $this->assertGeneration($connection, $generation);

        return [
            'mode' => $mode,
            'source' => ['resource' => $ref, 'name' => $this->name($source), 'dashboard_count' => count($source['dashboards']), 'sha256' => $this->digest($source), 'content_sha256' => $this->contentDigest($source, $ref, (string) $project->tenant_id)],
            'destination' => ['id' => $desk, 'resource' => 'bi-desk:'.$desk, 'name' => (string) ($destination['nome'] ?? $destination['name'] ?? 'Mesa #'.$desk)],
            'target' => $target,
            'connection_generation' => $connection->metadata['session_generation'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function verify(Connection $connection, Project $project, array $plan, bool $requireOriginalSession = true): array
    {
        if (($plan['kind'] ?? null) === 'flow') {
            return app(BiFlowPublications::class)->verify($connection, $project, $plan, $requireOriginalSession);
        }
        $generation = $connection->metadata['session_generation'] ?? null;
        [$context, $apps] = $this->context($connection, $project);
        $ref = $plan['target']['resource'] ?? '';
        if (! $this->listed($apps, $ref)) {
            throw new RuntimeException('A aplicação publicada ainda não apareceu na listagem autorizada.');
        }
        if (($plan['mode'] ?? '') === 'replace' && $this->listed($apps, $plan['source']['resource'] ?? '')) {
            throw new RuntimeException('O BI ainda mantém a cópia separada; a substituição não foi confirmada.');
        }
        $body = $this->body($connection, $context, $ref);
        if ($this->desk($body) !== ($plan['destination']['id'] ?? null) || $this->name($body) !== ($plan['source']['name'] ?? null) || ! is_array($body['dashboards'] ?? null) || count($body['dashboards']) !== ($plan['source']['dashboard_count'] ?? null)) {
            throw new RuntimeException('O destino ou o conteúdo resumido retornado pelo BI não corresponde à revisão autorizada.');
        }
        $contentVerified = array_key_exists('content_sha256', $plan['source']);
        if ($contentVerified && (! is_string($plan['source']['content_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $plan['source']['content_sha256']) || ! $this->matchesContentDigest($body, $ref, (string) $project->tenant_id, $plan['source']['content_sha256']))) {
            throw new RuntimeException('O conteúdo da aplicação publicada não corresponde à revisão autorizada. Confira gráficos, expressões, filtros e fontes sem reenviar a publicação.');
        }
        $this->assertSession($connection, $project);
        $this->assertGeneration($connection, $generation);
        if ($requireOriginalSession && ($connection->metadata['session_generation'] ?? null) !== ($plan['connection_generation'] ?? null)) {
            throw new RuntimeException('A sessão mudou durante a confirmação da publicação.');
        }

        return ['verified' => true, 'content_verified' => $contentVerified, 'mode' => $plan['mode'], 'resource' => $ref, 'name' => $this->name($body), 'dashboard_count' => count($body['dashboards']), 'destination' => $plan['destination'], 'message' => $contentVerified ? 'Conteúdo revisado conferido no BI. A atualização dos dados é verificada separadamente.' : 'Destino e quantidade de dashboards conferidos. Esta revisão anterior não inclui a conferência integral do conteúdo.', 'checked_at' => now()->toIso8601String()];
    }

    /** @return array{project: Project, approval: Approval} */
    public function reconcile(User $user, string $id): array
    {
        return $this->reconcileScoped($user, $id);
    }

    /** Called with the project lock held by RunAgentTurn.
     * @return array{project: Project, approval: Approval}
     */
    public function reconcileForAgent(AgentRun $run, string $id): array
    {
        $run->refresh();
        $project = $run->conversation->project;
        abort_unless($run->status === 'running' && $project->user_id === $run->user_id, 409, 'A tarefa não está disponível para esta conferência.');

        return $this->reconcileScoped(User::findOrFail($run->user_id), $id, $run);
    }

    /** @return array{project: Project, approval: Approval} */
    private function reconcileScoped(User $user, string $id, ?AgentRun $run = null): array
    {
        abort_if($user->role === 'viewer', 403, 'Seu perfil permite somente leitura.');
        $approval = Approval::where('user_id', $user->id)->whereIn('type', ['manual', 'publish'])->when($run, fn ($query) => $query->where('project_id', $run->conversation->project_id))->findOrFail($id);
        try {
            $operation = function () use ($user, $approval, $run): array {
                $approval->refresh();
                $project = Project::where('user_id', $user->id)->findOrFail($approval->project_id);
                if ($run) {
                    abort_unless($run->fresh()?->status === 'running' && $project->tenant_id === $run->conversation->project->tenant_id, 409, 'A tarefa ou o ambiente mudou durante a conferência.');
                }
                $arguments = $approval->payload['action'] ?? [];
                $revision = $approval->payload['revision'] ?? [];
                $plan = $revision['publication'] ?? null;
                abort_unless(in_array($arguments['action'] ?? '', ['publish_app', 'publish_flow'], true) && is_array($plan) && ($plan['source']['resource'] ?? null) === ($arguments['resource'] ?? null) && (($arguments['action'] === 'publish_flow') === (($plan['kind'] ?? null) === 'flow')) && $approval->decided_at !== null, 422, 'Esta revisão não contém uma publicação autorizada para conferir.');
                abort_unless(in_array($approval->status, ['failed', 'completed'], true), 409, 'Aguarde a execução terminar antes de conferir o resultado.');
                $fingerprint = hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR));
                abort_unless(hash_equals($approval->fingerprint, $fingerprint), 409, 'A revisão ou o contexto mudou. A conferência não pode usar estes dados.');
                $attempt = $approval->payload['publication_attempt'] ?? [];
                abort_unless(hash_equals(hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), (string) ($attempt['plan_sha256'] ?? '')) && ! empty($attempt['started_at']), 422, 'Não há registro de envio desta publicação. Confira a mensagem da operação antes de preparar outra revisão.');
                if ($approval->status === 'completed' && ($approval->payload['execution']['result']['publication']['verified'] ?? false) === true) {
                    return ['project' => $project, 'approval' => $approval];
                }
                [$connection] = $this->catalog->access($user, (string) $project->tenant_id);
                $confirmation = $this->verify($connection, $project, $plan, requireOriginalSession: false);
                DB::transaction(function () use ($approval, $project, $confirmation, $arguments, $fingerprint, $run): void {
                    $currentProject = Project::where('user_id', $approval->user_id)->lockForUpdate()->findOrFail($project->id);
                    $current = Approval::where('user_id', $approval->user_id)->lockForUpdate()->findOrFail($approval->id);
                    abort_unless(($run === null || $run->fresh()?->status === 'running') && $currentProject->tenant_id === $project->tenant_id && $current->status === $approval->status && hash_equals($fingerprint, $current->fingerprint) && $current->payload === $approval->payload, 409, 'O contexto mudou durante a conferência. Atualize a operação e confira novamente.');
                    $this->recordVerified($currentProject, $arguments['resource'], $confirmation);
                    $previous = $current->payload['execution'] ?? [];
                    $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => ['publication' => $confirmation, 'published_resource' => $confirmation['resource'], 'confirmed_name' => $confirmation['name']]], 'reconciliation' => ['checked_at' => $confirmation['checked_at'], 'previous_status' => $approval->status, 'previous_message' => $previous['message'] ?? null, 'remote_read_only' => true]]]);
                    app(ProjectPreviews::class)->recordCompletedChange($current);
                    Audit::record($approval->user_id, 'bi.publication.reconciled', 'Publicação confirmada por nova consulta, sem reenviar a operação.', $project->id, ['approval_id' => $approval->id, 'resource' => $confirmation['resource'], 'tenant_id' => $project->tenant_id]);
                });
                $this->catalog->invalidate((string) $project->tenant_id, $connection);

                return ['project' => $project->refresh(), 'approval' => $approval->refresh()];
            };
            $result = $run ? $operation() : Cache::lock('project:'.$approval->project_id, 630)->block(3, $operation);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['publication' => 'Outra tarefa deste projeto está em andamento. Aguarde para conferir a publicação.']);
        }
        try {
            event(new WorkspaceChanged($user->id));
        } catch (Throwable) {
        }

        return $result;
    }

    public function recordVerified(Project $project, string $source, array $confirmation): void
    {
        DB::transaction(function () use ($project, $source, $confirmation): void {
            $current = Project::where('user_id', $project->user_id)->lockForUpdate()->findOrFail($project->id);
            abort_unless($current->tenant_id === $project->tenant_id, 409, 'O tenant do projeto mudou durante a confirmação.');
            $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $source)->lockForUpdate()->first();
            if ($managed) {
                $managed->update(['status' => 'published', 'receipt' => [...($managed->receipt ?? []), 'publication' => $confirmation]]);
            }
            if ($current->source_ref === $source) {
                $current->update(['source_ref' => $confirmation['resource']]);
                $project->source_ref = $confirmation['resource'];
            }
        });
    }

    /** @return array{Project, array, array} */
    private function context(Connection $connection, Project $project): array
    {
        $user = $this->assertSession($connection, $project);
        [$current, $tenant] = $this->catalog->access($user, (string) $project->tenant_id);
        if ($current->id !== $connection->id || ($current->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A conexão mudou. Prepare uma nova revisão de publicação.');
        }
        $context = $this->catalog->context($connection, $tenant);
        $this->lumo->initialize($connection, $context, true);

        return [$context, $this->catalog->rows($connection, $context, 'app'), $this->catalog->rows($connection, $context, 'bi-desk')];
    }

    private function assertSession(Connection $connection, Project $project): User
    {
        $user = User::find($project->user_id);
        $current = $connection->fresh();
        if (! $user || $user->role === 'viewer' || $connection->user_id !== $project->user_id || ! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A permissão ou a sessão mudou. Confira seus acessos antes de publicar.');
        }

        return $user;
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        if (($connection->fresh()?->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A sessão mudou durante a consulta de publicação. Confira seus acessos e tente a conferência novamente.');
        }
    }

    private function listed(array $rows, string $ref): bool
    {
        return (bool) preg_match('/^app:([1-9][0-9]*)$/D', $ref, $match) && collect($rows)->contains(fn (array $row): bool => (string) $row['id'] === $match[1]);
    }

    /** @return array<string, mixed> */
    private function body(Connection $connection, Project $context, string $ref): array
    {
        $this->lumo->run($connection, ['fetch', $ref], $context, 90);
        $data = $this->lumo->run($connection, ['info', $ref, '--full'], $context, 60)['data'];
        $payload = is_array($data) ? ($data['payload'] ?? $data) : [];
        $body = $payload['body'] ?? null;
        if (! is_array($body) || (isset($payload['header']['tenantId']) && (string) $payload['header']['tenantId'] !== (string) $context->tenant_id) || (isset($payload['header']['id']) && (string) $payload['header']['id'] !== substr($ref, 4))) {
            throw new RuntimeException('A aplicação retornou fora do contexto de publicação autorizado.');
        }

        return $body;
    }

    private function desk(array $body): ?int
    {
        $known = false;
        $values = [];
        foreach ([$body, is_array($body['_state'] ?? null) ? $body['_state'] : []] as $metadata) {
            foreach (['deskId', 'desk_id'] as $key) {
                if (array_key_exists($key, $metadata)) {
                    $known = true;
                    $value = $metadata[$key];
                    if ($value !== null && (! is_numeric($value) || (int) $value < 1)) {
                        throw new RuntimeException('A mesa atual da aplicação não pôde ser confirmada.');
                    }
                    $values[] = $value === null ? null : (int) $value;
                }
            }
        }
        if (! $known || count(array_unique($values, SORT_REGULAR)) !== 1) {
            throw new RuntimeException('O estado atual da aplicação não pôde ser confirmado.');
        }

        return $values[0];
    }

    private function name(array $body): string
    {
        $name = $body['nome'] ?? $body['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('O nome da aplicação não foi confirmado pelo BI.');
        }

        return $name;
    }

    /** Native publication omits an empty reports field or returns null/[]; preserve existing review hashes. */
    private function matchesContentDigest(array $body, string $resource, string $tenant, string $expected): bool
    {
        if (hash_equals($expected, $this->contentDigest($body, $resource, $tenant))) {
            return true;
        }
        if (array_key_exists('reports', $body) && ! in_array($body['reports'], [null, []], true)) {
            return false;
        }
        foreach ([null, []] as $empty) {
            $body['reports'] = $empty;
            if (hash_equals($expected, $this->contentDigest($body, $resource, $tenant))) {
                return true;
            }
        }
        unset($body['reports']);

        return hash_equals($expected, $this->contentDigest($body, $resource, $tenant));
    }

    private function contentDigest(array $body, string $resource, string $tenant): string
    {
        foreach (['_state', 'deskId', 'desk_id', 'version', 'criado_em', 'criado_por', 'publicado_em', 'publicado_por', 'cloned_from'] as $field) {
            unset($body[$field]);
        }
        foreach ($body['dashboards'] as $index => $dashboard) {
            if (! is_array($dashboard)) {
                throw new RuntimeException('A configuração completa de um dashboard não foi recebida.');
            }
            if (array_key_exists('header', $dashboard) || array_key_exists('body', $dashboard)) {
                $header = $dashboard['header'] ?? null;
                if (! is_array($header) || ! is_array($dashboard['body'] ?? null) || ($header['kind'] ?? null) !== 'app-dashboard' || ($header['lumo'] ?? null) !== 'v2' || ! is_int($header['id'] ?? null) || $header['id'] < 1 || ! is_int($header['tenantId'] ?? null) || (string) $header['tenantId'] !== $tenant || ($header['appId'] ?? null) !== (int) substr($resource, 4)) {
                    throw new RuntimeException('O dashboard retornou fora do contexto da aplicação revisada.');
                }
                unset($dashboard['header']['appId']);
                foreach (['version', 'criado_em', 'criado_por', 'publicado_em', 'publicado_por'] as $field) {
                    unset($dashboard['body'][$field]);
                }
                $body['dashboards'][$index] = $dashboard;
            }
        }

        return $this->digest($body);
    }

    private function digest(array $body): string
    {
        $sort = function (array $items) use (&$sort): array {
            if (! array_is_list($items)) {
                ksort($items);
            }
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $items[$key] = $sort($value);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode($sort($body), JSON_THROW_ON_ERROR));
    }
}
