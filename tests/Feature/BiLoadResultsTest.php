<?php

namespace Tests\Feature;

use App\Services\BiLoadResults;
use Tests\TestCase;

class BiLoadResultsTest extends TestCase
{
    private function bindings(): array
    {
        return [['flow_id' => '10', 'context' => 'Total'], ['flow_id' => '11', 'context' => 'Incremental']];
    }

    private function info(): array
    {
        return ['payload' => ['id' => 20, 'last_log' => ['scheduleId' => 20, 'run_id' => 'new-run', 'startedWhen' => '2026-10-31T06:00:01.2763789+00:00', 'finishedWhen' => '2026-10-31T06:00:10.2178993+00:00', 'flows' => [['FlowID' => 10, 'Context' => 'Total', 'Success' => true, 'StartedWhen' => '2026-10-31T06:00:01.4752583+00:00', 'FinishedWhen' => '2026-10-31T06:00:05.9702068+00:00', 'RowsIn' => 14, 'RowsOut' => 14, 'Duration' => 4494], ['FlowID' => 11, 'Context' => 'Incremental', 'Success' => true, 'StartedWhen' => '2026-10-31T06:00:06Z', 'FinishedWhen' => '2026-10-31T06:00:09Z']]]]];
    }

    private function history(): array
    {
        return ['execution_id' => 'new-run', 'schedule_id' => 20, 'start_time' => '2026-10-31T06:00:01.000Z', 'end_time' => '2026-10-31T06:00:10.000Z', 'status' => null, 'flows' => [['flow_id' => 10, 'success' => 1], ['flow_id' => 11, 'success' => 1]]];
    }

    public function test_native_live_log_confirms_every_binding_and_preserves_rows_without_exposing_raw_data(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');

        $result = app(BiLoadResults::class)->info($this->info(), '20', $this->bindings(), 'new-run', '2026-10-31T06:00:00Z');

        $this->assertSame('succeeded', $result['status']);
        $this->assertTrue($result['verified']);
        $this->assertSame('live_schedule_log', $result['evidence']);
        $this->assertSame(14, $result['flows'][0]['rows_out']);
        $this->assertSame(4494, $result['flows'][0]['duration_ms']);
        $this->assertNull($result['flows'][1]['rows_out']);
    }

    public function test_a_finished_scheduler_cannot_prove_a_flow_that_is_still_running(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['last_log']['flows'][1]['FinishedWhen'] = null;

        $result = app(BiLoadResults::class)->info($info, '20', $this->bindings(), 'new-run');

        $this->assertSame('incomplete', $result['status']);
        $this->assertFalse($result['verified']);
        $this->assertSame('running', $result['flows'][1]['status']);
    }

    public function test_unfinished_parent_stays_running_even_when_all_flow_flags_look_successful(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['last_log']['finishedWhen'] = null;

        $result = app(BiLoadResults::class)->info($info, '20', $this->bindings());

        $this->assertSame('running', $result['status']);
        $this->assertFalse($result['verified']);
    }

    public function test_old_different_and_foreign_executions_cannot_confirm_the_current_request(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $parser = app(BiLoadResults::class);

        $this->assertSame('awaiting_requested_run', $parser->info($this->info(), '20', $this->bindings(), 'another-run')['status']);
        $this->assertSame('invalid_schedule', $parser->info($this->info(), '99', $this->bindings())['status']);
        $this->assertSame('invalid_timestamps', $parser->info($this->info(), '20', $this->bindings(), requestedAt: '2026-10-31T06:00:30Z')['status']);
    }

    public function test_source_context_and_multiplicity_are_required_including_deletion_sync(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['last_log']['flows'] = [$info['payload']['last_log']['flows'][1]];
        $bindings = [['flow_id' => '11', 'context' => 'Incremental'], ['flow_id' => '11', 'context' => 'KeySweep']];

        $result = app(BiLoadResults::class)->info($info, '20', $bindings);

        $this->assertSame('incomplete', $result['status']);
        $this->assertFalse($result['verified']);
        $info['payload']['last_log']['flows'][] = [...$info['payload']['last_log']['flows'][0], 'Context' => 'KeySweep'];
        $this->assertTrue(app(BiLoadResults::class)->info($info, '20', $bindings)['verified']);
    }

    public function test_a_flow_outside_the_authorized_plan_is_not_exposed_or_verified(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['last_log']['flows'][] = ['FlowID' => 999, 'Context' => 'Total', 'RowsOut' => 999999, 'Message' => 'another tenant value'];

        $result = app(BiLoadResults::class)->info($info, '20', $this->bindings());

        $this->assertSame('unexpected_sources', $result['status']);
        $this->assertSame([], $result['flows']);
        $this->assertFalse($result['verified']);
    }

