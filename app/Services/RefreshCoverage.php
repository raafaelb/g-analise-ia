<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class RefreshCoverage
{
    public function assess(array $app, array $tables, array $flows, array $schedules): array
    {
        $sources = [];
        $unresolved = [];
        foreach ($app['tables'] ?? [] as $binding) {
            $id = (string) ($binding['tableId'] ?? '');
            if (! preg_match('/^[1-9][0-9]*$/D', $id)) {
                continue;
            }
            $table = $tables[$id] ?? null;
            if (! $table) {
                $unresolved[] = ['table_id' => $id, 'reason' => 'Metadados da tabela indisponíveis para a sessão.'];

                continue;
            }
            $flowId = (string) ($table['originFlowId'] ?? '');
            if (! preg_match('/^[1-9][0-9]*$/D', $flowId)) {
                $unresolved[] = ['table_id' => $id, 'name' => $table['nome'] ?? $id, 'reason' => 'Fonte sem fluxo ETL identificado. A atualização precisa ser verificada na origem.'];

                continue;
            }
            if (! isset($sources[$flowId])) {
                $sources[$flowId] = ['flow_id' => $flowId, 'resource' => 'flow:'.$flowId, 'name' => $flows[$flowId]['nome'] ?? 'Fonte #'.$flowId, 'tables' => []];
            }
            $sources[$flowId]['tables'][] = ['id' => $id, 'name' => $table['nome'] ?? $id];
        }
        foreach ($sources as $id => &$source) {
            $flow = $flows[$id] ?? null;
            $matches = [];
            $covered = false;
            $active = false;
            foreach ($schedules as $scheduleId => $schedule) {
                $binding = collect($schedule['flows'] ?? [])->first(fn ($item) => (string) ($item['flow_etl_id'] ?? '') === (string) $id);
                if (! $binding) {
                    continue;
                }
                $isActive = ($schedule['ativo'] ?? false) === true;
                $active = $active || $isActive;
                $daily = $this->daily($schedule['triggers'] ?? []);
                $context = $flow ? $this->contextMatches((string) ($flow['load_type'] ?? ''), (string) ($binding['context'] ?? '')) : false;
                $sufficient = $isActive && $daily && $context;
                $covered = $covered || $sufficient;
                $matches[] = ['id' => (string) $scheduleId, 'name' => $schedule['nome'] ?? 'Agenda #'.$scheduleId, 'active' => $isActive, 'daily_or_more_frequent' => $daily, 'context' => $binding['context'] ?? null, 'context_compatible' => $context, 'covers_daily' => $sufficient, 'timezone' => $schedule['timezone'] ?? null, 'frequency' => $this->frequency($schedule['triggers'] ?? [])];
            }
            $published = $flow && isset($flow['deskId']) && (int) $flow['deskId'] > 0;
            $source['load_type'] = $flow['load_type'] ?? null;
            $source['published'] = $published;
            $source['schedules'] = $matches;
            $source['configured'] = $published && $covered;
            $source['status'] = ! $flow ? 'metadata_unavailable' : (! $published ? 'publication_required' : ($covered ? 'daily_configured' : 'schedule_required'));
            $source['recommendation'] = ! $flow ? 'verify_access' : (! $published ? 'request_source_publication' : ($covered ? 'preserve_existing' : ($active ? 'review_existing_schedule' : 'prepare_dedicated_schedule')));
            $source['execution_verification'] = 'pending';
        }
        unset($source);
        $sources = array_values($sources);
        $configured = count($sources) > 0 && count($unresolved) === 0 && collect($sources)->every(fn ($s) => $s['configured']);

        return ['sources' => $sources, 'unresolved_tables' => $unresolved, 'configured' => $configured, 'verified' => false, 'timezone' => 'America/Sao_Paulo', 'preferred_time' => '03:00', 'missing_flow_ids' => array_values(array_map(fn ($s) => $s['flow_id'], array_filter($sources, fn ($s) => ! $s['configured']))), 'message' => $configured ? 'Configuração diária identificada. A conclusão das cargas ainda precisa ser verificada.' : 'Há fontes pendentes. Não foi criada ou alterada nenhuma agenda.'];
    }

    public function inspect(User $user, string $tenant, string $appId, ?Project $project = null): array
    {
        if ($project) {
            abort_unless($project->user_id === $user->id && (string) $project->tenant_id === $tenant, 403);
        }
        $catalog = app(BiCatalog::class);
        $snapshot = $catalog->snapshot($user, $tenant, true);
        $detail = $catalog->detail($user, $tenant, 'app', $appId, includeRelations: false);
        [$connection,$item] = $catalog->access($user, $tenant);
        $context = $catalog->context($connection, $item);
        $documents = $catalog->workingMetadata($context);
        $maps = [];
        foreach (['table', 'flow', 'schedule'] as $kind) {
            $maps[$kind] = [];
            foreach ($snapshot['resources'][$kind] ?? [] as $resource) {
                $ref = $kind.':'.$resource['id'];
                if (isset($documents[$ref])) {
                    $maps[$kind][(string) $resource['id']] = $documents[$ref];
                }
            }
        }
        $report = $this->assess($detail['details'], $maps['table'], $maps['flow'], $maps['schedule']);
        $logs = [];
        foreach ($report['sources'] as &$source) {
            $source['last_execution'] = null;
            foreach ($source['schedules'] as $schedule) {
                if (! $schedule['covers_daily']) {
                    continue;
                }
                try {
                    $id = $schedule['id'];
                    $logs[$id] ??= app(LumoClient::class)->run($connection, ['info', 'schedule:'.$id], $context)['data'];
                    $payload = $logs[$id]['payload'] ?? $logs[$id];
                    $execution = $this->execution($logs[$id], (int) $id, [$source['flow_id']]);
                    $record = $project ? RefreshSchedule::where('user_id', $user->id)->where('tenant_id', $tenant)->where('source_id', $source['resource'])->first() : null;
                    $test = $record?->metadata['test_request'] ?? null;
                    if (is_array($test) && (string) ($test['schedule_id'] ?? '') === (string) $id) {
                        $configuration = $this->signature($maps['schedule'][$id] ?? [], $documents);
                        $sameConfiguration = ! empty($test['configuration_hash']) && hash_equals($test['configuration_hash'], $configuration);
                        if ($sameConfiguration) {
                            if (empty($test['run_id'])) {
                                $history = app(LumoClient::class)->run($connection, ['schedule', 'logs', 'schedule:'.$id, '--since', $test['requested_at'], '--tail', '100'], $context)['data'];
                                $test = $this->correlate($history, $test);
                                if ($user->role !== 'viewer') {
                                    $record->update(['metadata' => [...($record->metadata ?? []), 'test_request' => $test]]);
                                }
                            }
                            $proof = $record->metadata['test_result'] ?? null;
                            if (! empty($proof['verified']) && ($proof['run_id'] ?? null) === ($test['run_id'] ?? null)) {
                                $verification = $proof;
                            } elseif (! empty($test['run_id'])) {
                                $verification = $this->execution($logs[$id], (int) $id, $test['expected_flow_ids'], $test['run_id'], $test['requested_at']);
                            } else {
                                $verification = ['status' => $test['correlation'] ?? 'awaiting_history', 'verified' => false];
                            }
                            $source['test_execution'] = $verification;
                            if ($verification['verified'] && $user->role !== 'viewer') {
                                $record->update(['metadata' => [...($record->metadata ?? []), 'test_result' => $verification, 'test_verified' => true]]);
                            }
                        } else {
                            $source['test_execution'] = ['status' => 'configuration_changed', 'verified' => false];
                        }
                    }

                    if ($execution['status'] === 'observed_success' && Carbon::parse($execution['finished_at'])->lessThan(now()->subDay())) {
                        $execution['status'] = 'stale';
                    }
                    if (($payload['ativo'] ?? null) !== true) {
                        $execution['status'] = 'inactive';
                        $source['configured'] = false;
                    }
                    $source['last_execution'] = [...$execution, 'schedule_id' => $id];
                    if (in_array($execution['status'], ['observed_success', 'completed'], true)) {
                        break;
                    }
                } catch (\RuntimeException $e) {
                    $source['last_execution'] = ['status' => 'unavailable', 'verified' => false, 'message' => $e->getMessage()];
                }
            }
            if ($project && $user->role !== 'viewer') {
                $record = RefreshSchedule::firstOrNew(['user_id' => $user->id, 'tenant_id' => $tenant, 'source_id' => $source['resource']]);
                if (! $record->exists) {
                    $record->project_id = $project->id;
                }
                $observed = in_array($source['last_execution']['status'] ?? '', ['observed_success', 'completed'], true);
                $record->fill(['name' => $source['name'], 'remote_id' => $source['last_execution']['schedule_id'] ?? null, 'status' => $source['configured'] ? ($observed ? 'active' : 'pending_execution') : $source['status'], 'active' => $source['configured'], 'last_success_at' => $observed ? $source['last_execution']['finished_at'] : $record->last_success_at, 'metadata' => [...($record->metadata ?? []), 'coverage' => $source, 'test_verified' => $source['test_execution']['verified'] ?? false, 'observed_at' => now()->toIso8601String()]]);
                $record->save();
            }
        }
        unset($source);
        $report['configured'] = count($report['sources']) > 0 && $report['unresolved_tables'] === [] && collect($report['sources'])->every(fn ($source) => $source['configured']);
        $report['last_executions_successful'] = $report['configured'] && collect($report['sources'])->every(fn ($source) => in_array($source['last_execution']['status'] ?? '', ['observed_success', 'completed'], true));

        $report['verified'] = $report['last_executions_successful'] && collect($report['sources'])->every(fn ($source) => $source['test_execution']['verified'] ?? false);

        return [...$report, 'tenant' => $detail['tenant'], 'application' => $detail['resource'], 'checked_at' => now()->toIso8601String()];
    }

    public function signature(array $schedule, array $documents): string
    {
        $flows = [];
        foreach ($schedule['flows'] ?? [] as $binding) {
            $id = (int) ($binding['flow_etl_id'] ?? 0);
            $flows[(string) $id] = $documents['flow:'.$id] ?? null;
        }
        $data = ['schedule' => Arr::only($schedule, ['ativo', 'tokenId', 'timezone', 'triggers', 'flows']), 'flows' => $flows];
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }

    public function correlate(array $history, array $request): array
    {
        $rows = $history['lista'] ?? null;
        if (! is_array($rows) || count($rows) >= 100 || empty($request['requested_at']) || empty($request['expected_flow_ids'])) {
            return [...$request, 'correlation' => 'unavailable_or_truncated'];
        }
        try {
            $after = Carbon::parse($request['requested_at']);
        } catch (\Throwable) {
            return [...$request, 'correlation' => 'invalid_request_time'];
        }
        $expected = array_map('intval', $request['expected_flow_ids']);
        sort($expected);
        $matches = [];
        foreach ($rows as $row) {
            if ((int) ($row['schedule_id'] ?? 0) !== (int) ($request['schedule_id'] ?? 0) || empty($row['execution_id']) || empty($row['start_time'])) {
                continue;
            }
            try {
                $started = Carbon::parse($row['start_time']);
            } catch (\Throwable) {
                continue;
            }
            if ($started->lessThan($after) || $started->greaterThan($after->copy()->addMinutes(5))) {
                continue;
            }
            $actual = array_map(fn ($flow) => (int) ($flow['flow_id'] ?? 0), $row['flows'] ?? []);
            sort($actual);
            if ($actual !== $expected) {
                continue;
            }
            $matches[] = (string) $row['execution_id'];
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            return [...$request, 'correlation' => count($matches) > 1 ? 'ambiguous' : 'awaiting_history'];
        }

        return [...$request, 'run_id' => $matches[0], 'correlation' => 'unique_history', 'correlated_at' => now()->toIso8601String()];
    }

    public function execution(array $info, int $scheduleId, array $requiredFlows, ?string $expectedRun = null, ?string $requestedAt = null): array
    {
        $payload = $info['payload'] ?? $info;
        $log = $payload['last_log'] ?? null;
        $result = ['status' => 'pending', 'verified' => false, 'run_id' => null, 'started_at' => null, 'finished_at' => null, 'flows' => []];
        if (! is_array($log) || empty($log['run_id'])) {
            return $result;
        }
        if ((int) ($log['scheduleId'] ?? 0) !== $scheduleId) {
            return [...$result, 'status' => 'invalid_schedule'];
        }
        if ($expectedRun !== null && ! hash_equals($expectedRun, (string) $log['run_id'])) {
            return [...$result, 'status' => 'awaiting_requested_run'];
        }
        $result['run_id'] = $log['run_id'];
        try {
            $started = Carbon::parse($log['startedWhen'] ?? 'invalid');
            if ($requestedAt !== null && $started->lessThan(Carbon::parse($requestedAt))) {
                return [...$result, 'status' => 'stale_execution'];
            }
            $result['started_at'] = $started->toIso8601String();
            if (empty($log['finishedWhen'])) {
                return [...$result, 'status' => 'running'];
            }
            $finished = Carbon::parse($log['finishedWhen']);
            if ($finished->lessThan($started) || $finished->isFuture()) {
                return [...$result, 'status' => 'invalid_timestamps'];
            }
            $result['finished_at'] = $finished->toIso8601String();
        } catch (\Throwable) {
            return [...$result, 'status' => 'invalid_timestamps'];
        }
        $observed = [];
        $failed = false;
        $incomplete = false;
        foreach ($log['flows'] ?? [] as $flow) {
            $id = (string) ($flow['FlowID'] ?? '');
            if (! ctype_digit($id)) {
                $incomplete = true;

                continue;
            }
            $success = ($flow['Success'] ?? null) === true;
            $done = ! empty($flow['FinishedWhen']);
            if ($done) {
                try {
                    $flowEnd = Carbon::parse($flow['FinishedWhen']);
                    $flowStart = Carbon::parse($flow['StartedWhen'] ?? $log['startedWhen']);
                    $done = $flowEnd->greaterThanOrEqualTo($flowStart) && $flowStart->greaterThanOrEqualTo($started) && $flowEnd->lessThanOrEqualTo($finished);
                } catch (\Throwable) {
                    $done = false;
                }
            }
            if (! $done) {
                $incomplete = true;
            }
            if (array_key_exists('Success', $flow) && $flow['Success'] === false) {
                $failed = true;
            }
            $observed[$id] = $success && $done;
            $result['flows'][] = ['flow_id' => $id, 'success' => $success, 'finished' => $done, 'rows_in' => $flow['RowsIn'] ?? null, 'rows_out' => $flow['RowsOut'] ?? null];
        }
        foreach ($requiredFlows as $id) {
            if (! array_key_exists((string) $id, $observed)) {
                $incomplete = true;
            }
        }
        if ($failed) {
            return [...$result, 'status' => 'failed'];
        }
        if ($incomplete || ! $observed || in_array(false, $observed, true)) {
            return [...$result, 'status' => 'incomplete'];
        }
        $manual = $expectedRun !== null && $requestedAt !== null;

        return [...$result, 'status' => $manual ? 'completed' : 'observed_success', 'verified' => $manual];
    }

    public function contextMatches(string $loadType, string $context): bool
    {
        if ($context === 'KeySweep') {
            return false;
        }

        return match ($loadType) {
            'Total' => $context === 'Total',
            'Incremental' => in_array($context, ['Incremental', 'Total'], true),
            'Temporal' => $context === 'Total' || preg_match('/^Temporal:(Days:[1-9][0-9]*|Future:[1-9][0-9]*|Month:(?:[1-9]|1[0-2])-\d{4}|Year:(?:\d{4}|Current))$/D', $context) === 1,
            default => false,
        };
    }

    public function frequency(array $triggers): string
    {
        return app(BiScheduleTiming::class)->frequency($triggers);
    }

    public function daily(array $triggers): bool
    {
        return app(BiScheduleTiming::class)->daily($triggers);
    }
}
