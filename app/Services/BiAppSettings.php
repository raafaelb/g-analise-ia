<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiAppSettings
{
    public const ACTIONS = ['update_app_details', 'update_app_dashboards'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    public function describe(User $user, string $tenant, string $id): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $id, $generation): array {
            $row = $this->listed($connection, $context, $id);
            [, $body, , $shape] = $this->document($connection, $context, 'app:'.$id);
            $summary = $this->catalog->summary('app', $row, $body);
            $this->assertGeneration($connection, $generation);

            return ['resource' => 'app:'.$id, 'name' => $body['nome'], 'description' => $body['descricao'] ?? null, 'version' => $this->digest($shape), 'state' => $summary['state'], 'desk_id' => $summary['desk_id']];
        });
    }

    public function describeDashboards(User $user, string $tenant, string $id): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $id, $generation): array {
            $row = $this->listed($connection, $context, $id);
            [, $body, , $shape] = $this->document($connection, $context, 'app:'.$id);
            $summary = $this->catalog->summary('app', $row, $body);
            $dashboards = $this->dashboardRows($shape, 'app:'.$id, (string) $context->tenant_id);
            $this->assertGeneration($connection, $generation);

            return ['resource' => 'app:'.$id, 'name' => $body['nome'], 'version' => $this->digest($shape), 'state' => $summary['state'], 'desk_id' => $summary['desk_id'], 'dashboards' => $dashboards];
        });
    }

    public function prepareDashboards(User $user, string $tenant, string $id, array $input): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $data = $this->normalizeDashboards('app:'.$id, $input);

        return $this->prepareUpdate($user, $tenant, $connection, $environment, $data);
    }

    public function prepare(User $user, string $tenant, string $id, array $input): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $data = $this->normalize('app:'.$id, $input);

        return $this->prepareUpdate($user, $tenant, $connection, $environment, $data);
    }

    private function prepareUpdate(User $user, string $tenant, Connection $connection, array $environment, array $data): array
    {
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        $requestHash = $this->digest([$tenant, $data]);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $connection, $environment, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outra configuração. Abra outra revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, $data['resource']);
                $revision = $this->withContext($connection, $environment, fn (Project $context): array => $this->revision($connection, $context, $data));
                $approval = DB::transaction(function () use ($user, $tenant, $connection, $data, $revision, $requestHash): Approval {
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => $data['action'] === 'update_app_dashboards' ? 'Organizar dashboards da aplicação' : 'Editar nome e descrição da aplicação', 'description' => 'Confira os valores atuais e propostos antes de salvar na aplicação existente.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.app_details.review_created', 'Alteração da aplicação preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'changed_fields' => count($revision['changes'])]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['application' => 'Esta revisão ainda está sendo preparada. Aguarde e consulte a mesma solicitação.']);
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        [$data, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || isset($approval->payload['app_settings_attempt']['started_at'])) {
            throw new RuntimeException('Esta revisão não permite uma nova execução. Confira o resultado antes de preparar outra alteração.');
        }
        [$connection, $environment] = $this->access($user, $tenant, substr($data['resource'], 4));
        $this->assertGeneration($connection, $revision['connection_generation']);
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        try {
            return Cache::lock('bi-app-details-write:'.$tenant.':'.$data['resource'], 630)->block(3, function () use ($connection, $environment, $user, $tenant, $data, $revision, $approval): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($connection, $environment, $user, $tenant, $data, $revision, $approval): array {
                    if ($this->revision($connection, $context, $data, false) !== $revision) {
                        throw new RuntimeException('A aplicação mudou após a revisão. Reabra o formulário e confira a versão atual.');
                    }
                    [$path, , $header, $shape] = $this->workingDocument($context, $data['resource']);
                    $this->write($path, $header, $this->apply($shape, $data));
                    $this->validate($connection, $context, $data['resource']);
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, $data['resource']);
                    $this->assertExecution($approval, $user);
                    $approval->update(['payload' => [...$approval->payload, 'app_settings_attempt' => ['started_at' => now()->toIso8601String(), 'resource' => $data['resource']]]]);
                    try {
                        $this->lumo->run($connection, ['push', $data['resource']], $context, 90);
                        $result = $this->withContext($connection, $environment, fn (Project $verification): array => $this->verify($connection, $verification, $data, $revision));
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        Audit::record($user->id, 'bi.app_details.configured', 'Alteração salva e conferida na aplicação existente.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource']]);

                        return $result;
                    } catch (Throwable $e) {
                        Audit::record($user->id, 'bi.app_details.confirmation_pending', 'O resultado da edição precisa de conferência antes de outra tentativa.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'exception_type' => get_class($e), 'exception_code' => (string) $e->getCode()]);
                        $reason = $e instanceof RuntimeException && $e->getCode() === 6 ? 'O BI não permitiu concluir a alteração com o acesso atual. Confira sua conexão e as permissões. ' : 'Não foi possível confirmar esta alteração. ';
                        throw new RuntimeException($reason.'Use Conferir resultado para consultar a aplicação, sem reenviar a edição.');
                    } finally {
                        $this->catalog->invalidate($tenant, $connection);
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['application' => 'Outra edição desta aplicação está em andamento. Confira seu resultado antes de continuar.']);
        }
    }

    public function reconcile(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->findOrFail($id);
        [$data, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status === 'completed') {
            return ['approval' => $approval, 'project' => null];
        }
        abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true) && isset($approval->payload['app_settings_attempt']['started_at']) && $approval->decided_at !== null, 409, 'Esta alteração ainda não tem uma tentativa encerrada para conferir.');
        [$connection, $environment] = $this->access($user, $tenant, substr($data['resource'], 4));
        $generation = $connection->metadata['session_generation'] ?? null;
        try {
            return Cache::lock('bi-operation:'.$approval->id, 630)->block(3, function () use ($approval, $user, $connection, $environment, $generation, $tenant, $data, $revision): array {
                $approval->refresh();
                if ($approval->status === 'completed') {
                    return ['approval' => $approval, 'project' => null];
                }
                abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true), 409, 'A operação mudou durante a conferência. Consulte seu estado atual.');
                $result = $this->withContext($connection, $environment, fn (Project $context): array => $this->verify($connection, $context, $data, $revision));
                $this->assertGeneration($connection, $generation);
                DB::transaction(function () use ($approval, $user, $result): void {
                    $current = Approval::where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->id);
                    $this->review($current, $user);
                    abort_unless(in_array($current->status, ['failed', 'cancelled', 'expired'], true), 409, 'O estado desta operação mudou. Consulte o resultado atual.');
                    $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result], 'reconciliation' => ['remote_read_only' => true, 'previous_status' => $current->status, 'checked_at' => now()->toIso8601String()]]]);
                });
                $this->catalog->invalidate($tenant, $connection);
                Audit::record($user->id, 'bi.app_details.reconciled', 'Edição da aplicação conferida por leitura, sem novo envio.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource']]);

                return ['approval' => $approval->fresh(), 'project' => null];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['application' => 'A operação ainda está em uso. Aguarde antes de conferir novamente.']);
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'app-details-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function access(User $user, string $tenant, string $id): array
    {
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite editar aplicações.');
        abort_unless(ctype_digit($id) && (int) $id > 0, 422, 'Aplicação inválida.');
        app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, 'app:'.$id);

        return $this->catalog->access($user, $tenant);
    }

    private function normalize(string $resource, array $input): array
    {
        if (array_diff(array_keys($input), ['request_id', 'version', 'name', 'description']) !== []) {
            throw ValidationException::withMessages(['application' => 'Esta edição aceita somente nome e descrição da aplicação.']);
        }
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        $validated = Validator::make($input, ['request_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'name' => ['sometimes', 'required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'description' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/']])->validate();
        $changes = [];
        if (array_key_exists('name', $validated)) {
            $changes['nome'] = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $description = trim((string) ($validated['description'] ?? ''));
            // O BI representa a descrição removida como texto vazio.
            $changes['descricao'] = $description;
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['application' => 'Altere o nome ou a descrição antes de conferir.']);
        }

        return ['action' => self::ACTIONS[0], 'resource' => $resource, 'source_version' => $validated['version'], 'app_changes' => $changes, 'request_id' => strtolower($validated['request_id'])];
    }

    private function normalizeDashboards(string $resource, array $input): array
    {
        if (array_diff(array_keys($input), ['request_id', 'version', 'dashboards']) !== []) {
            throw ValidationException::withMessages(['application' => 'Esta edição aceita somente nomes e ordem dos dashboards existentes.']);
        }
        $validated = Validator::make($input, ['request_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'dashboards' => ['required', 'array', 'list', 'min:1', 'max:100'], 'dashboards.*' => ['required', 'array:id,name'], 'dashboards.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'dashboards.*.name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();
        $rows = array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => trim($row['name'])], $validated['dashboards']);
        if (collect($rows)->contains(fn (array $row): bool => $row['name'] === '')) {
            throw ValidationException::withMessages(['application' => 'Informe um nome para cada dashboard.']);
        }

        return ['action' => 'update_app_dashboards', 'resource' => $resource, 'source_version' => $validated['version'], 'dashboard_changes' => $rows, 'request_id' => strtolower($validated['request_id'])];
    }

    /** Only canonical inline dashboards with a confirmed identity can be organized. */
    private function dashboardRows(stdClass $shape, string $resource, string $tenant): array
    {
        if (! is_array($shape->dashboards ?? null) || ! array_is_list($shape->dashboards) || count($shape->dashboards) > 100) {
            throw new RuntimeException('A lista completa de dashboards não pôde ser confirmada. Os painéis serão preservados.');
        }
        $rows = [];
        foreach ($shape->dashboards as $dashboard) {
            $header = $dashboard->header ?? null;
            $body = $dashboard->body ?? null;
            if (! $header instanceof stdClass || ! $body instanceof stdClass || ($header->kind ?? null) !== 'app-dashboard' || ($header->lumo ?? null) !== 'v2' || ! is_int($header->id ?? null) || $header->id < 1 || ! is_int($header->tenantId ?? null) || (string) $header->tenantId !== $tenant || ! is_int($header->appId ?? null) || (string) $header->appId !== substr($resource, 4) || ! is_string($body->nome ?? null) || ! is_int($body->ordem ?? null) || ! is_array($body->widgets ?? null) || ! array_is_list($body->widgets)) {
                throw new RuntimeException('A identidade ou a configuração de um dashboard não foi confirmada nesta aplicação.');
            }
            $rows[] = ['id' => $header->id, 'name' => $body->nome, 'order' => $body->ordem, 'widget_count' => count($body->widgets)];
        }
        if (count(array_unique(array_column($rows, 'id'))) !== count($rows)) {
            throw new RuntimeException('A lista recebida contém identidades repetidas de dashboards.');
        }
        usort($rows, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $rows;
    }

    private function revision(Connection $connection, Project $context, array $data, bool $validate = true): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $row = $this->listed($connection, $context, substr($data['resource'], 4));
        [$path, $body, $header, $shape] = $this->document($connection, $context, $data['resource']);
        abort_unless(hash_equals($data['source_version'], $this->digest($shape)), 409, 'Esta aplicação mudou. Reabra o formulário para conferir a versão atual.');
        $summary = $this->catalog->summary('app', $row, $body);
        if (! $summary['desk_known'] || ! in_array($summary['state'], ['draft', 'published'], true)) {
            throw new RuntimeException('Não foi possível confirmar se a aplicação está privada ou publicada. Confira o acesso antes de editar.');
        }
        $dashboardEdit = $data['action'] === 'update_app_dashboards';
        $dashboardReview = null;
        if ($dashboardEdit) {
            abort_unless($summary['state'] === 'draft' && $summary['desk_id'] === null, 409, 'Esta aplicação está publicada. Duplique para sua mesa antes de organizar seus dashboards.');
            $before = $this->dashboardRows($shape, $data['resource'], (string) $context->tenant_id);
            $ids = array_column($before, 'id');
            $proposedIds = array_column($data['dashboard_changes'], 'id');
            sort($ids);
            sort($proposedIds);
            abort_unless($ids === $proposedIds, 409, 'Mantenha todos os dashboards existentes, uma única vez. Esta edição não adiciona nem exclui painéis.');
            $dashboardReview = ['before' => $before, 'after' => $data['dashboard_changes']];
        }
        $updated = $this->apply($shape, $data);
        $changes = [];
        foreach ($data['app_changes'] ?? [] as $field => $value) {
            if (($body[$field] ?? null) !== $value) {
                $changes[] = ['target' => 'Aplicação', 'field' => $field === 'nome' ? 'Nome' : 'Descrição', 'before' => $body[$field] ?? null, 'after' => $value];
            }
        }
        if ($dashboardEdit) {
            $beforeById = array_column($dashboardReview['before'], null, 'id');
            $currentOrder = array_column($dashboardReview['before'], 'id');
            foreach ($data['dashboard_changes'] as $index => $row) {
                $original = $beforeById[$row['id']];
                if ($original['name'] !== $row['name']) {
                    $changes[] = ['target' => $original['name'], 'field' => 'Nome do dashboard', 'before' => $original['name'], 'after' => $row['name']];
                }
                $position = array_search($row['id'], $currentOrder, true);
                if ($position !== $index) {
                    $changes[] = ['target' => $row['name'], 'field' => 'Posição', 'before' => $position + 1, 'after' => $index + 1];
                }
            }
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['application' => $dashboardEdit ? 'Altere um nome ou a ordem dos dashboards antes de conferir.' : 'Altere o nome ou a descrição antes de conferir.']);
        }
        if ($validate) {
            try {
                $this->write($path, $header, $updated);
                $this->validate($connection, $context, $data['resource']);
            } finally {
                $this->write($path, $header, $shape);
            }
        }
        $this->assertGeneration($connection, $generation);

        return array_filter(['tenant_id' => (string) $context->tenant_id, 'resource' => $data['resource'], 'connection_generation' => $generation, 'source_digest' => $this->digest($shape), 'expected_digest' => $this->digest($updated), 'app_details' => ['name' => $body['nome'], 'state' => $summary['state'], 'desk_id' => $summary['desk_id']], 'dashboard_edit' => $dashboardReview, 'changes' => $changes, 'impact' => $dashboardEdit ? 'Somente os nomes e a ordem dos dashboards serão alterados neste rascunho privado. Todos os painéis, gráficos, filtros, fontes e agendas serão preservados. Esta operação não publica a aplicação.' : ($summary['state'] === 'published' ? 'O nome e a descrição serão alterados na aplicação já publicada, visível aos usuários da mesma mesa. Os dashboards, filtros, fontes e agendas serão preservados. Esta operação não publica outra versão nem altera os acessos.' : 'O nome e a descrição serão alterados neste rascunho. Os dashboards, filtros, fontes e agendas serão preservados. A aplicação continua privada; publicação tem revisão própria.')], fn (mixed $value, string $key): bool => $key !== 'dashboard_edit' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function verify(Connection $connection, Project $context, array $data, array $revision): array
    {
        $row = $this->listed($connection, $context, substr($data['resource'], 4));
        [, $body, , $shape] = $this->document($connection, $context, $data['resource']);
        $summary = $this->catalog->summary('app', $row, $body);
        $difference = $this->lumo->run($connection, ['diff', $data['resource'], '--include-remote'], $context, 60)['data'] ?? null;
        $comparison = $shape;
        $orderVerified = true;
        if ($data['action'] === 'update_app_dashboards') {
            $desired = array_column($data['dashboard_changes'], 'id');
            $rows = $this->dashboardRows($shape, $data['resource'], (string) $context->tenant_id);
            $received = array_map(fn (stdClass $dashboard): int => $dashboard->header->id, $shape->dashboards);
            $orderVerified = $received === $desired && array_column($rows, 'id') === $desired;
            // The native read projection can zero ordem; its ordered ID sequence must still match.
            $comparison = clone $shape;
            $comparison->dashboards = [];
            foreach ($shape->dashboards as $index => $original) {
                $dashboard = clone $original;
                $dashboard->body = clone $dashboard->body;
                $dashboard->body->ordem = $index;
                $comparison->dashboards[] = $dashboard;
            }
        }
        if (! $orderVerified || ! hash_equals($revision['expected_digest'], $this->digest($comparison)) || $summary['state'] !== $revision['app_details']['state'] || $summary['desk_id'] !== $revision['app_details']['desk_id'] || ! is_array($difference) || ($difference['workingMissing'] ?? null) !== false || ($difference['workingVsState']['equal'] ?? null) !== true || ($difference['workingVsServer']['equal'] ?? null) !== true) {
            throw new RuntimeException('A configuração revisada não foi confirmada integralmente. Confira a aplicação atual antes de outra alteração.');
        }

        return ['resource' => $data['resource'], 'confirmed_name' => $body['nome'], 'confirmed_description' => $body['descricao'] ?? null, 'changed_fields' => count($revision['changes']), 'configuration_verified' => true, 'dashboards_verified' => $data['action'] === 'update_app_dashboards', 'message' => $data['action'] === 'update_app_dashboards' ? 'Nomes e ordem dos dashboards salvos e conferidos no G-Analise BI.' : 'Nome e descrição salvos e conferidos no G-Analise BI.'];
    }

    private function review(Approval $approval, User $user): array
    {
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($data['action'] ?? '', self::ACTIONS, true) || ! preg_match('/^app:[1-9][0-9]*$/D', (string) ($data['resource'] ?? '')) || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->digest([$tenant, $data]))) {
            throw new RuntimeException('Esta revisão não corresponde à alteração autorizada da aplicação.');
        }

        return [$data, $revision, $tenant];
    }

    private function listed(Connection $connection, Project $context, string $id): array
    {
        $row = collect($this->catalog->rows($connection, $context, 'app'))->first(fn (array $item): bool => (string) $item['id'] === $id);
        abort_unless($row !== null, 404, 'Esta aplicação não está disponível neste tenant para sua sessão.');

        return $row;
    }

    private function document(Connection $connection, Project $context, string $resource): array
    {
        $this->lumo->run($connection, ['pull', $resource], $context, 90);

        return $this->workingDocument($context, $resource);
    }

    private function workingDocument(Project $context, string $resource): array
    {
        $folder = app(WorkspaceFiles::class)->path($context).'/apps';
        $documents = [];
        foreach (is_dir($folder) && ! is_link($folder) ? File::files($folder) : [] as $file) {
            if ($file->isLink() || $file->getSize() > 2000000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                continue;
            }
            $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $header = Yaml::parse($parts[0]);
            $body = Yaml::parse($parts[1]);
            $shape = Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
            if (is_array($header) && is_array($body) && $shape instanceof stdClass && ($header['kind'] ?? '') === 'app' && (is_int($header['tenantId'] ?? null) || is_string($header['tenantId'] ?? null)) && (is_int($header['id'] ?? null) || is_string($header['id'] ?? null)) && ctype_digit((string) $header['id']) && (int) $header['id'] > 0 && (string) ($header['tenantId'] ?? '') === (string) $context->tenant_id && $resource === 'app:'.($header['id'] ?? '') && is_string($body['nome'] ?? null) && (! isset($body['descricao']) || is_string($body['descricao']))) {
                $documents[] = [$file->getPathname(), $body, $header, $shape];
            }
        }
        if (count($documents) !== 1) {
            throw new RuntimeException('Não foi possível identificar uma configuração canônica única desta aplicação e tenant.');
        }

        return $documents[0];
    }

    private function write(string $path, array $header, stdClass $body): void
    {
        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($body, 30, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE), 0600);
    }

    private function apply(stdClass $source, array $data): stdClass
    {
        $updated = clone $source;
        foreach ($data['app_changes'] ?? [] as $field => $value) {
            $updated->{$field} = $value;
        }
        if ($data['action'] === 'update_app_dashboards') {
            $byId = [];
            foreach ($source->dashboards as $dashboard) {
                $byId[$dashboard->header->id] = $dashboard;
            }
            $updated->dashboards = [];
            foreach ($data['dashboard_changes'] as $index => $row) {
                $dashboard = clone $byId[$row['id']];
                $dashboard->body = clone $dashboard->body;
                $dashboard->body->nome = $row['name'];
                $dashboard->body->ordem = $index;
                $updated->dashboards[] = $dashboard;
            }
        }

        return $updated;
    }

    private function validate(Connection $connection, Project $context, string $resource): void
    {
        if (! app(BiResourceValidation::class)->inspect($connection, $context, $resource)['passed']) {
            throw new RuntimeException('A configuração da aplicação não passou na validação. Confira o recurso antes de salvar nome e descrição.');
        }
    }

    private function assertExecution(Approval $approval, User $user): void
    {
        $approval->refresh();
        $this->review($approval, $user);
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || isset($approval->payload['app_settings_attempt']['started_at'])) {
            throw new RuntimeException('A autorização mudou ou expirou durante a conferência. Prepare outra revisão antes de salvar.');
        }
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou durante a operação. Confira o resultado antes de continuar.');
        }
    }

    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Nome e descrição da aplicação']);
        $context->id = $approval ? 'app-details-operation-'.$approval->id : 'app-details-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function digest(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if ($item instanceof stdClass) {
                $fields = get_object_vars($item);
                ksort($fields);

                return (object) array_map($canonical, $fields);
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($canonical, $item);
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    private function fingerprint(int $user, string $tenant, array $data, array $revision): string
    {
        return $this->digest(['app-details-review-v1', $user, $tenant, $data, $revision]);
    }
}
