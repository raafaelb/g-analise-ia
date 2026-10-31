<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Jobs\ObserveBiLoadExecution;
use App\Models\Approval;
use App\Models\BiLoadExecution;
use App\Models\Connection;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BiScheduleRuns
{
    public const OBSERVING = ['requesting', 'accepted', 'running', 'uncertain'];

    public const UNRESOLVED = ['requesting', 'accepted', 'running', 'uncertain', 'ambiguous', 'needs_attention', 'access_changed', 'needs_revalidation'];

    public function __construct(private LumoClient $lumo, private BiCatalog $catalog, private BiScheduleDefinition $definition, private BiLoadResults $results) {}

    public function describe(User $user, string $tenant, string $id): array
    {
        [$connection] = $this->access($user, $tenant);
        $plan = $this->plan($connection, $tenant, $id);

        return [...$plan, 'executions' => $this->recent($user, $tenant, $id)];
    }

    public function recent(User $user, string $tenant, string $id): array
    {
        $this->access($user, $tenant);

        return BiLoadExecution::where('user_id', $user->id)->where('tenant_id', $tenant)->where('schedule_id', $id)->latest()->limit(10)->get()->map(fn (BiLoadExecution $execution): array => $this->present($execution))->all();
    }

    public function plan(Connection $connection, string $tenant, string $id): array
    {
        [$current] = $this->access(User::findOrFail($connection->user_id), $tenant);
        abort_unless($current->id === $connection->id && ($current->metadata['session_generation'] ?? null) === ($connection->metadata['session_generation'] ?? null), 403, 'A sessão mudou.');

        return $this->withContext($current, $tenant, fn (Project $context): array => $this->snapshot($current, $context, $id));
    }

    public function prepare(User $user, string $tenant, string $id, array $input): array
    {
        $data = Validator::make($input, ['request_id' => ['required', 'uuid'], 'version' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/D']])->validate();
        abort_if(array_diff(array_keys($input), array_keys($data)) !== [], 422, 'A solicitação contém campos desconhecidos.');
        [$connection] = $this->access($user, $tenant);
        $action = ['action' => 'run_schedule_now', 'resource' => 'schedule:'.$id, ...$data];
        $requestHash = $this->definition->digest([$tenant, $action]);

        return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $id, $connection, $action, $requestHash): array {
            $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $action['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada. Abra outra revisão.');

                return ['approval' => $existing, 'project' => null, 'reused' => true];
            }
            $plan = $this->plan($connection, $tenant, $id);
            abort_unless(! isset($action['version']) || hash_equals($action['version'], $plan['version']), 409, 'A agenda ou suas fontes mudaram. Confira a atualização novamente.');
            $this->assertAvailable($plan);
            app(TenantPolicy::class)->assertCommandAllowed($tenant, ['schedule', 'run', 'schedule:'.$id], $connection);
            $revision = ['tenant_id' => $tenant, 'resource' => 'schedule:'.$id, 'schedule' => $plan['schedule'], 'load_plan' => $plan];
            $this->assertSession($connection, $plan['connection_generation']);
            $approval = Approval::create(['user_id' => $user->id, 'type' => 'manual', 'title' => 'Atualizar dados agora', 'description' => 'Executar uma vez as fontes desta agenda. Isso grava dados no BI; horários e ativação permanecem como estão.', 'payload' => ['origin' => 'manual', 'request_id' => $action['request_id'], 'request_hash' => $requestHash, 'action' => $action, 'revision' => $revision], 'fingerprint' => $this->fingerprint($user->id, $tenant, $action, $revision), 'expires_at' => now()->addMinutes(12)]);

            Audit::record($user->id, 'bi.load.review_created', 'Carga pontual preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'schedule_id' => $id]);
            try {
                event(new WorkspaceChanged($user->id));
            } catch (Throwable) {
            }

            return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
        });
    }

    public function executeApproved(Approval $approval): array
    {
        $user = User::findOrFail($approval->user_id);
        $action = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if ($approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ($action['action'] ?? '') !== 'run_schedule_now' || $approval->status !== 'executing' || ! $approval->decided_at || $approval->expires_at->isPast() || ! $approval->permitsUserAccess($user) || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $action, $revision))) {
            throw new RuntimeException('Esta revisão de carga não está autorizada.');
        }
        [$connection] = $this->access($user, $tenant);

        return $this->send($user, $connection, $tenant, substr($action['resource'], 9), $approval, $revision['load_plan'] ?? []);
    }

    public function send(User $user, Connection $connection, string $tenant, string $id, Approval $approval, array $expected, ?Project $project = null): array
    {
        return Cache::lock('schedule-write:'.$tenant, 630)->block(3, function () use ($user, $connection, $tenant, $id, $approval, $expected, $project): array {
            if (BiLoadExecution::where('approval_id', $approval->id)->exists()) {
                throw new RuntimeException('Esta autorização já tem uma tentativa registrada. Confira a carga; o envio não será repetido.');
            }
            [$current] = $this->access($user, $tenant);
            $this->assertSession($current, $expected['connection_generation'] ?? null);
            abort_unless($current->id === $connection->id && $approval->permitsUserAccess($user) && $approval->status === 'executing' && $approval->decided_at && $approval->expires_at->isFuture(), 403, 'A autorização não está válida para envio.');
            abort_unless((int) $approval->user_id === $user->id && ($approval->payload['revision']['tenant_id'] ?? null) === $tenant && ($approval->payload['action']['resource'] ?? null) === 'schedule:'.$id && in_array($approval->payload['action']['action'] ?? '', ['run_schedule', 'run_schedule_now'], true) && ($project === null ? $approval->project_id === null : $project->user_id === $user->id && (string) $project->tenant_id === $tenant && $approval->project_id === $project->id), 403, 'A carga está fora do contexto autorizado.');
            app(TenantPolicy::class)->assertCommandAllowed($tenant, ['schedule', 'run', 'schedule:'.$id], $current);

            return $this->withContext($current, $tenant, function (Project $context) use ($user, $current, $id, $approval, $expected, $project): array {
                $plan = $this->snapshot($current, $context, $id);
                if ($plan !== $expected) {
                    throw new RuntimeException('A agenda, suas fontes ou seu acesso mudaram. Prepare outra revisão.');
                }
                $this->assertAvailable($plan);
                $ids = array_column($plan['bindings'], 'flow_id');
                foreach (BiLoadExecution::where('tenant_id', $context->tenant_id)->whereIn('status', self::UNRESOLVED)->get() as $pending) {
                    if (array_intersect($ids, array_column($pending->metadata['bindings'] ?? [], 'flow_id')) !== []) {
                        throw new RuntimeException('Há um pedido anterior destas fontes sem resultado confirmado. Confira a carga antes de solicitar outra.');
                    }
                }
                $before = $this->read($current, $context, $id);
                $log = $before['info']['payload']['last_log'] ?? $before['info']['last_log'] ?? null;
                if (is_array($log) && ! empty($log['startedWhen']) && empty($log['finishedWhen'])) {
                    throw new RuntimeException('Esta agenda já tem uma execução em andamento. Acompanhe o histórico antes de solicitar outra.');
                }
                foreach (array_unique($ids) as $flowId) {
                    $status = $this->lumo->run($current, ['flow', 'status', 'flow:'.$flowId], $context, 60)['data'];
                    if (! is_array($status) || (! empty($status['startedWhen']) && empty($status['finishedWhen']))) {
                        throw new RuntimeException('Uma das fontes está ocupada ou seu estado não pôde ser conferido. Aguarde antes de solicitar outra carga.');
                    }
                }
                $known = array_values(array_unique(array_filter([...(array_column($before['history']['lista'], 'execution_id')), $log['run_id'] ?? null], is_string(...))));
                $this->assertSession($current, $plan['connection_generation']);
                $execution = DB::transaction(function () use ($user, $current, $context, $id, $approval, $plan, $known, $project): BiLoadExecution {
                    $execution = BiLoadExecution::create(['user_id' => $user->id, 'approval_id' => $approval->id, 'project_id' => $project?->id, 'connection_id' => $current->id, 'tenant_id' => $context->tenant_id, 'schedule_id' => $id, 'status' => 'requesting', 'requested_at' => now(), 'metadata' => ['schedule_name' => $plan['schedule']['nome'], 'bindings' => $plan['bindings'], 'configuration_hash' => $plan['configuration_hash'], 'connection_generation' => $plan['connection_generation'], 'access_version' => $user->access_version, 'before_run_ids' => $known, 'run_id' => null, 'correlation' => 'awaiting_history']]);
                    $approval->update(['payload' => [...$approval->payload, 'load_execution_id' => $execution->id]]);
                    $this->recordCoverageRequest($execution, $project);

                    return $execution;
                });
                try {
                    $response = $this->lumo->run($current, ['schedule', 'run', 'schedule:'.$id], $context, 180);
                    $data = $response['data'] ?? null;
                    $runId = is_array($data) ? ($data['run_id'] ?? $data['runId'] ?? $data['execution']['run_id'] ?? null) : null;
                    $execution->update(['status' => 'accepted', 'metadata' => [...$execution->metadata, 'run_id' => is_string($runId) && $runId !== '' ? $runId : null, 'correlation' => is_string($runId) && $runId !== '' ? 'response_id' : 'awaiting_history', 'accepted_at' => now()->toIso8601String()]]);
                } catch (Throwable $failure) {
                    $rejected = $failure instanceof RuntimeException && in_array($failure->getCode(), [2, 3, 4, 5, 6, 8, 9, 11, 12, 13, 14], true);
                    $execution->update(['status' => $rejected ? 'rejected' : 'uncertain', 'metadata' => [...$execution->metadata, 'message' => $rejected ? 'O comando recusou o pedido. Confira as permissões e a agenda antes de preparar outra revisão.' : 'O retorno do envio não foi confirmado. O histórico será consultado sem repetir a carga.']]);
                    if ($rejected) {
                        throw $failure;
                    }
                } finally {
                    if (in_array($execution->fresh()->status, self::OBSERVING, true)) {
                        ObserveBiLoadExecution::dispatch($execution->id)->onQueue('agents')->delay(now()->addSeconds(5));
                    }
                    Audit::record($user->id, 'bi.load.requested', 'Tentativa de carga registrada; resultado final tem conferência própria.', $project?->id, ['execution_id' => $execution->id, 'approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'schedule_id' => $id]);
                }

                return ['execution_request' => $this->request($execution->fresh()), 'load_execution' => $this->present($execution->fresh()), 'message' => 'Pedido registrado. Acompanhe o resultado de todas as fontes; o envio não comprova a conclusão.'];
            });
        });
    }

    public function show(User $user, string $id): array
    {
        $execution = BiLoadExecution::where('user_id', $user->id)->findOrFail($id);
        $this->access($user, $execution->tenant_id);

        return $this->present($execution);
    }

    public function observe(User $user, string $id): array
    {
        $execution = BiLoadExecution::where('user_id', $user->id)->findOrFail($id);
        [$connection] = $this->access($user, $execution->tenant_id);
        if (in_array($execution->status, ['access_changed', 'needs_revalidation'], true)) {
            $this->withContext($connection, $execution->tenant_id, function (Project $context) use ($connection, $execution, $user): array {
                abort_unless(collect($this->catalog->rows($connection, $context, 'schedule'))->contains(fn (array $row): bool => (string) $row['id'] === $execution->schedule_id), 404);
                $visible = array_map(fn (array $row): string => (string) $row['id'], $this->catalog->rows($connection, $context, 'flow'));
                abort_unless(array_diff(array_column($execution->metadata['bindings'], 'flow_id'), $visible) === [], 403, 'Uma fonte da tentativa anterior não está disponível para seu acesso atual.');
                $this->assertSession($connection, $connection->metadata['session_generation'] ?? null);
                $execution->update(['status' => 'uncertain', 'connection_id' => $connection->id, 'metadata' => [...$execution->metadata, 'connection_generation' => $connection->metadata['session_generation'], 'access_version' => $user->access_version]]);

                return [];
            });
        }

        $result = $this->observeRecord($execution);
        if (in_array($result['status'], self::OBSERVING, true)) {
            ObserveBiLoadExecution::dispatch($execution->id)->onQueue('agents')->delay(now()->addSeconds(15));
        }

        return $result;
    }

    public function observeRecord(BiLoadExecution $execution): array
    {
        return Cache::lock('bi-load-observe:'.$execution->id, 180)->block(2, function () use ($execution): array {
            $execution->refresh();
            if (! in_array($execution->status, self::UNRESOLVED, true)) {
                return $this->present($execution);
            }
            $user = User::find($execution->user_id);
            $connection = Connection::find($execution->connection_id);
            $metadata = $execution->metadata;
            if (! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== ($metadata['access_version'] ?? null) || ! $connection || $connection->status !== 'connected' || ($connection->metadata['session_generation'] ?? null) !== ($metadata['connection_generation'] ?? null) || ! collect($connection->metadata['tenants'] ?? [])->contains(fn (array $tenant): bool => (string) $tenant['id'] === $execution->tenant_id)) {
                $execution->update(['status' => 'access_changed', 'last_checked_at' => now()]);

                return $this->present($execution);
            }
            $connection->operationAccessVersion = $user->access_version;
            try {
                return $this->withContext($connection, $execution->tenant_id, function (Project $context) use ($connection, $execution): array {
                    $data = $this->read($connection, $context, $execution->schedule_id, $execution->requested_at->toIso8601String());
                    $request = $this->request($execution);
                    $correlation = $this->results->correlate($data['history'], $data['info'], $request);
                    $proof = null;
                    if ($correlation['run_id'] !== null) {
                        $proof = $this->results->info($data['info'], $execution->schedule_id, $request['bindings'], $correlation['run_id'], $request['requested_at']);
                        if (! in_array($proof['status'], ['succeeded', 'failed', 'running'], true)) {
                            $matching = array_values(array_filter($data['history']['lista'], fn (array $row): bool => ($row['execution_id'] ?? null) === $correlation['run_id']));
                            if (count($matching) === 1) {
                                $proof = $this->results->history($matching[0], $execution->schedule_id, $request['bindings'], $correlation['run_id'], $request['requested_at']);
                            }
                        }
                    }
                    $this->assertSession($connection, $execution->metadata['connection_generation']);
                    $status = in_array($proof['status'] ?? '', ['succeeded', 'failed', 'cancelled', 'running'], true) ? $proof['status'] : ($correlation['correlation'] === 'ambiguous' ? 'ambiguous' : ($execution->status === 'uncertain' ? 'uncertain' : 'accepted'));
                    if (in_array($status, self::OBSERVING, true) && $execution->requested_at->lessThan(now()->subHours(4))) {
                        $status = 'needs_attention';
                    }
                    $execution->update(['status' => $status, 'started_at' => isset($proof['started_at']) ? Carbon::parse($proof['started_at'])->setTimezone(config('app.timezone')) : null, 'finished_at' => isset($proof['finished_at']) ? Carbon::parse($proof['finished_at'])->setTimezone(config('app.timezone')) : null, 'last_checked_at' => now(), 'metadata' => [...$execution->metadata, ...$correlation, 'proof' => $proof, 'observation_error' => null]]);
                    $this->recordCoverageProof($execution);

                    return $this->present($execution);
                });
            } catch (Throwable) {
                $execution->update(['status' => $execution->requested_at->lessThan(now()->subHours(4)) ? 'needs_attention' : $execution->status, 'last_checked_at' => now(), 'metadata' => [...$execution->metadata, 'observation_error' => 'Não foi possível consultar o resultado agora. A tentativa permanece registrada; nenhum envio foi repetido.']]);

                return $this->present($execution);
            }
        });
    }

    public function present(BiLoadExecution $execution): array
    {
        $metadata = $execution->metadata;

        return ['id' => $execution->id, 'schedule_id' => $execution->schedule_id, 'schedule_name' => $metadata['schedule_name'], 'status' => $execution->status, 'requested_at' => $execution->requested_at?->toIso8601String(), 'started_at' => $execution->started_at?->toIso8601String(), 'finished_at' => $execution->finished_at?->toIso8601String(), 'checked_at' => $execution->last_checked_at?->toIso8601String(), 'bindings' => $metadata['bindings'], 'run_id' => $metadata['run_id'] ?? null, 'correlation' => $metadata['correlation'] ?? null, 'proof' => $metadata['proof'] ?? null, 'message' => $metadata['observation_error'] ?? $metadata['message'] ?? null];
    }

    private function snapshot(Connection $connection, Project $context, string $id): array
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/D', $id), 422, 'Agenda inválida.');
        abort_unless(collect($this->catalog->rows($connection, $context, 'schedule'))->contains(fn (array $row): bool => (string) $row['id'] === $id), 404, 'Agenda indisponível para sua sessão.');
        $this->lumo->run($connection, ['pull', 'schedule:'.$id], $context, 90);
        $schedule = $this->catalog->workingMetadata($context, ['schedule:'.$id])['schedule:'.$id] ?? [];
        if (! is_string($schedule['nome'] ?? null) || ! is_bool($schedule['ativo'] ?? null) || ! is_array($schedule['flows'] ?? null) || ! array_is_list($schedule['flows']) || count($schedule['flows']) > 100 || ! ctype_digit((string) ($schedule['tokenId'] ?? ''))) {
            throw new RuntimeException('A configuração da agenda não chegou completa.');
        }
        $ids = [];
        foreach ($schedule['flows'] as $row) {
            if (! is_array($row) || ! preg_match('/^[1-9][0-9]{0,17}$/D', (string) ($row['flow_etl_id'] ?? '')) || ! is_string($row['context'] ?? null)) {
                throw new RuntimeException('Uma fonte da agenda não foi identificada.');
            }
            $ids[] = (string) $row['flow_etl_id'];
        }
        $visible = array_map(fn (array $row): string => (string) $row['id'], $this->catalog->rows($connection, $context, 'flow'));
        abort_unless(array_diff($ids, $visible) === [], 403, 'Uma fonte da agenda não está disponível para sua sessão.');
        if ($ids !== []) {
            $this->lumo->run($connection, ['pull', ...array_map(fn (string $source): string => 'flow:'.$source, array_values(array_unique($ids)))], $context, 150);
        }
        $documents = $this->catalog->workingMetadata($context);
        $bindings = [];
        foreach ($schedule['flows'] as $row) {
            $flowId = (string) $row['flow_etl_id'];
            $flow = $documents['flow:'.$flowId] ?? [];
            if (empty($flow['deskId']) || (string) ($flow['tokenId'] ?? '') !== (string) $schedule['tokenId'] || (($row['context'] ?? '') === 'KeySweep' ? ($flow['load_type'] ?? '') !== 'Incremental' || empty($flow['key_sweep']['sql']) : ! app(RefreshCoverage::class)->contextMatches((string) ($flow['load_type'] ?? ''), $row['context']))) {
                throw new RuntimeException('Uma fonte está privada, usa outro agente ou tem uma forma de carga incompatível. Confira a agenda antes de executar.');
            }
            $bindings[] = ['flow_id' => $flowId, 'name' => (string) ($flow['nome'] ?? 'Fonte #'.$flowId), 'context' => $row['context'], 'version' => $this->definition->version($flow)];
        }
        $generation = $connection->metadata['session_generation'] ?? null;
        $this->assertSession($connection, $generation);

        return ['version' => $this->definition->digest([$this->definition->version($schedule), $bindings]), 'configuration_hash' => app(RefreshCoverage::class)->signature($schedule, $documents), 'connection_generation' => $generation, 'access_version' => $connection->operationAccessVersion, 'schedule' => [...Arr::only($schedule, ['nome', 'ativo', 'tokenId', 'timezone']), 'triggers' => array_map(fn (array $trigger): array => Arr::only($trigger, ['type', 'period', 'start_when', 'start_time', 'end_time']), $schedule['triggers'] ?? []), 'flows' => array_map(fn (array $binding): array => Arr::only($binding, ['flow_etl_id', 'context']), $schedule['flows'])], 'bindings' => $bindings, 'available' => $schedule['ativo'] === true && $bindings !== [], 'reason' => ! $schedule['ativo'] ? 'Esta agenda está inativa. Confira sua configuração e autorize a ativação antes de executar.' : ($bindings === [] ? 'Esta agenda não tem fontes.' : null)];
    }

    private function read(Connection $connection, Project $context, string $id, ?string $since = null): array
    {
        $info = $this->lumo->run($connection, ['info', 'schedule:'.$id], $context, 60)['data'];
        $history = $this->lumo->run($connection, ['schedule', 'logs', 'schedule:'.$id, ...($since ? ['--since', $since] : []), '--tail', '100'], $context, 60)['data'];
        if (! is_array($info) || ! is_array($history) || ! is_array($history['lista'] ?? null) || ! array_is_list($history['lista']) || count($history['lista']) >= 1000 || (isset($history['schedule']['id']) && (string) $history['schedule']['id'] !== $id) || (string) ($info['payload']['id'] ?? $info['id'] ?? '') !== $id || collect($history['lista'])->contains(fn (mixed $row): bool => ! is_array($row) || (string) ($row['schedule_id'] ?? '') !== $id)) {
            throw new RuntimeException('O histórico da agenda está indisponível ou incompleto.');
        }

        return compact('info', 'history');
    }

    private function request(BiLoadExecution $execution): array
    {
        return ['execution_id' => $execution->id, 'schedule_id' => $execution->schedule_id, 'requested_at' => $execution->requested_at->toIso8601String(), 'bindings' => $execution->metadata['bindings'], 'expected_flow_ids' => array_values(array_unique(array_map('intval', array_column($execution->metadata['bindings'], 'flow_id')))), 'before_run_ids' => $execution->metadata['before_run_ids'], 'run_id' => $execution->metadata['run_id'] ?? null, 'correlation' => $execution->metadata['correlation'], 'configuration_hash' => $execution->metadata['configuration_hash'], 'verified' => ($execution->metadata['proof']['verified'] ?? false) === true];
    }

    private function recordCoverageRequest(BiLoadExecution $execution, ?Project $project): void
    {
        if (! $project) {
            return;
        }
        foreach ($this->request($execution)['expected_flow_ids'] as $flowId) {
            $record = RefreshSchedule::firstOrNew(['user_id' => $execution->user_id, 'tenant_id' => $execution->tenant_id, 'source_id' => 'flow:'.$flowId]);
            if (! $record->exists) {
                $record->project_id = $project->id;
                $record->name = 'Fonte #'.$flowId;
            }
            $record->fill(['remote_id' => $execution->schedule_id, 'status' => 'pending_test', 'metadata' => [...($record->metadata ?? []), 'test_request' => $this->request($execution), 'test_verified' => false]])->save();
        }
    }

    private function recordCoverageProof(BiLoadExecution $execution): void
    {
        foreach (RefreshSchedule::where('user_id', $execution->user_id)->where('tenant_id', $execution->tenant_id)->get() as $record) {
            if (($record->metadata['test_request']['execution_id'] ?? null) === $execution->id) {
                $record->update(['metadata' => [...$record->metadata, 'test_request' => $this->request($execution), 'test_result' => $execution->metadata['proof'], 'test_verified' => ($execution->metadata['proof']['verified'] ?? false) === true]]);
            }
        }
    }

    private function access(User $user, string $tenant): array
    {
        $current = $user->fresh();
        abort_unless($current?->canStartWork() && $current->role !== 'viewer' && $current->access_version === $user->access_version, 403, 'Seu acesso permite somente consulta.');

        return $this->catalog->access($user, $tenant);
    }

    private function assertSession(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! is_string($generation) || $generation === '' || ! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation || ! $user?->canStartWork() || $user->access_version !== $connection->operationAccessVersion) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou. Confira a sessão antes de continuar.');
        }
    }

    private function assertAvailable(array $plan): void
    {
        if (! $plan['available']) {
            throw new RuntimeException($plan['reason']);
        }
    }

    private function withContext(Connection $connection, string $tenant, callable $operation): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => $tenant, 'name' => 'Conferência de carga']);
        $context->id = 'load-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function fingerprint(int $user, string $tenant, array $action, array $revision): string
    {
        return $this->definition->digest(['load-review-v1', $user, $tenant, $action, $revision]);
    }
}