    public function test_terminal_native_history_can_confirm_an_older_run_without_inventing_row_counts(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');

        $result = app(BiLoadResults::class)->history($this->history(), '20', $this->bindings(), 'new-run', '2026-10-31T06:00:00Z');

        $this->assertSame('succeeded', $result['status']);
        $this->assertTrue($result['verified']);
        $this->assertSame('schedule_history', $result['evidence']);
        $this->assertNull($result['flows'][0]['rows_out']);
        $this->assertNull($result['flows'][0]['finished_at']);
    }

    public function test_missing_unknown_and_failed_source_outcomes_never_become_successful_history(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $parser = app(BiLoadResults::class);
        $missing = $this->history();
        array_pop($missing['flows']);
        $unknown = $this->history();
        $unknown['flows'][1]['success'] = 'true';
        $failed = $this->history();
        $failed['flows'][1]['success'] = 0;

        $this->assertSame('incomplete', $parser->history($missing, '20', $this->bindings())['status']);
        $this->assertSame('incomplete', $parser->history($unknown, '20', $this->bindings())['status']);
        $this->assertSame('failed', $parser->history($failed, '20', $this->bindings())['status']);
    }

    public function test_cancelled_and_failed_parent_history_override_successful_flags(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $row = $this->history();

        $this->assertSame('cancelled', app(BiLoadResults::class)->history([...$row, 'status' => 'cancelled'], '20', $this->bindings())['status']);
        $this->assertSame('failed', app(BiLoadResults::class)->history([...$row, 'status' => 'error'], '20', $this->bindings())['status']);
    }

    public function test_baseline_runs_are_excluded_and_two_new_candidates_remain_ambiguous(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $request = ['schedule_id' => '20', 'requested_at' => '2026-10-31T06:00:00Z', 'before_run_ids' => ['baseline'], 'bindings' => $this->bindings()];
        $rows = [[...$this->history(), 'execution_id' => 'baseline'], $this->history()];
        $parser = app(BiLoadResults::class);

        $this->assertSame(['run_id' => 'new-run', 'correlation' => 'unique_after_request'], $parser->correlate(['lista' => $rows], $this->info(), $request));
        $rows[] = [...$this->history(), 'execution_id' => 'second-new'];
        $this->assertSame(['run_id' => null, 'correlation' => 'ambiguous'], $parser->correlate(['lista' => $rows], $this->info(), $request));
    }

    public function test_a_response_run_id_is_preserved_when_other_automatic_runs_exist(): void
    {
        $request = ['run_id' => 'explicit-request', 'schedule_id' => '20'];

        $this->assertSame(['run_id' => 'explicit-request', 'correlation' => 'response_id'], app(BiLoadResults::class)->correlate([], [], $request));
    }

    public function test_provider_errors_redact_credentials_and_bound_the_message(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['last_log']['flows'][1] += ['Message' => 'Cannot connect https://login:secret@example.test password=private refresh_token=hidden'];
        $info['payload']['last_log']['flows'][1]['Success'] = false;

        $result = app(BiLoadResults::class)->info($info, '20', $this->bindings());

        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString('password=private', $result['flows'][1]['message']);
        $this->assertStringNotContainsString('refresh_token=hidden', $result['flows'][1]['message']);
        $this->assertStringNotContainsString('login:secret', $result['flows'][1]['message']);
    }

    public function test_an_invalid_calendar_date_or_foreign_payload_cannot_prove_success(): void
    {
        $this->travelTo('2026-10-31T06:01:00Z');
        $info = $this->info();
        $info['payload']['id'] = 999;
        $this->assertSame('invalid_schedule', app(BiLoadResults::class)->info($info, '20', $this->bindings())['status']);
        $info = $this->info();
        $info['payload']['last_log']['startedWhen'] = '2026-02-30T06:00:00Z';

        $result = app(BiLoadResults::class)->info($info, '20', $this->bindings());

        $this->assertSame('invalid_timestamps', $result['status']);
        $this->assertFalse($result['verified']);
    }

    public function test_a_later_automatic_run_is_not_bound_to_an_unidentified_manual_request(): void
    {
        $this->travelTo('2026-10-31T07:00:00Z');
        $row = $this->history();
        $row['start_time'] = '2026-10-31T06:15:00Z';
        $row['end_time'] = '2026-10-31T06:16:00Z';
        $request = ['schedule_id' => '20', 'requested_at' => '2026-10-31T06:00:00Z', 'bindings' => $this->bindings(), 'before_run_ids' => [], 'run_id' => null];

        $result = app(BiLoadResults::class)->correlate(['lista' => [$row]], [], $request);

        $this->assertNull($result['run_id']);
        $this->assertSame('awaiting_history', $result['correlation']);
        $direct = app(BiLoadResults::class)->history($row, '20', $this->bindings(), 'new-run', $request['requested_at']);
        $this->assertTrue($direct['verified']);
    }
}
