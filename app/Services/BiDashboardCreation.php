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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiDashboardCreation
{
    public const ACTIONS = ['create_dashboard'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy, private BiDashboardBlueprint $blueprint) {}

    public function describe(User $user, string $tenant, string $id): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $id, $generation): array {
            [, , $shape, $summary] = $this->document($connection, $context, 'app:'.$id);
            $fields = $this->fields($connection, $context, 'app:'.$id, $shape);
            $this->assertSession($connection, $generation);

            return ['resource' => 'app:'.$id, 'name' => $shape->nome, 'version' => $this->blueprint->digest($shape), 'state' => $summary['state'], 'desk_id' => $summary['desk_id'], 'dashboard_count' => count($shape->dashboards), 'fields' => $fields, 'aggregations' => BiDashboardBlueprint::AGGREGATIONS];
        });
    }

    public function prepare(User $user, string $tenant, string $id, array $input): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $data = [...$this->blueprint->normalize($input), 'resource' => 'app:'.$id];
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        $requestHash = $this->blueprint->digest([$tenant, $data]);
        $generation = $connection->metadata['session_generation'] ?? null;
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $connection, $environment, $data, $requestHash, $generation): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outro painel. Reabra o assistente para outra revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                $revision = $this->withContext($connection, $environment, function (Project $context) use ($connection, $user, $tenant, $data, $generation): array {
                    [$path, $header, $shape] = $this->document($connection, $context, $data['resource']);
                    $this->assertDraft($shape, true);
                    abort_unless(hash_equals($data['source_version'], $this->blueprint->digest($shape)), 409, 'Esta aplicação mudou. Reabra o assistente para conferir a versão atual.');
                    $selection = $this->blueprint->selection($data, $this->fields($connection, $context, $data['resource'], $shape));
                    $new = $this->blueprint->dashboard($data, $selection, count($shape->dashboards), ($shape->filter_mode ?? null) === 'per_dashboard');
                    $updated = $this->append($shape, $new);
                    try {
                        $this->write($path, $header, $updated);
                        $this->validate($connection, $context, $data['resource']);
                    } finally {
                        $this->write($path, $header, $shape);
                    }
                    $coverage = $this->coverage($user, $tenant, $data['resource']);
                    try {
                        $total = $this->lumo->run($connection, ['app', 'query', $data['resource'], '--fields', $selection['metric_ref'], '--limit', '1'], $context, 60)['data'] ?? null;
                        $groups = $this->lumo->run($connection, ['app', 'query', $data['resource'], '--fields', $selection['group_ref'].','.$selection['metric_ref'], '--order-by', ($selection['chart_type'] === 'line' ? $data['group_id'].':ASC' : $data['metric_id'].':DESC'), '--limit', '26'], $context, 60)['data'] ?? null;
                    } catch (RuntimeException $e) {
                        if ($e->getCode() === 10) {
                            throw ValidationException::withMessages(['dashboard' => 'O G-Analise BI apresentou uma falha ao consultar os valores desta aplicação. Nenhum dashboard foi criado. Suas escolhas foram preservadas; você pode conferir esta solicitação quando a consulta estiver disponível. A aplicação não será publicada para contornar essa falha.']);
                        }
                        throw $e;
                    }
                    if (! is_array($total) || ! is_array($groups)) {
                        throw new RuntimeException('A consulta não retornou dados reconhecidos para a revisão.');
                    }
                    $this->assertSession($connection, $generation);

                    return ['tenant_id' => $tenant, 'resource' => $data['resource'], 'connection_generation' => $generation, 'source_digest' => $this->blueprint->digest($shape), 'preserved_digest' => $this->preservedDigest($shape), 'before_dashboard_ids' => $this->dashboardIds($shape, $data['resource'], $tenant), 'selection_digest' => $this->blueprint->digest($selection), 'expected_dashboard_digest' => $this->newDashboardDigest($new->body), 'dashboard_creation' => ['name' => $data['name'], 'app_name' => $shape->nome, 'dashboard_count_before' => count($shape->dashboards), 'widget_count' => 3, 'selection' => $selection, 'filter_behavior' => ($shape->filter_mode ?? null) === 'per_dashboard' ? 'O novo painel começa sem filtros fixos próprios. Os filtros das outras abas são preservados.' : 'O novo painel mantém o uso dos filtros globais da aplicação.', 'preview' => $this->blueprint->preview($total, $groups, $data, $selection), 'coverage' => $coverage], 'impact' => 'Adiciona somente um dashboard privado com indicador, gráfico e tabela nesta aplicação. Os painéis existentes, fontes, filtros e agendas são preservados. Não cria nem carrega tabelas e não publica a aplicação. A prévia interativa será aberta depois da criação conferida.'];
                });
                $approval = DB::transaction(function () use ($user, $connection, $tenant, $data, $revision, $requestHash): Approval {
                    $this->assertSession($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => 'Criar dashboard na aplicação', 'description' => 'Confira os campos, o cálculo, os dados consultados e a atualização diária antes de adicionar este painel privado.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.dashboard.review_created', 'Novo dashboard preparado com amostra de dados e revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource']]);

                    return $approval;
                });
                $this->notify($user->id);

                return ['approval' => $approval->fresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['dashboard' => 'Esta revisão ainda está sendo preparada. Aguarde e consulte a mesma solicitação.']);
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        [$data, $revision, $tenant] = $this->review($approval, $user);
        $this->assertExecution($approval, $user);
        [$connection, $environment] = $this->access($user, $tenant, substr($data['resource'], 4));
        $this->assertSession($connection, $revision['connection_generation']);
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        try {
            return Cache::lock('bi-app-details-write:'.$tenant.':'.$data['resource'], 630)->block(3, function () use ($approval, $user, $tenant, $data, $revision, $connection, $environment): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($approval, $user, $tenant, $data, $revision, $connection, $environment): array {
                    [$path, $header, $shape] = $this->document($connection, $context, $data['resource']);
                    $this->assertDraft($shape, true);
                    if (! hash_equals($revision['source_digest'], $this->blueprint->digest($shape))) {
                        throw new RuntimeException('A aplicação mudou depois da revisão. Reabra o assistente antes de criar o dashboard.');
                    }
                    $selection = $this->blueprint->selection($data, $this->fields($connection, $context, $data['resource'], $shape));
                    $new = $this->blueprint->dashboard($data, $selection, count($shape->dashboards), ($shape->filter_mode ?? null) === 'per_dashboard');
                    if (! hash_equals($revision['selection_digest'], $this->blueprint->digest($selection)) || ! hash_equals($revision['expected_dashboard_digest'], $this->newDashboardDigest($new->body))) {
                        throw new RuntimeException('Os campos ou o cálculo mudaram depois da revisão. Confira a proposta atual.');
                    }
                    $this->coverage($user, $tenant, $data['resource']);
                    $this->write($path, $header, $this->append($shape, $new));
                    $this->validate($connection, $context, $data['resource']);
                    $this->assertSession($connection, $revision['connection_generation']);
                    app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, $data['resource']);
                    $this->assertExecution($approval, $user);
                    $approval->update(['payload' => [...$approval->payload, 'dashboard_creation_attempt' => ['started_at' => now()->toIso8601String(), 'resource' => $data['resource']]]]);
                    try {
                        try {
                            $this->lumo->run($connection, ['push', $data['resource']], $context, 90);
                        } catch (Throwable $e) {
                            if ($e instanceof BiBusy || ($e instanceof RuntimeException && in_array($e->getCode(), [3, 4, 6, 8, 9, 14], true))) {
                                $approval->update(['payload' => [...$approval->payload, 'dashboard_creation_attempt' => [...$approval->payload['dashboard_creation_attempt'], 'native_rejection' => ['code' => $e->getCode(), 'before_save' => true]]]]);
                            }
                            throw $e;
                        }
                        $approval->update(['payload' => [...$approval->payload, 'dashboard_creation_attempt' => [...$approval->payload['dashboard_creation_attempt'], 'native_saved' => true]]]);
                        $result = $this->withContext($connection, $environment, fn (Project $verification): array => $this->confirm($connection, $verification, $data, $revision));
                        $this->assertSession($connection, $revision['connection_generation']);
                        Audit::record($user->id, 'bi.dashboard.created', 'Dashboard privado criado e conferido, preservando os painéis existentes.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'dashboard_id' => $result['dashboard_creation']['dashboard_id']]);

                        return $result;
                    } catch (Throwable $e) {
                        Audit::record($user->id, 'bi.dashboard.confirmation_pending', 'A criação do dashboard precisa de conferência antes de outra tentativa.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'exception_type' => get_class($e), 'exception_code' => (string) $e->getCode()]);
                        throw new RuntimeException('A criação ainda não pôde ser confirmada. Use Conferir criação para consultar o painel, sem enviar uma nova criação.');
                    } finally {
                        $this->catalog->invalidate($tenant, $connection);
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['dashboard' => 'Outra edição desta aplicação está em andamento. Aguarde e confira seu resultado.']);
        }
    }

    public function reconcile(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->findOrFail($id);
        [$data, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status === 'completed' || (($approval->payload['dashboard_creation_resolution']['state'] ?? null) === 'rejected' && ($approval->payload['dashboard_creation_resolution']['native_rejection_verified'] ?? false) === true)) {
            return ['approval' => $approval, 'project' => null];
        }
        abort_unless(in_array($approval->status, ['failed', 'expired', 'cancelled'], true) && isset($approval->payload['dashboard_creation_attempt']['started_at']) && $approval->decided_at !== null, 409, 'Esta criação ainda não tem uma tentativa encerrada para conferir.');
        [$connection, $environment] = $this->access($user, $tenant, substr($data['resource'], 4), $approval->id);
        $generation = $connection->metadata['session_generation'] ?? null;
        try {
            return Cache::lock('bi-operation:'.$approval->id, 630)->block(3, function () use ($approval, $user, $data, $revision, $connection, $environment, $generation, $tenant): array {
                $approval->refresh();
                if ($approval->status === 'completed') {
                    return ['approval' => $approval, 'project' => null];
                }
                abort_unless(in_array($approval->status, ['failed', 'expired', 'cancelled'], true), 409, 'A operação mudou durante a conferência. Consulte o estado atual.');
                try {
                    $result = $this->withContext($connection, $environment, fn (Project $context): array => $this->confirm($connection, $context, $data, $revision));
                } catch (Throwable $e) {
                    $attempt = $approval->payload['dashboard_creation_attempt'] ?? [];
                    if (($attempt['native_rejection']['before_save'] ?? false) !== true || ($attempt['native_saved'] ?? false) === true) {
                        throw $e;
                    }
                    $this->withContext($connection, $environment, function (Project $context) use ($connection, $data): array {
                        $this->document($connection, $context, $data['resource']);

                        return [];
                    });
                    $this->assertSession($connection, $generation);
                    DB::transaction(function () use ($approval, $user): void {
                        $current = Approval::where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->id);
                        $this->review($current, $user);
                        abort_unless(in_array($current->status, ['failed', 'expired', 'cancelled'], true), 409, 'O estado desta operação mudou. Consulte a confirmação atual.');
                        $current->update(['status' => 'failed', 'payload' => [...$current->payload, 'dashboard_creation_resolution' => ['state' => 'rejected', 'native_rejection_verified' => true, 'checked_at' => now()->toIso8601String()], 'execution' => ['status' => 'failed', 'finished_at' => now()->toIso8601String(), 'message' => 'O BI recusou esta criação antes de salvar. O acesso à aplicação atual foi conferido. Revise as permissões e prepare uma nova proposta; esta consulta não repetiu a criação.']]]);
                    });
                    $this->catalog->invalidate($tenant, $connection);
                    $this->notify($user->id);
                    Audit::record($user->id, 'bi.dashboard.rejection_verified', 'Recusa da criação conferida por leitura, sem novo envio.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource']]);

                    return ['approval' => $approval->fresh(), 'project' => null];
                }
                $this->assertSession($connection, $generation);
                DB::transaction(function () use ($approval, $user, $result): void {
                    $current = Approval::where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->id);
                    $this->review($current, $user);
                    abort_unless(in_array($current->status, ['failed', 'expired', 'cancelled'], true), 409, 'O estado desta operação mudou. Consulte a confirmação atual.');
                    $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result], 'reconciliation' => ['remote_read_only' => true, 'previous_status' => $current->status, 'checked_at' => now()->toIso8601String()]]]);
                    app(ProjectPreviews::class)->recordCompletedChange($current);
                });
                $this->catalog->invalidate($tenant, $connection);
                $this->notify($user->id);
                Audit::record($user->id, 'bi.dashboard.reconciled', 'Criação de dashboard conferida por leitura, sem novo envio.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource']]);

                return ['approval' => $approval->fresh(), 'project' => null];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['dashboard' => 'A operação ainda está em uso. Aguarde antes de conferir.']);
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && ($approval->payload['action']['action'] ?? '') === self::ACTIONS[0] && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'dashboard-create-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function access(User $user, string $tenant, string $id, ?string $ignoredApproval = null): array
    {
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite criar dashboards.');
        abort_unless(ctype_digit($id) && (int) $id > 0, 422, 'Aplicação inválida.');
        app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, 'app:'.$id, $ignoredApproval);

        return $this->catalog->access($user, $tenant);
    }

    private function document(Connection $connection, Project $context, string $resource): array
    {
        $row = collect($this->catalog->rows($connection, $context, 'app'))->first(fn (array $item): bool => 'app:'.$item['id'] === $resource);
        abort_unless($row !== null, 404, 'Esta aplicação não está disponível neste ambiente para sua conta.');
        $this->lumo->run($connection, ['pull', $resource], $context, 90);
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
            $shape = Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
            if (is_array($header) && ($header['kind'] ?? null) === 'app' && is_int($header['id'] ?? null) && 'app:'.$header['id'] === $resource && is_int($header['tenantId'] ?? null) && (string) $header['tenantId'] === (string) $context->tenant_id && $shape instanceof stdClass && is_string($shape->nome ?? null)) {
                $this->dashboardIds($shape, $resource, (string) $context->tenant_id);
                $documents[] = [$file->getPathname(), $header, $shape, $this->catalog->summary('app', $row, json_decode(json_encode($shape, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))];
            }
        }
        if (count($documents) !== 1) {
            throw new RuntimeException('Não foi identificada uma configuração completa e única desta aplicação.');
        }

        return $documents[0];
    }

    private function fields(Connection $connection, Project $context, string $resource, stdClass $shape): array
    {
        $info = $this->lumo->run($connection, ['info', $resource, '--full'], $context, 60)['data'] ?? null;
        if (! is_array($info)) {
            throw new RuntimeException('Os campos não foram devolvidos pelo BI.');
        }

        return $this->blueprint->fields($info, $shape, $resource, (string) $context->tenant_id);
    }

    private function coverage(User $user, string $tenant, string $resource): array
    {
        $report = app(RefreshCoverage::class)->inspect($user, $tenant, substr($resource, 4));
        if (($report['configured'] ?? false) !== true || ($report['verified'] ?? false) !== true || ($report['last_executions_successful'] ?? false) !== true || empty($report['sources'])) {
            throw ValidationException::withMessages(['dashboard' => 'Confira as fontes e agendas desta aplicação antes de criar o painel. É necessária atualização automática ao menos diária e uma carga concluída. Abra o recurso em um projeto para revisar a atualização dos dados.']);
        }

        return ['verified' => true, 'source_count' => count($report['sources']), 'checked_at' => $report['checked_at'] ?? now()->toIso8601String(), 'message' => 'Atualização automática ao menos diária e execução concluída conferidas. As agendas existentes serão preservadas.'];
    }

    private function assertDraft(stdClass $shape, bool $appending = false): void
    {
        if (($shape->_state ?? null) !== 'draft' || ! property_exists($shape, 'deskId') || $shape->deskId !== null || ($appending && count($shape->dashboards) >= 100)) {
            throw ValidationException::withMessages(['dashboard' => 'Use uma aplicação privada com menos de 100 dashboards. Se estiver publicada, duplique para sua mesa antes de criar o novo painel.']);
        }
    }

    private function dashboardIds(stdClass $shape, string $resource, string $tenant): array
    {
        if (! is_array($shape->dashboards ?? null) || ! array_is_list($shape->dashboards) || count($shape->dashboards) > 100) {
            throw new RuntimeException('A lista completa de dashboards não foi confirmada.');
        }
        $ids = [];
        foreach ($shape->dashboards as $dashboard) {
            $header = $dashboard->header ?? null;
            $body = $dashboard->body ?? null;
            if (! $header instanceof stdClass || ! $body instanceof stdClass || ($header->kind ?? null) !== 'app-dashboard' || ($header->lumo ?? null) !== 'v2' || ! is_int($header->id ?? null) || $header->id < 1 || ! is_int($header->tenantId ?? null) || (string) $header->tenantId !== $tenant || ! is_int($header->appId ?? null) || (string) $header->appId !== substr($resource, 4) || ! is_int($body->ordem ?? null) || ! is_string($body->nome ?? null) || ! is_array($body->widgets ?? null) || ! array_is_list($body->widgets)) {
                throw new RuntimeException('Um dashboard não foi identificado integralmente nesta aplicação.');
            }
            $ids[] = $header->id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('A lista de dashboards contém identidades repetidas.');
        }

        return $ids;
    }

    private function append(stdClass $source, stdClass $new): stdClass
    {
        $updated = clone $source;
        $updated->dashboards = [];
        foreach ($source->dashboards as $index => $original) {
            $dashboard = clone $original;
            $dashboard->body = clone $dashboard->body;
            $dashboard->body->ordem = $index;
            $updated->dashboards[] = $dashboard;
        }
        $updated->dashboards[] = $new;

        return $updated;
    }

    private function preservedDigest(stdClass $shape): string
    {
        $copy = clone $shape;
        $copy->dashboards = [];
        foreach ($shape->dashboards as $index => $original) {
            $dashboard = clone $original;
            $dashboard->body = clone $dashboard->body;
            $dashboard->body->ordem = $index;
            $copy->dashboards[] = $dashboard;
        }

        return $this->blueprint->digest($copy);
    }

    private function newDashboardDigest(stdClass $body): string
    {
        if (! is_array($body->widgets ?? null) || ! array_is_list($body->widgets)) {
            throw new RuntimeException('Os componentes do dashboard não foram confirmados.');
        }
        $copy = clone $body;
        $copy->ordem = 0;
        $copy->widgets = [];
        foreach ($body->widgets as $original) {
            if (! $original instanceof stdClass) {
                throw new RuntimeException('Um componente retornou em formato não reconhecido.');
            }
            $widget = clone $original;
            if (($widget->chart ?? null) instanceof stdClass) {
                $widget->chart = clone $widget->chart;
                // Native indexed options can round-trip as a list or a numeric-key object.
                foreach (['valueConfig', 'fieldOptions'] as $field) {
                    $options = $widget->chart->{$field} ?? null;
                    if ($options instanceof stdClass) {
                        $values = get_object_vars($options);
                        if ($values !== [] && array_is_list($values)) {
                            $widget->chart->{$field} = array_values($values);
                        }
                    }
                }
            }
            unset($widget->id);
            $copy->widgets[] = $widget;
        }

        return $this->blueprint->digest($copy);
    }

    private function confirm(Connection $connection, Project $context, array $data, array $revision): array
    {
        [, , $shape] = $this->document($connection, $context, $data['resource']);
        $this->assertDraft($shape);
        $selection = $this->blueprint->selection($data, $this->fields($connection, $context, $data['resource'], $shape));
        if (! hash_equals($revision['selection_digest'], $this->blueprint->digest($selection))) {
            throw new RuntimeException('Os campos ou seus significados mudaram durante a criação. Confira o painel atual.');
        }
        $ids = $this->dashboardIds($shape, $data['resource'], (string) $context->tenant_id);
        $displayed = $shape->dashboards;
        usort($displayed, fn (stdClass $a, stdClass $b): int => $a->body->ordem <=> $b->body->ordem);
        if (array_map(fn (stdClass $row): int => $row->header->id, $displayed) !== $ids) {
            throw new RuntimeException('A sequência dos dashboards retornou com posições contraditórias.');
        }
        $before = $revision['before_dashboard_ids'];
        if (count($ids) !== count($before) + 1 || array_slice($ids, 0, count($before)) !== $before || in_array($ids[count($before)], $before, true)) {
            throw new RuntimeException('Não foi confirmado um único novo dashboard, preservando a sequência dos painéis anteriores.');
        }
        $dashboard = $shape->dashboards[count($before)];
        $preserved = clone $shape;
        $preserved->dashboards = array_slice($shape->dashboards, 0, count($before));
        if (! hash_equals($revision['preserved_digest'], $this->preservedDigest($preserved)) || ! hash_equals($revision['expected_dashboard_digest'], $this->newDashboardDigest($dashboard->body))) {
            throw new RuntimeException('O conteúdo do novo painel ou de um painel anterior diverge da revisão.');
        }
        $oldWidgetIds = [];
        foreach ($preserved->dashboards as $old) {
            foreach ($old->body->widgets as $widget) {
                if (is_int($widget->id ?? null)) {
                    $oldWidgetIds[] = $widget->id;
                }
            }
        }
        $widgetIds = [];
        foreach ($dashboard->body->widgets as $widget) {
            if (! is_int($widget->id ?? null) || $widget->id < 1 || in_array($widget->id, $oldWidgetIds, true)) {
                throw new RuntimeException('Um novo componente não recebeu uma identidade própria.');
            }
            $widgetIds[] = $widget->id;
        }
        if (count($widgetIds) !== 3 || count(array_unique($widgetIds)) !== 3) {
            throw new RuntimeException('Os três componentes do novo dashboard não foram confirmados.');
        }
        $difference = $this->lumo->run($connection, ['diff', $data['resource'], '--include-remote'], $context, 60)['data'] ?? null;
        if (! is_array($difference) || ($difference['workingMissing'] ?? null) !== false || ($difference['workingVsState']['equal'] ?? null) !== true || ($difference['workingVsServer']['equal'] ?? null) !== true) {
            throw new RuntimeException('A versão do dashboard mudou durante a conferência.');
        }

        return ['resource' => $data['resource'], 'confirmed_name' => $shape->nome, 'configuration_verified' => true, 'dashboard_creation' => ['verified' => true, 'dashboard_id' => $dashboard->header->id, 'name' => $dashboard->body->nome, 'widget_ids' => $widgetIds, 'state' => 'draft', 'preserved_dashboard_count' => count($before)], 'message' => 'Dashboard privado criado e conferido. Abra a prévia interativa para conferir filtros e navegação.'];
    }

    private function review(Approval $approval, User $user): array
    {
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ($data['action'] ?? '') !== self::ACTIONS[0] || ! preg_match('/^app:[1-9][0-9]*$/D', (string) ($data['resource'] ?? '')) || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->blueprint->digest([$tenant, $data]))) {
            throw new RuntimeException('Esta revisão não corresponde à criação autorizada do dashboard.');
        }

        return [$data, $revision, $tenant];
    }

    private function assertExecution(Approval $approval, User $user): void
    {
        $approval->refresh();
        $this->review($approval, $user);
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || isset($approval->payload['dashboard_creation_attempt'])) {
            throw new RuntimeException('Esta revisão não permite outra criação. Confira o resultado antes de repetir.');
        }
    }

    private function validate(Connection $connection, Project $context, string $resource): void
    {
        if (! app(BiResourceValidation::class)->inspect($connection, $context, $resource)['passed']) {
            throw new RuntimeException('A configuração do novo painel não passou na validação. Confira a aplicação antes de criar.');
        }
    }

    private function write(string $path, array $header, stdClass $shape): void
    {
        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($shape, 30, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), 0600);
    }

    private function assertSession(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou. Confira o resultado antes de continuar.');
        }
    }

    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Novo dashboard']);
        $context->id = $approval ? 'dashboard-create-operation-'.$approval->id : 'dashboard-create-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function fingerprint(int $user, string $tenant, array $data, array $revision): string
    {
        return $this->blueprint->digest(['dashboard-create-review-v1', $user, $tenant, $data, $revision]);
    }

    private function notify(int $userId): void
    {
        try {
            event(new WorkspaceChanged($userId));
        } catch (Throwable) {
        }
    }
}
