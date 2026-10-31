<?php

namespace Tests\Feature;

use App\Services\ScheduleChanges;
use Tests\TestCase;

class ScheduleChangesTest extends TestCase
{
    private function active(int $flow, string $context = 'Total'): array
    {
        return ['ativo' => true, 'flows' => [['flow_etl_id' => $flow, 'context' => $context]], 'triggers' => [['type' => 'Minute', 'period' => 5, 'start_when' => '2020-01-01T00:00:00Z', 'start_time' => '00:00', 'end_time' => '23:59']]];
    }

    public function test_new_active_schedule_cannot_duplicate_a_shared_source(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reutilize a agenda existente');
        app(ScheduleChanges::class)->assertNoAdditionalLoad('2', $this->active(10), ['1' => $this->active(10)]);
    }

    public function test_resuming_an_inactive_duplicate_also_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ScheduleChanges::class)->assertNoAdditionalLoad('2', $this->active(10), ['1' => $this->active(10), '2' => [...$this->active(10), 'ativo' => false]]);
    }

    public function test_inactive_plan_and_preserving_the_existing_schedule_do_not_create_a_second_load(): void
    {
        $s = app(ScheduleChanges::class);
        $s->assertNoAdditionalLoad('2', [...$this->active(10), 'ativo' => false], ['1' => $this->active(10)]);
        $s->assertNoAdditionalLoad('1', $this->active(10), ['1' => $this->active(10)]);
        $this->addToAssertionCount(1);
    }

    public function test_keysweep_is_not_mistaken_for_the_data_load(): void
    {
        app(ScheduleChanges::class)->assertNoAdditionalLoad('2', $this->active(10), ['1' => $this->active(10, 'KeySweep')]);
        $this->addToAssertionCount(1);
    }

    public function test_new_binding_to_an_existing_schedule_is_checked_for_duplicates(): void
    {
        $candidate = $this->active(10);
        $candidate['flows'][] = ['flow_etl_id' => 20, 'context' => 'Total'];
        $this->expectException(\RuntimeException::class);
        app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $this->active(10), '2' => $this->active(20)]);
    }

    public function test_existing_five_minute_load_cannot_be_reduced_to_daily(): void
    {
        $candidate = [...$this->active(10), 'triggers' => [['type' => 'Day', 'period' => 1]]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Preserve a agenda');
        app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $this->active(10)]);
    }

    public function test_existing_daily_or_more_frequent_load_cannot_be_disabled_or_lose_its_source(): void
    {
        $before = $this->active(10);
        foreach ([[...$before, 'ativo' => false], [...$before, 'flows' => []]] as $candidate) {
            try {
                app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $before]);
                $this->fail('Uma fonte perdeu sua atualização automática.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Preserve a agenda', $e->getMessage());
            }
        }
    }

    public function test_faster_and_equivalent_intervals_preserve_current_loads(): void
    {
        $before = [...$this->active(10), 'triggers' => [['type' => 'Hour', 'period' => 1, 'start_when' => '2020-01-01T00:00:00Z', 'start_time' => '00:00', 'end_time' => '23:59']]];
        foreach ([30, 60] as $minutes) {
            $candidate = [...$before, 'nome' => 'Novo nome', 'triggers' => [['period' => (string) $minutes, 'start_when' => '2020-01-01T00:00:00Z', 'type' => 'Minute', 'start_time' => '00:00', 'end_time' => '23:59']]];
            app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $before]);
        }
        $this->addToAssertionCount(1);
    }

    public function test_same_interval_cannot_hide_new_weekday_or_future_start_restrictions(): void
    {
        $before = $this->active(10);
        foreach ([['weekdays' => [1]], ['start_when' => '2099-01-01T00:00:00Z'], ['end_when' => '2020-01-01T00:00:00Z']] as $restriction) {
            $candidate = [...$before, 'triggers' => [[...$before['triggers'][0], ...$restriction]]];
            try {
                app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $before]);
                $this->fail('Restrição nova não preserva a agenda existente.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Preserve a agenda', $e->getMessage());
            }
        }
    }

    public function test_multiple_daily_times_cannot_silently_be_replaced_by_only_one(): void
    {
        $before = [...$this->active(10), 'timezone' => 'America/Sao_Paulo', 'triggers' => [['type' => 'Day', 'period' => 1, 'start_when' => '03:00'], ['type' => 'Day', 'period' => 1, 'start_when' => '15:00']]];
        $candidate = [...$before, 'triggers' => [$before['triggers'][0]]];
        $this->expectException(\RuntimeException::class);
        app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $before]);
    }

    public function test_preserving_trigger_order_does_not_require_preserving_yaml_key_order(): void
    {
        $before = [...$this->active(10), 'triggers' => [['type' => 'Day', 'period' => 1, 'start_when' => '03:00'], ['type' => 'Day', 'period' => 1, 'start_when' => '15:00']]];
        $candidate = [...$before, 'triggers' => [['start_when' => '15:00', 'period' => 1, 'type' => 'Day'], ['period' => 1, 'type' => 'Day', 'start_when' => '03:00']]];
        app(ScheduleChanges::class)->assertNoAdditionalLoad('1', $candidate, ['1' => $before]);
        $this->addToAssertionCount(1);
    }

    public function test_an_existing_daily_load_without_a_period_cannot_be_disabled(): void
    {
        $before = [...$this->active(10), 'triggers' => [['type' => 'Day', 'start_when' => '03:00']]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Preserve a agenda diária');
        app(ScheduleChanges::class)->assertNoAdditionalLoad('1', [...$before, 'ativo' => false], ['1' => $before]);
    }
}
