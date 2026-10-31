<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class BiLoadResults
{
    public function info(array $data, string $scheduleId, array $bindings, ?string $runId = null, ?string $requestedAt = null): array
    {
        $payload = $data['payload'] ?? $data;
        $payload = is_array($payload) ? ($payload['body'] ?? $payload) : [];
        $log = is_array($payload) ? ($payload['last_log'] ?? null) : null;
        $result = $this->empty('awaiting_history');
        if (! is_array($log) || ! is_string($log['run_id'] ?? null) || $log['run_id'] === '') {
            return $result;
        }
        if ((isset($payload['id']) && (string) $payload['id'] !== $scheduleId) || (string) ($log['scheduleId'] ?? '') !== $scheduleId) {
            return [...$result, 'status' => 'invalid_schedule'];
        }
        if ($runId !== null && ! hash_equals($runId, $log['run_id'])) {
            return [...$result, 'status' => 'awaiting_requested_run'];
        }
        $result['run_id'] = $log['run_id'];
        $started = $this->instant($log['startedWhen'] ?? null);
        $finished = $this->instant($log['finishedWhen'] ?? null);
        if (! $started || $started->isFuture() || ($requestedAt !== null && ! $this->afterRequest($started, $requestedAt))) {
            return [...$result, 'status' => 'invalid_timestamps'];
        }
        $result['started_at'] = $started->toIso8601String();
        if (! empty($log['finishedWhen']) && (! $finished || $finished->lessThan($started) || $finished->isFuture())) {
            return [...$result, 'status' => 'invalid_timestamps'];
        }
        $result['finished_at'] = $finished?->toIso8601String();
        $rows = $log['flows'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 300) {
            return [...$result, 'status' => 'invalid_flows'];
        }
        $actual = [];
        $failed = false;
        $incomplete = false;
        $allowedIds = array_column($bindings, 'flow_id');
        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->identifier($row['FlowID'] ?? null) || ! is_string($row['Context'] ?? null)) {
                $incomplete = true;

                continue;
            }
            $id = (string) $row['FlowID'];
            if (! in_array($id, $allowedIds, true)) {
                return [...$result, 'status' => 'unexpected_sources', 'flows' => []];
            }
            $context = $row['Context'];
            $actual[] = [$id, $context];
            $flowStart = $this->instant($row['StartedWhen'] ?? null);
            $flowEnd = $this->instant($row['FinishedWhen'] ?? null);
            $validStart = $flowStart !== null && $flowStart->greaterThanOrEqualTo($started) && ! $flowStart->isFuture();
            $done = $validStart && $flowEnd !== null && $flowEnd->greaterThanOrEqualTo($flowStart) && ! $flowEnd->isFuture() && ($finished === null || $flowEnd->lessThanOrEqualTo($finished));
            $success = is_bool($row['Success'] ?? null) ? $row['Success'] : null;
            $failed = $failed || ($done && $success === false);
            $incomplete = $incomplete || ! $done || $success === null;
            $result['flows'][] = ['flow_id' => $id, 'context' => $context, 'status' => $done ? ($success === true ? 'succeeded' : ($success === false ? 'failed' : 'unknown')) : ($validStart ? 'running' : 'pending'), 'success' => $success, 'finished' => $done, 'started_at' => $flowStart?->toIso8601String(), 'finished_at' => $flowEnd?->toIso8601String(), 'rows_in' => $this->count($row['RowsIn'] ?? null), 'rows_out' => $this->count($row['RowsOut'] ?? null), 'duration_ms' => $this->count($row['Duration'] ?? null), 'message' => $this->message($row['Message'] ?? null)];
        }
        if ($finished === null) {
            return [...$result, 'status' => 'running', 'evidence' => 'live_schedule_log'];
        }
        if (! $this->sameBindings($bindings, $actual, true)) {
            return [...$result, 'status' => 'incomplete', 'evidence' => 'live_schedule_log'];
        }
        if ($failed || ($log['Success'] ?? null) === false) {
            return [...$result, 'status' => 'failed', 'evidence' => 'live_schedule_log'];
        }
        if ($incomplete || $actual === []) {
            return [...$result, 'status' => 'incomplete', 'evidence' => 'live_schedule_log'];
        }

        return [...$result, 'status' => 'succeeded', 'verified' => true, 'evidence' => 'live_schedule_log'];
    }

    public function history(array $row, string $scheduleId, array $bindings, ?string $runId = null, ?string $requestedAt = null): array
    {
        $result = $this->empty('awaiting_history');
        if ((string) ($row['schedule_id'] ?? '') !== $scheduleId) {
            return [...$result, 'status' => 'invalid_schedule'];
        }
        if (! is_string($row['execution_id'] ?? null) || $row['execution_id'] === '' || ($runId !== null && ! hash_equals($runId, $row['execution_id']))) {
            return [...$result, 'status' => 'awaiting_requested_run'];
        }
        $result['run_id'] = $row['execution_id'];
        $started = $this->instant($row['start_time'] ?? null);
        $finished = $this->instant($row['end_time'] ?? null);
        if (! $started || $started->isFuture() || ($requestedAt !== null && ! $this->afterRequest($started, $requestedAt)) || (! empty($row['end_time']) && (! $finished || $finished->lessThan($started) || $finished->isFuture()))) {
            return [...$result, 'status' => 'invalid_timestamps'];
        }
        $result['started_at'] = $started->toIso8601String();
        $result['finished_at'] = $finished?->toIso8601String();
        if ($finished === null) {
            return [...$result, 'status' => 'running', 'evidence' => 'schedule_history'];
        }
        $rows = $row['flows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 300) {
            return [...$result, 'status' => 'invalid_flows'];
        }
        $actual = [];
        $failed = false;
        $incomplete = false;
        $allowedIds = array_column($bindings, 'flow_id');
        foreach ($rows as $flow) {
            if (! is_array($flow) || ! $this->identifier($flow['flow_id'] ?? null)) {
                $incomplete = true;

                continue;
            }
            $id = (string) $flow['flow_id'];
            if (! in_array($id, $allowedIds, true)) {
                return [...$result, 'status' => 'unexpected_sources', 'flows' => []];
            }
            $actual[] = [$id, null];
            $success = match ($flow['success'] ?? null) {
                true, 1 => true, false, 0 => false, default => null
            };
            $failed = $failed || $success === false;
            $incomplete = $incomplete || $success === null;
            $result['flows'][] = ['flow_id' => $id, 'context' => null, 'status' => $success === true ? 'succeeded' : ($success === false ? 'failed' : 'unknown'), 'success' => $success, 'finished' => $success !== null, 'started_at' => null, 'finished_at' => null, 'rows_in' => null, 'rows_out' => null, 'duration_ms' => null, 'message' => $this->message($flow['message'] ?? null)];
        }
        $status = is_string($row['status'] ?? null) ? strtolower($row['status']) : '';
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return [...$result, 'status' => 'cancelled', 'evidence' => 'schedule_history'];
        }
        if ($failed || in_array($status, ['error', 'failed', 'failure'], true)) {
            return [...$result, 'status' => 'failed', 'evidence' => 'schedule_history'];
        }
        if (! $this->sameBindings($bindings, $actual, false) || $incomplete || $actual === []) {
            return [...$result, 'status' => 'incomplete', 'evidence' => 'schedule_history'];
        }

        return [...$result, 'status' => 'succeeded', 'verified' => true, 'evidence' => 'schedule_history'];
    }

    public function correlate(array $history, array $info, array $request): array
    {
        $expectedId = $request['run_id'] ?? null;
        if (is_string($expectedId) && $expectedId !== '') {
            return ['run_id' => $expectedId, 'correlation' => $request['correlation'] ?? 'response_id'];
        }
        $rows = $history['lista'] ?? null;
        $payload = $info['payload'] ?? $info;
        $log = is_array($payload) ? ($payload['last_log'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) >= 1000) {
            return ['run_id' => null, 'correlation' => 'history_unavailable'];
        }
        $known = $request['before_run_ids'] ?? [];
        $bindings = $request['bindings'] ?? [];
        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row) || in_array($row['execution_id'] ?? '', $known, true)) {
                continue;
            }
            $parsed = $this->history($row, (string) $request['schedule_id'], $bindings, requestedAt: $request['requested_at']);
            if ($parsed['run_id'] !== null && $this->inCorrelationWindow($parsed, $request) && ! in_array($parsed['status'], ['invalid_schedule', 'invalid_timestamps', 'unexpected_sources', 'incomplete', 'invalid_flows'], true)) {
                $candidates[] = $parsed['run_id'];
            }
        }
        if (is_array($log) && ! in_array($log['run_id'] ?? '', $known, true)) {
            $parsed = $this->info($info, (string) $request['schedule_id'], $bindings, requestedAt: $request['requested_at']);
            if ($parsed['run_id'] !== null && $this->inCorrelationWindow($parsed, $request) && in_array($parsed['status'], ['running', 'succeeded', 'failed'], true)) {
                $candidates[] = $parsed['run_id'];
            }
        }
        $candidates = array_values(array_unique($candidates));

        return ['run_id' => count($candidates) === 1 ? $candidates[0] : null, 'correlation' => count($candidates) === 1 ? 'unique_after_request' : (count($candidates) > 1 ? 'ambiguous' : 'awaiting_history')];
    }

    private function inCorrelationWindow(array $parsed, array $request): bool
    {
        $requested = $this->instant($request['requested_at'] ?? null);
        $started = $this->instant($parsed['started_at'] ?? null);

        return $requested !== null && $started !== null && $started->lessThanOrEqualTo($requested->copy()->addMinutes(5));
    }

    public function message(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace(['~(https?://)[^\s/@]+:[^\s/@]+@~i', '~\b(password|senha|access_token|refresh_token|api_key|secret)\s*[=:]\s*[^\s;,]+~i', '~\beyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b~'], ['$1[protegido]@', '$1=[protegido]', '[protegido]'], $value);

        return mb_substr($value, 0, 1500);
    }

    private function empty(string $status): array
    {
        return ['status' => $status, 'verified' => false, 'run_id' => null, 'started_at' => null, 'finished_at' => null, 'flows' => [], 'evidence' => null];
    }

    private function sameBindings(array $bindings, array $actual, bool $contexts): bool
    {
        $expected = array_map(fn (array $binding): string => json_encode([(string) $binding['flow_id'], $contexts ? $binding['context'] : null], JSON_THROW_ON_ERROR), $bindings);
        $observed = array_map(fn (array $binding): string => json_encode($binding, JSON_THROW_ON_ERROR), $actual);
        sort($expected);
        sort($observed);

        return $expected !== [] && $expected === $observed;
    }

    private function afterRequest(Carbon $started, string $requestedAt): bool
    {
        $requested = $this->instant($requestedAt);

        return $requested !== null && $started->greaterThanOrEqualTo($requested->startOfSecond());
    }

    private function instant(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', substr($value, 0, 10)));
        if (! checkdate($month, $day, $year) || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59 || (int) substr($value, 17, 2) > 59) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function identifier(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) === 1;
    }

    private function count(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && is_finite((float) $value) ? $value : null;
    }
}
