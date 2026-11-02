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
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class BiAppCreation
{
    public const ACTIONS = ['create_app'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    public function prepare(User $user, string $tenant, array $input): array
    {
        $this->assertAuthor($user);
        $data = $this->normalize($input);
        [$connection, $environment] = $this->catalog->access($user, $tenant);
        $this->policy->assertCommandAllowed($tenant, $this->command($data), $connection);
        $requestHash = $this->digest([$tenant, $data]);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $connection, $environment, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outros dados. Abra uma nova revisão.');

                    return ['approval' => $existing, 'project' => Project::where('user_id', $user->id)->findOrFail($existing->project_id), 'reused' => true];
                }
                $generation = $connection->metadata['session_generation'] ?? null;
                $context = $this->catalog->context($connection, $environment);
                $this->lumo->initialize($connection, $context, true);
                $tables = $this->tables($connection, $context, $data['tables']);
                $this->catalog->rows($connection, $context, 'app');
                $this->assertSession($connection, $generation);
                $revision = ['tenant_id' => $tenant, 'connection_generation' => $generation, 'app_creation' => ['name' => $data['name'], 'objective' => $data['objective'], 'tables' => $tables, 'state' => 'draft'], 'impact' => 'Cria uma nova aplicação privada em Minha Mesa e um projeto para continuar a autoria. Vincula somente as tabelas escolhidas. A criação não inclui gráficos, não executa cargas, não cria agendas e não publica recursos.'];
                $result = DB::transaction(function () use ($user, $tenant, $environment, $connection, $generation, $data, $revision, $requestHash): array {
                    $this->assertSession($connection, $generation);
                    $project = Project::create(['user_id' => $user->id, 'tenant_id' => $tenant, 'tenant_name' => $environment['name'], 'name' => $data['name'], 'description' => $data['objective'], 'instructions' => 'A aplicação está sendo criada pelo catálogo. Confira o resultado da revisão de criação antes de propor outra criação. Depois, use a aplicação vinculada para montar os dashboards conforme o objetivo do projeto.']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => null, 'type' => 'manual', 'title' => 'Criar aplicação no G-Analise BI', 'description' => 'Confira o ambiente, o nome e as tabelas desta nova aplicação privada.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $project->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.app_creation.review_created', 'Nova aplicação preparada para revisão.', $project->id, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'table_count' => count($data['tables'])]);

                    return ['approval' => $approval->fresh(), 'project' => $project->fresh(), 'reused' => false];
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Esta solicitação ainda está sendo preparada. Aguarde e consulte a mesma revisão.');
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        [$project, $data, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || isset($approval->payload['app_creation_attempt'])) {
            throw new RuntimeException('Esta revisão não permite outra criação. Confira o resultado antes de repetir.');
        }
        $this->assertProject($project);
        [$connection, $environment] = $this->catalog->access($user, $tenant);
        $this->assertSession($connection, $revision['connection_generation']);
        $this->policy->assertCommandAllowed($tenant, $this->command($data), $connection);
        $this->lumo->initialize($connection, $project);
        if ($this->tables($connection, $project, $data['tables']) !== $revision['app_creation']['tables']) {
            throw new RuntimeException('As tabelas mudaram após a revisão. Confira a seleção antes de criar.');
        }
        $before = array_map(fn (array $row): string => 'app:'.$row['id'], $this->catalog->rows($connection, $project, 'app'));
        $this->assertSession($connection, $revision['connection_generation']);
        $this->assertProject($project->fresh());
        $approval->refresh();
        if ($approval->status !== 'executing' || ! $approval->permitsUserAccess($user->fresh()) || $approval->expires_at->isPast()) {
            throw new RuntimeException('O acesso ou a autorização mudou antes da criação.');
        }
        $approval->update(['payload' => [...$approval->payload, 'app_creation_attempt' => ['started_at' => now()->toIso8601String()]]]);
        try {
            $response = $this->lumo->run($connection, $this->command($data), $project, 90);
            $ref = $this->createdReference($response['data'] ?? null);
            if ($ref === null || in_array($ref, $before, true)) {
                throw new RuntimeException('O BI não identificou com segurança uma nova aplicação. Não repita a criação; confira o catálogo e esta operação.');
            }
            $approval->update(['payload' => [...$approval->payload, 'app_creation_attempt' => [...$approval->payload['app_creation_attempt'], 'resource' => $ref]]]);
            $result = $this->confirm($connection, $project, $data, $ref);
            $this->assertSession($connection, $revision['connection_generation']);
            $this->bind($user, $project, $approval, $result);
            Audit::record($user->id, 'bi.app_creation.completed', 'Nova aplicação privada criada e conferida.', $project->id, ['approval_id' => $approval->id, 'resource' => $ref, 'tenant_id' => $tenant]);

            return $result;
        } catch (Throwable $e) {
            throw new RuntimeException('A criação foi enviada, mas o resultado precisa de conferência. Não crie outra aplicação para substituir esta tentativa. Use Conferir criação ou consulte o catálogo.', previous: $e);
        } finally {
            $this->catalog->invalidate($tenant, $connection);
        }
    }

    public function reconcile(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->findOrFail($id);
        [$project, $data, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status === 'completed') {
            return ['approval' => $approval, 'project' => $project];
        }
        abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true) && $approval->decided_at !== null && isset($approval->payload['app_creation_attempt']['started_at']), 409, 'Esta criação ainda não tem uma tentativa encerrada para conferir.');
        $ref = $approval->payload['app_creation_attempt']['resource'] ?? null;
        abort_unless(is_string($ref) && preg_match('/^app:[1-9][0-9]*$/D', $ref), 409, 'O BI não devolveu o identificador da criação. Confira o catálogo com o administrador antes de outra tentativa; o sistema não adota uma aplicação somente pelo nome.');
        try {
            return Cache::lock('project:'.$project->id, 630)->block(3, function () use ($user, $approval, $project, $data, $tenant, $ref): array {
                $approval->refresh();
                [$project] = $this->review($approval, $user->fresh());
                if ($approval->status === 'completed') {
                    return ['approval' => $approval, 'project' => $project];
                }
                abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true), 409, 'A criação mudou de estado. Consulte o resultado atual.');
                $this->assertProject($project, $ref);
                [$connection] = $this->catalog->access($user, $tenant);
                $generation = $connection->metadata['session_generation'] ?? null;
                $this->lumo->initialize($connection, $project);
                $result = $this->confirm($connection, $project, $data, $ref);
                $this->assertSession($connection, $generation);
                DB::transaction(function () use ($user, $project, $approval, $result): void {
                    $current = Approval::where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->id);
                    $this->review($current, $user->fresh());
                    abort_unless(in_array($current->status, ['failed', 'cancelled', 'expired'], true), 409, 'O estado mudou durante a conferência.');
                    $this->bind($user, $project, $current, $result);
                    $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result], 'reconciliation' => ['remote_read_only' => true, 'previous_status' => $current->status, 'checked_at' => now()->toIso8601String()]]]);
                });
                $this->catalog->invalidate($tenant, $connection);
                Audit::record($user->id, 'bi.app_creation.reconciled', 'Criação conferida por leitura, sem novo envio.', $project->id, ['approval_id' => $approval->id, 'resource' => $ref]);

                return ['approval' => $approval->fresh(), 'project' => $project->fresh()];
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('O projeto ainda está em uso. Aguarde antes de conferir a criação.');
        }
    }

    private function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['request_id', 'name', 'objective', 'tables']) !== []) {
            throw new RuntimeException('Esta criação aceita somente nome, objetivo e tabelas.');
        }
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $data = Validator::make($input, ['request_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'objective' => ['nullable', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], 'tables' => ['sometimes', 'array', 'list', 'max:8'], 'tables.*' => ['required', 'string', 'distinct', 'regex:/^table:[1-9][0-9]*$/D']])->validate();
        $tables = $data['tables'] ?? [];
        sort($tables, SORT_STRING);

        return ['action' => 'create_app', 'request_id' => strtolower($data['request_id']), 'name' => $data['name'], 'objective' => trim((string) ($data['objective'] ?? '')), 'tables' => $tables];
    }

    private function tables(Connection $connection, Project $context, array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        $listed = collect($this->catalog->rows($connection, $context, 'table'))->keyBy(fn (array $row): string => 'table:'.$row['id']);

        return array_map(function (string $ref) use ($listed, $connection, $context): array {
            abort_unless($listed->has($ref), 404, 'Uma tabela escolhida não está disponível neste ambiente para sua conta.');
            app(BiMutationGuard::class)->assertAvailable($connection->user_id, (string) $context->tenant_id, $ref);
            $row = $listed->get($ref);
            $name = $row['nome'] ?? $row['name'] ?? $ref;

            return ['resource' => $ref, 'name' => is_string($name) ? $name : $ref];
        }, $refs);
    }

    private function confirm(Connection $connection, Project $project, array $data, string $ref): array
    {
        $native = $this->lumo->run($connection, ['info', $ref, '--full'], $project, 60)['data'] ?? null;
        $payload = is_array($native) ? ($native['payload'] ?? $native) : null;
        $header = $payload['header'] ?? null;
        $body = $payload['body'] ?? null;
        if (! is_array($header) || ! is_array($body) || ($header['kind'] ?? null) !== 'app' || ! $this->positiveId($header['id'] ?? null) || $ref !== 'app:'.$header['id'] || ! $this->positiveId($header['tenantId'] ?? null) || (string) $header['tenantId'] !== (string) $project->tenant_id || ($body['nome'] ?? null) !== $data['name']) {
            throw new RuntimeException('A identidade ou o nome da nova aplicação não foi confirmado.');
        }
        $summary = $this->catalog->summary('app', ['id' => $header['id']], $body);
        $tables = [];
        foreach ($body['tables'] ?? [] as $table) {
            if (! is_array($table) || ! $this->positiveId($table['tableId'] ?? null)) {
                throw new RuntimeException('Os vínculos de tabelas da nova aplicação não foram reconhecidos.');
            }
            $tables[] = 'table:'.$table['tableId'];
        }
        sort($tables, SORT_STRING);
        if ($summary['state'] !== 'draft' || $summary['desk_id'] !== null || $tables !== $data['tables'] || ! is_array($body['dashboards'] ?? null) || count($body['dashboards']) !== 0) {
            throw new RuntimeException('A configuração privada e as tabelas da criação não correspondem à revisão.');
        }

        return ['resource' => $ref, 'managed_resource' => $ref, 'confirmed_name' => $body['nome'], 'configuration_verified' => true, 'app_creation' => ['verified' => true, 'state' => 'draft', 'table_count' => count($tables), 'dashboard_count' => 0], 'message' => 'Aplicação privada criada e conferida. Abra o projeto para montar os dashboards e conferir a atualização das fontes antes de publicar.'];
    }

    private function bind(User $user, Project $project, Approval $approval, array $result): void
    {
        DB::transaction(function () use ($user, $project, $approval, $result): void {
            $currentUser = $user->fresh();
            $this->assertAuthor($currentUser);
            if (! $approval->permitsUserAccess($currentUser)) {
                throw new RuntimeException('O acesso mudou durante a conferência da criação.');
            }
            $current = Project::where('user_id', $user->id)->lockForUpdate()->findOrFail($project->id);
            $this->assertProject($current, $result['resource']);
            $existing = ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $current->tenant_id)->where('resource', $result['resource'])->first();
            if ($existing && $existing->project_id !== $current->id) {
                throw new RuntimeException('A aplicação já está vinculada a outro projeto.');
            }
            ManagedBiResource::firstOrCreate(['user_id' => $user->id, 'tenant_id' => $current->tenant_id, 'resource' => $result['resource']], ['project_id' => $current->id, 'origin' => 'manual_app_creation', 'status' => 'draft', 'receipt' => ['approval_id' => $approval->id, 'created_at' => $approval->payload['app_creation_attempt']['started_at']]]);
            $current->update(['source_ref' => $result['resource']]);
        });
    }

    private function review(Approval $approval, User $user): array
    {
        $this->assertAuthor($user);
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->type !== 'manual' || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ($data['action'] ?? null) !== 'create_app' || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, (string) $approval->project_id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->digest([$tenant, $data]))) {
            throw new RuntimeException('Esta revisão não autoriza a criação da aplicação.');
        }
        $project = Project::where('user_id', $user->id)->where('tenant_id', $tenant)->findOrFail($approval->project_id);

        return [$project, $data, $revision, $tenant];
    }

    public function assertConversationReady(Project $project): void
    {
        $pending = Approval::where('user_id', $project->user_id)->where('project_id', $project->id)->where('payload->action->action', 'create_app')->where(function ($query): void {
            $query->whereIn('status', ['pending', 'approved', 'executing'])->orWhere(function ($attempt): void {
                $attempt->whereIn('status', ['failed', 'cancelled', 'expired'])->whereNotNull('payload->app_creation_attempt->started_at');
            });
        })->exists();
        abort_if($pending, 409, 'A criação da aplicação ainda precisa de decisão ou conferência. Resolva esta operação no catálogo ou em Atividade antes de enviar outro pedido à IA.');
    }

    private function assertAuthor(User $user): void
    {
        abort_unless($user->canStartWork() && $user->role !== 'viewer', 403, 'Seu perfil não permite criar aplicações.');
    }

    private function assertProject(Project $project, ?string $ref = null): void
    {
        if ($project->status === 'archived' || ($project->source_ref !== null && $project->source_ref !== $ref) || AgentRun::whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested'])->whereHas('conversation', fn ($query) => $query->where('project_id', $project->id))->exists()) {
            throw new RuntimeException('O contexto do projeto mudou ou há uma tarefa de IA ativa. Confira o projeto antes de continuar.');
        }
    }

    private function assertSession(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation || ! $user?->canStartWork() || $user->access_version !== $connection->operationAccessVersion) {
            throw new RuntimeException('A sessão ou o acesso mudou durante esta criação. Confira a conexão e a revisão.');
        }
    }

    private function command(array $data): array
    {
        $command = ['new', 'app', '--name', $data['name']];
        foreach ($data['tables'] as $ref) {
            array_push($command, '--table', $ref);
        }

        return $command;
    }

    private function createdReference(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        $refs = [];
        foreach ([$data, $data['payload'] ?? null, $data['created'] ?? null, $data['app'] ?? null, $data['resource'] ?? null] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['ref', 'resource_ref', 'resource', 'app_ref'] as $key) {
                if (is_string($item[$key] ?? null) && preg_match('/^app:[1-9][0-9]*$/D', $item[$key])) {
                    $refs[] = $item[$key];
                }
            }
            foreach (['new_id', 'app_id', 'id'] as $key) {
                if ($this->positiveId($item[$key] ?? null)) {
                    $refs[] = 'app:'.$item[$key];
                }
            }
        }
        $refs = array_values(array_unique($refs));

        return count($refs) === 1 ? $refs[0] : null;
    }

    private function positiveId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1;
    }

    private function fingerprint(int $userId, string $projectId, string $tenant, array $data, array $revision): string
    {
        return $this->digest([$userId, $projectId, $tenant, $data, $revision]);
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
