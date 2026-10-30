<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

class BiInspector
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function inspect(User $user, string $tenant, string $kind, string $id, string $action): array
    {
        $arguments = match ($kind.':'.$action) {
            'table:data' => ['table', 'preview', 'table:'.$id, '--limit', '20'],
            'flow:status' => ['flow', 'status', 'flow:'.$id],
            'flow:history' => ['flow', 'logs', 'flow:'.$id, '--tail', '20'],
            'schedule:history' => ['schedule', 'logs', 'schedule:'.$id, '--tail', '20'],
            'agent:health' => ['agent', 'health', 'agent:'.$id],
            'agent:events' => ['agent', 'events', 'agent:'.$id, '--window', '48h'],
            default => null,
        };
        abort_unless($arguments, 422, 'Esta consulta não está disponível para este tipo de recurso.');
        $detail = $this->catalog->detail($user, $tenant, $kind, $id);
        [$connection, $item] = $this->catalog->access($user, $tenant);
        $project = $this->catalog->context($connection, $item);
        $result = $this->lumo->run($connection, $arguments, $project, 60)['data'];
        abort_unless(is_array($result), 422, 'O BI não retornou um relatório em formato reconhecido.');
        $report = ['resource' => $detail['resource']['ref'], 'action' => $action, 'checked_at' => now()->toIso8601String()];

        return [...$report, ...match ($kind.':'.$action) {
            'table:data' => $this->table($result),
            'flow:status' => ['history' => [$this->execution($result)]],
            'flow:history' => ['history' => array_map($this->execution(...), $this->rows($result))],
            'schedule:history' => ['history' => array_map($this->scheduleExecution(...), $this->rows($result))],
            'agent:health' => $this->health($result),
            'agent:events' => $this->events($result, $tenant),
        }];
    }

    private function table(array $data): array
    {
        abort_unless(is_array($data['rows'] ?? null) && array_is_list($data['rows']), 422, 'A amostra de dados não retornou linhas em formato reconhecido.');
        $rows = [];
        $columns = [];
        foreach (array_slice($data['rows'], 0, 20) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $safe = [];
            foreach (array_slice($this->catalog->redact($row), 0, 50, true) as $key => $value) {
                $name = mb_substr((string) $key, 0, 150);
                $columns[$name] = true;
                $safe[$name] = is_scalar($value) || $value === null ? (is_string($value) ? mb_substr($value, 0, 2000) : $value) : '[valor composto]';
            }
            $rows[] = $safe;
        }

        return ['table' => ['columns' => array_keys($columns), 'rows' => $rows, 'limit' => 20], 'message' => 'Amostra de até 20 linhas; não representa a contagem total da tabela.'];
    }

    private function rows(array $data): array
    {
        $rows = $data['lista'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows), 422, 'O histórico não retornou uma lista em formato reconhecido.');

        return array_slice(array_values(array_filter($rows, is_array(...))), 0, 100);
    }

    private function execution(array $row): array
    {
        $finished = $this->date($row['finishedWhen'] ?? null);
        $started = $this->date($row['startedWhen'] ?? null);
        $success = $row['success'] ?? null;
        $state = $finished && is_bool($success) ? ($success ? 'succeeded' : 'failed') : ($started && ! $finished ? 'running' : 'unknown');

        return ['id' => $this->text($row['jobId'] ?? null), 'state' => $state, 'started_at' => $started, 'finished_at' => $finished, 'duration_ms' => $this->number($row['duration'] ?? null), 'rows_in' => $this->number($row['rowsIn'] ?? null), 'rows_out' => $this->number($row['rowsOut'] ?? null), 'message' => $this->text($row['message'] ?? null)];
    }

    private function scheduleExecution(array $row): array
    {
        $finished = $this->date($row['end_time'] ?? null);
        $started = $this->date($row['start_time'] ?? null);
        $status = strtolower(is_string($row['status'] ?? null) ? $row['status'] : '');
        $flows = is_array($row['flows'] ?? null) ? $row['flows'] : [];
        $outcomes = array_map(fn ($flow) => is_array($flow) ? ($flow['success'] ?? null) : null, $flows);
        $failed = collect($outcomes)->contains(fn ($value) => in_array($value, [false, 0], true));
        $allSucceeded = $outcomes !== [] && collect($outcomes)->every(fn ($value) => in_array($value, [true, 1], true));
        $state = $finished ? ($failed || in_array($status, ['error', 'failed', 'failure'], true) ? 'failed' : ($allSucceeded ? 'succeeded' : (in_array($status, ['cancelled', 'canceled'], true) ? 'cancelled' : 'unknown'))) : match ($status) {
            'queued', 'pending' => 'queued', 'running', 'in_progress' => 'running', default => $started ? 'running' : 'unknown',
        };

        return ['id' => $this->text($row['execution_id'] ?? null), 'state' => $state, 'started_at' => $started, 'finished_at' => $finished, 'duration_ms' => null, 'rows_in' => null, 'rows_out' => null, 'message' => null];
    }

    private function health(array $data): array
    {
        $current = is_array($data['current'] ?? null) ? $data['current'] : [];
        $timeline = [];
        foreach (is_array($data['buckets'] ?? null) ? $data['buckets'] : [] as $bucket) {
            if (! is_array($bucket) || ! is_numeric($bucket['ts'] ?? null)) {
                continue;
            }
            $state = strtolower(is_string($bucket['state'] ?? null) ? $bucket['state'] : 'unknown');
            $timeline[] = ['at' => gmdate('c', (int) $bucket['ts']), 'state' => match ($state) {
                'up', 'ok' => 'ok', 'down', 'offline' => 'offline', 'degraded' => 'degraded', default => 'unknown'
            }];
        }

        return ['health' => ['online' => is_bool($current['online'] ?? null) ? $current['online'] : null, 'uptime_percent' => $this->number($data['uptimePct'] ?? null), 'used_memory_bytes' => $this->number($current['usedMemoryBytes'] ?? null), 'memory_limit_bytes' => $this->number($current['memoryLimitBytes'] ?? null), 'active_jobs' => $this->number($current['activeJobs'] ?? null), 'queued_jobs' => $this->number($current['queuedJobs'] ?? null), 'timeline' => $timeline], 'message' => 'Saúde do agente nas últimas 24 horas. As métricas podem abranger todos os trabalhos executados neste agente.'];
    }

    private function events(array $data, string $tenant): array
    {
        $events = [];
        $omitted = 0;
        foreach ($this->rows($data) as $row) {
            if ((string) ($row['tenantId'] ?? '') !== $tenant) {
                $omitted++;

                continue;
            }
            $status = strtoupper(is_string($row['status'] ?? null) ? $row['status'] : '');
            $events[] = ['name' => $this->text($row['name'] ?? null), 'state' => in_array($status, ['MISSED', 'LATE', 'ERROR', 'CRASHED'], true) ? $status : 'unknown', 'expected_at' => $this->date($row['expected'] ?? null), 'started_at' => $this->date($row['actualStarted'] ?? null), 'recovered_at' => $this->date($row['recoveredAt'] ?? null), 'drift_minutes' => $this->number($row['driftMin'] ?? null), 'message' => $this->text($row['errorMessage'] ?? null)];
        }

        return ['events' => $events, 'message' => 'Ocorrências abertas nas últimas 48 horas, somente deste tenant.', 'other_tenants_omitted' => $omitted > 0];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $value)) {
            return null;
        }
        try {
            $date = Carbon::parse($value);

            return $date->year >= 2000 ? $date->toIso8601String() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace(['~(https?://)[^\s/@]+:[^\s/@]+@~i', '~\b(password|senha|access_token|refresh_token|api_key|secret)\s*[=:]\s*[^\s;,]+~i', '~\beyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b~'], ['$1[protegido]@', '$1=[protegido]', '[protegido]'], $value);

        return mb_substr($value, 0, 1500);
    }
}
