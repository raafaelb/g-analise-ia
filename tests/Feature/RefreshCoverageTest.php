<?php

namespace Tests\Feature;

use App\Services\RefreshCoverage;
use Tests\TestCase;

class RefreshCoverageTest extends TestCase
{
    public function test_shared_sources_are_deduplicated_and_more_frequent_agendas_preserved(): void
    {
        $report = app(RefreshCoverage::class)->assess(['tables' => [['tableId' => 1], ['tableId' => 2], ['tableId' => 3]]], [1 => ['originFlowId' => 10], 2 => ['originFlowId' => 10], 3 => ['originFlowId' => 20]], [10 => ['deskId' => 2, 'load_type' => 'Total'], 20 => ['deskId' => 2, 'load_type' => 'Temporal']], [100 => ['ativo' => true, 'flows' => [['flow_etl_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'period' => 1, 'start_when' => '03:00']]], 200 => ['ativo' => true, 'flows' => [['flow_etl_id' => 20, 'context' => 'Temporal:Days:2']], 'triggers' => [['type' => 'Minute', 'period' => 10]]]]);
        $this->assertCount(2, $report['sources']);
        $this->assertCount(2, $report['sources'][0]['tables']);
        $this->assertTrue($report['configured']);
        $this->assertFalse($report['verified']);
        $this->assertSame([], $report['missing_flow_ids']);
        $this->assertSame('preserve_existing', $report['sources'][1]['recommendation']);
    }

    public function test_inactive_agenda_and_keysweep_never_count_as_successful_refresh_coverage(): void
    {
        $report = app(RefreshCoverage::class)->assess(['tables' => [['tableId' => 1]]], [1 => ['originFlowId' => 10]], [10 => ['deskId' => 2, 'load_type' => 'Incremental']], [1 => ['ativo' => false, 'flows' => [['flow_etl_id' => 10, 'context' => 'Incremental']], 'triggers' => [['type' => 'Day', 'period' => 1]]], 2 => ['ativo' => true, 'flows' => [['flow_etl_id' => 10, 'context' => 'KeySweep']], 'triggers' => [['type' => 'Minute', 'period' => 5]]]]);
        $this->assertFalse($report['configured']);
        $this->assertSame(['10'], $report['missing_flow_ids']);
        $this->assertSame('review_existing_schedule', $report['sources'][0]['recommendation']);
    }

    public function test_private_source_requires_publication_even_if_an_active_schedule_mentions_it(): void
    {
        $report = app(RefreshCoverage::class)->assess(['tables' => [['tableId' => 1]]], [1 => ['originFlowId' => 10]], [10 => ['deskId' => null, 'load_type' => 'Total']], [1 => ['ativo' => true, 'flows' => [['flow_etl_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'period' => 1]]]]);
        $this->assertFalse($report['configured']);
        $this->assertSame('request_source_publication', $report['sources'][0]['recommendation']);
    }

    public function test_missing_source_metadata_is_explicit_and_never_declared_complete(): void
    {
        $report = app(RefreshCoverage::class)->assess(['tables' => [['tableId' => 1], ['tableId' => 2]]], [1 => ['nome' => 'Manual']], [], []);
        $this->assertCount(2, $report['unresolved_tables']);
        $this->assertFalse($report['configured']);
        $this->assertFalse($report['verified']);
    }

    public function test_cadence_rejects_future_weekly_and_partial_week_schedules(): void
    {
        $coverage = app(RefreshCoverage::class);
        $this->assertFalse($coverage->daily([['type' => 'Day', 'period' => 2]]));
        $this->assertFalse($coverage->daily([['type' => 'Day', 'period' => 1, 'weekdays' => [1, 2, 3, 4, 5]]]));
        $this->assertFalse($coverage->daily([['type' => 'Day', 'period' => 1, 'start_when' => now()->addMonth()->toIso8601String()]]));
        $this->assertTrue($coverage->daily([['type' => 'Hour', 'period' => 2, 'start_when' => '2020-01-01T00:00:00Z']]));
    }

    public function test_load_context_is_cross_checked_against_the_source_contract(): void
    {
        $coverage = app(RefreshCoverage::class);
        $this->assertFalse($coverage->contextMatches('Total', 'Temporal:Days:2'));
        $this->assertTrue($coverage->contextMatches('Temporal', 'Temporal:Days:2'));
        $this->assertTrue($coverage->contextMatches('Incremental', 'Incremental'));
        $this->assertFalse($coverage->contextMatches('Incremental', 'KeySweep'));
    }

    private function completedLog(): array
    {
        return ['ativo' => true, 'last_log' => ['scheduleId' => 100, 'run_id' => 'new-run', 'startedWhen' => now()->subMinutes(2)->toIso8601String(), 'finishedWhen' => now()->subMinute()->toIso8601String(), 'flows' => [['FlowID' => 10, 'Success' => true, 'FinishedWhen' => now()->subMinute()->toIso8601String(), 'RowsIn' => 14, 'RowsOut' => 14]]]];
    }

    public function test_old_or_different_finished_run_is_never_accepted_as_the_requested_test(): void
    {
        $coverage = app(RefreshCoverage::class);
        $log = $this->completedLog();
        $this->assertFalse($coverage->execution($log, 100, [10], 'other-run', now()->subMinutes(3)->toIso8601String())['verified']);
        $this->assertSame('stale_execution', $coverage->execution($log, 100, [10], 'new-run', now()->toIso8601String())['status']);
    }

    public function test_queued_running_or_partial_flow_is_not_a_successful_test(): void
    {
        $coverage = app(RefreshCoverage::class);
        $log = $this->completedLog();
        $log['last_log']['finishedWhen'] = null;
        $this->assertSame('running', $coverage->execution($log, 100, [10], 'new-run', now()->subMinutes(3)->toIso8601String())['status']);
        $log = $this->completedLog();
        $this->assertSame('incomplete', $coverage->execution($log, 100, [10, 20], 'new-run', now()->subMinutes(3)->toIso8601String())['status']);
        $log['last_log']['flows'][0]['Success'] = false;
        $this->assertSame('failed', $coverage->execution($log, 100, [10], 'new-run', now()->subMinutes(3)->toIso8601String())['status']);
    }

    public function test_native_terminal_success_is_correlated_to_run_schedule_time_and_all_sources(): void
    {
        $coverage = app(RefreshCoverage::class);
        $log = $this->completedLog();
        $result = $coverage->execution($log, 100, [10], 'new-run', now()->subMinutes(3)->toIso8601String());
        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['verified']);
        $this->assertFalse($coverage->execution($log, 999, [10], 'new-run', now()->subMinutes(3)->toIso8601String())['verified']);
        $observed = $coverage->execution($log, 100, [10]);
        $this->assertSame('observed_success', $observed['status']);
        $this->assertFalse($observed['verified']);
    }

    public function test_history_correlation_binds_only_one_new_execution_with_exact_sources(): void
    {
        $request = ['schedule_id' => 100, 'requested_at' => now()->subMinute()->toIso8601String(), 'expected_flow_ids' => [10], 'run_id' => null];
        $history = ['lista' => [['execution_id' => 'old', 'schedule_id' => 100, 'start_time' => now()->subHour()->toIso8601String(), 'flows' => [['flow_id' => 10]]], ['execution_id' => 'new', 'schedule_id' => 100, 'start_time' => now()->subSeconds(30)->toIso8601String(), 'flows' => [['flow_id' => 10]]]]];
        $result = app(RefreshCoverage::class)->correlate($history, $request);
        $this->assertSame('new', $result['run_id']);
        $this->assertSame('unique_history', $result['correlation']);
    }

    public function test_multiple_new_runs_or_wrong_sources_remain_unverified(): void
    {
        $request = ['schedule_id' => 100, 'requested_at' => now()->subMinute()->toIso8601String(), 'expected_flow_ids' => [10], 'run_id' => null];
        $row = ['execution_id' => 'one', 'schedule_id' => 100, 'start_time' => now()->subSeconds(30)->toIso8601String(), 'flows' => [['flow_id' => 10]]];
        $result = app(RefreshCoverage::class)->correlate(['lista' => [$row, [...$row, 'execution_id' => 'two']]], $request);
        $this->assertNull($result['run_id']);
        $this->assertSame('ambiguous', $result['correlation']);
        $result = app(RefreshCoverage::class)->correlate(['lista' => [[...$row, 'flows' => [['flow_id' => 20]]]]], $request);
        $this->assertNull($result['run_id']);
    }

    public function test_configuration_signature_changes_when_schedule_or_source_changes(): void
    {
        $coverage = app(RefreshCoverage::class);
        $schedule = ['ativo' => true, 'flows' => [['flow_etl_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'period' => 1]]];
        $documents = ['flow:10' => ['load_type' => 'Total', 'nodes' => [['kind' => 'ExtractPostgreSQL', 'sql' => 'SELECT 1']]]];
        $signature = $coverage->signature($schedule, $documents);
        $documents['flow:10']['nodes'][0]['sql'] = 'SELECT 2';
        $this->assertNotSame($signature, $coverage->signature($schedule, $documents));
        $this->assertNotSame($signature, $coverage->signature([...$schedule, 'ativo' => false], $documents));
    }

    public function test_invalid_flow_completion_timestamp_does_not_verify_a_test(): void
    {
        $log = $this->completedLog();
        $log['last_log']['flows'][0]['FinishedWhen'] = 'invalid';
        $this->assertFalse(app(RefreshCoverage::class)->execution($log, 100, [10], 'new-run', now()->subMinutes(3)->toIso8601String())['verified']);
    }
}
