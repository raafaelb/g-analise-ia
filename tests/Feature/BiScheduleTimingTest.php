<?php

namespace Tests\Feature;

use App\Services\RefreshCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiScheduleTimingTest extends TestCase
{
    #[DataProvider('dailyTriggers')]
    public function test_daily_coverage_requires_a_valid_schedule_that_reaches_every_day(array $triggers, bool $expected): void
    {
        $this->travelTo('2026-10-31T12:00:00Z');

        $this->assertSame($expected, app(RefreshCoverage::class)->daily($triggers));
    }

    public static function dailyTriggers(): array
    {
        $daily = ['type' => 'Day', 'start_when' => '03:00'];
        $hourly = ['type' => 'Hour', 'period' => 2, 'start_when' => '2026-01-01T09:00:00.000Z', 'start_time' => '06:00', 'end_time' => '22:00'];
        $minute = ['type' => 'Minute', 'period' => 5, 'start_when' => '2026-01-01T03:01:00Z', 'start_time' => '00:00', 'end_time' => '23:59'];

        return [
            'daily_without_period' => [[$daily], true],
            'daily_ignores_an_irrelevant_period' => [[[...$daily, 'period' => 2]], true],
            'daily_does_not_use_the_default_interval_window' => [[[...$daily, 'start_time' => '07:00', 'end_time' => '19:00']], true],
            'daily_missing_time' => [[['type' => 'Day', 'period' => 1]], false],
            'daily_invalid_clock' => [[[...$daily, 'start_when' => '25:90']], false],
            'daily_does_not_accept_an_absolute_anchor' => [[[...$daily, 'start_when' => '2026-01-01T03:00:00Z']], false],
            'daily_restricted_weekdays' => [[[...$daily, 'weekdays' => [1, 2, 3, 4, 5]]], false],
            'daily_all_weekdays_zero_based' => [[[...$daily, 'weekdays' => [0, 1, 2, 3, 4, 5, 6]]], true],
            'daily_all_weekdays_one_based' => [[[...$daily, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]]], true],
            'invalid_weekday_values_cannot_appear_as_full_coverage' => [[[...$daily, 'days' => [11, 12, 13, 14, 15, 16, 17]]], false],
            'expired_trigger' => [[[...$daily, 'end_when' => '2026-10-30T23:00:00Z']], false],
            'unknown_expiration' => [[[...$daily, 'end_when' => 'not-a-date']], false],
            'expiration_still_in_future' => [[[...$daily, 'end_when' => '2026-11-30T23:00:00Z']], true],
            'native_two_hour_window' => [[$hourly], true],
            'native_five_minute_window' => [[$minute], true],
            'daily_hour_interval_inside_its_window' => [[[...$hourly, 'period' => 24, 'start_when' => '2026-01-01T11:00:00Z', 'start_time' => '07:00', 'end_time' => '19:00']], true],
            'daily_hour_interval_never_reaches_its_window' => [[[...$hourly, 'period' => 24, 'start_when' => '2026-01-01T08:00:00Z', 'start_time' => '07:00', 'end_time' => '19:00']], false],
            'historical_daylight_saving_does_not_shift_the_current_interval_grid' => [[[...$hourly, 'period' => 24, 'start_when' => '2010-01-01T09:00:00Z', 'start_time' => '07:00', 'end_time' => '19:00']], false],
            'historical_daylight_saving_does_not_hide_a_current_valid_window' => [[[...$hourly, 'period' => 24, 'start_when' => '2010-01-01T22:00:00Z', 'start_time' => '19:00', 'end_time' => '19:30']], true],
            'non_divisor_hour_interval_can_skip_days_in_a_short_window' => [[[...$hourly, 'period' => 7, 'start_when' => '2026-01-01T03:00:00Z', 'start_time' => '07:00', 'end_time' => '08:00']], false],
            'non_divisor_hour_interval_with_sufficient_window' => [[[...$hourly, 'period' => 7]], true],
            'non_divisor_minute_interval_can_skip_days' => [[[...$minute, 'period' => 17, 'start_time' => '07:00', 'end_time' => '07:05']], false],
            'future_interval_anchor' => [[[...$hourly, 'start_when' => '2026-11-01T09:00:00Z']], false],
            'invalid_calendar_date_is_not_normalized_to_a_different_day' => [[[...$hourly, 'start_when' => '2026-02-30T09:00:00Z']], false],
            'invalid_timezone_offset' => [[[...$hourly, 'start_when' => '2026-01-01T09:00:00+25:00']], false],
            'ambiguous_anchor_without_timezone' => [[[...$hourly, 'start_when' => '2026-01-01T09:00:00']], false],
            'missing_interval_window' => [[['type' => 'Hour', 'period' => 2, 'start_when' => '2026-01-01T09:00:00Z']], false],
            'interval_window_in_unknown_overnight_format' => [[[...$hourly, 'start_time' => '22:00', 'end_time' => '06:00']], false],
            'interval_with_zero_period' => [[[...$minute, 'period' => 0]], false],
            'interval_with_fractional_period' => [[[...$minute, 'period' => 1.5]], false],
            'interval_longer_than_one_day' => [[[...$hourly, 'period' => 25]], false],
            'one_weekday_is_not_daily' => [[['type' => 'Week', 'period' => 0, 'start_when' => '03:00']], false],
            'seven_weekly_triggers_cover_the_whole_week' => [array_map(fn (int $day): array => ['type' => 'Week', 'period' => $day, 'start_when' => '03:00'], range(0, 6)), true],
            'duplicate_weekdays_do_not_cover_the_whole_week' => [array_fill(0, 7, ['type' => 'Week', 'period' => 1, 'start_when' => '03:00']), false],
            'monthly_is_not_daily' => [[['type' => 'Month', 'period' => 1, 'start_when' => '03:00']], false],
            'cdc_polling_does_not_guarantee_a_daily_load' => [[['type' => 'CDC', 'period' => 5, 'start_when' => '2026-01-01T09:00:00Z']], false],
            'an_unknown_trigger_does_not_hide_a_valid_daily_trigger' => [[['type' => 'FutureInternalType'], $daily], true],
            'empty_and_malformed_triggers_cannot_confirm_a_load' => [[null, 'invalid', ['type' => ['invalid']]], false],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_frequency_explains_each_native_trigger_without_confusing_its_unit(array $trigger, string $expected): void
    {
        $this->travelTo('2026-10-31T12:00:00Z');

        $this->assertSame($expected, app(RefreshCoverage::class)->frequency([$trigger]));
    }

    public static function descriptions(): array
    {
        return [
            'daily' => [['type' => 'Day', 'start_when' => '03:00'], 'Diariamente às 03:00'],
            'sunday_is_period_zero' => [['type' => 'Week', 'period' => 0, 'start_when' => '05:00'], 'Semanalmente, domingo, às 05:00'],
            'weekday_is_a_day_not_an_interval' => [['type' => 'Week', 'period' => 2, 'start_when' => '05:00'], 'Semanalmente, terça-feira, às 05:00'],
            'month_is_day_of_month_not_number_of_months' => [['type' => 'Month', 'period' => 15, 'start_when' => '05:00'], 'Mensalmente, dia 15, às 05:00'],
            'month_end_adjustment' => [['type' => 'Month', 'period' => 31, 'start_when' => '05:00'], 'Mensalmente, dia 31, às 05:00 (ajustado ao último dia nos meses mais curtos)'],
            'hour_window' => [['type' => 'Hour', 'period' => 1, 'start_when' => '2026-01-01T09:00:00Z', 'start_time' => '06:00', 'end_time' => '22:00'], 'A cada 1 hora · das 06:00 às 22:00 (Brasília)'],
            'minute_window' => [['type' => 'Minute', 'period' => 30, 'start_when' => '2026-01-01T09:00:00Z', 'start_time' => '06:00', 'end_time' => '22:00'], 'A cada 30 minutos · das 06:00 às 22:00 (Brasília)'],
            'cdc_is_conditional_and_uses_seconds' => [['type' => 'CDC', 'period' => 10], 'Quando os dados mudarem · sondagem a cada 10 segundos'],
            'unknown_type_is_not_shown_as_a_technical_error' => [['type' => 'INTERNAL-TECHNICAL-NAME'], 'Frequência a conferir'],
            'invalid_clock_does_not_look_configured' => [['type' => 'Day', 'start_when' => '28:59'], 'Horário diário a conferir'],
        ];
    }

    public function test_multiple_triggers_are_all_visible_and_private_cdc_configuration_is_not_exposed(): void
    {
        $value = app(RefreshCoverage::class)->frequency([['type' => 'Day', 'start_when' => '03:00'], ['type' => 'Day', 'start_when' => '15:00'], ['type' => 'CDC', 'period' => 10, 'config' => ['credential' => 'PRIVATE-CREDENTIAL', 'sql' => 'PRIVATE-SOURCE-QUERY']]]);

        $this->assertSame('Diariamente às 03:00; Diariamente às 15:00; Quando os dados mudarem · sondagem a cada 10 segundos', $value);
    }
}
