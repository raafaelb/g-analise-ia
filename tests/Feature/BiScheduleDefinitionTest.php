<?php

namespace Tests\Feature;

use App\Services\BiScheduleDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiScheduleDefinitionTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return [...['action' => 'create_schedule', 'request_id' => (string) Str::uuid(), 'name' => 'Carga comercial', 'agent' => 5, 'flows' => [['flow_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'time' => '03:00']]], ...$changes];
    }

    private function source(): array
    {
        return ['nome' => 'Carga existente', 'ativo' => true, 'tokenId' => 5, 'timezone' => 'America/Sao_Paulo', 'flows' => [['flow_etl_id' => 10, 'context' => 'Total', 'extra' => (object) ['keep' => true]], ['flow_etl_id' => 11, 'context' => 'Incremental']], 'triggers' => [['type' => 'Day', 'period' => 1, 'start_when' => '03:00', 'start_time' => '07:00', 'end_time' => '19:00', 'native_option' => (object) ['keep' => true]]], 'sendmail_missed' => true, 'sendmail_missed_window' => 30, 'sendmail_error' => true, 'sendmail_emails' => ['alerts@example.test'], 'future_configuration' => (object) []];
    }

    private function update(array $changes, ?array $source = null): array
    {
        return ['action' => 'update_schedule', 'request_id' => (string) Str::uuid(), 'resource' => 'schedule:20', 'version' => app(BiScheduleDefinition::class)->version($source ?? $this->source()), ...$changes];
    }

    public function test_a_new_schedule_is_inactive_until_activation_is_explicitly_requested(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input());

        $body = $definitions->apply($definitions->defaults(), $data);

        $this->assertSame(false, $body['ativo']);
        $this->assertSame('America/Sao_Paulo', $body['timezone']);
        $this->assertSame([['flow_etl_id' => 10, 'context' => 'Total']], $body['flows']);
        $this->assertSame([['type' => 'Day', 'start_when' => '03:00']], $body['triggers']);
    }

    #[DataProvider('requiredCreateFields')]
    public function test_missing_create_fields_are_rejected_before_building_a_candidate(string $field): void
    {
        $input = $this->input();
        unset($input[$field]);

        $this->expectException(ValidationException::class);
        app(BiScheduleDefinition::class)->normalize($input);
    }

    public static function requiredCreateFields(): array
    {
        return ['name' => ['name'], 'agent' => ['agent'], 'flows' => ['flows'], 'triggers' => ['triggers']];
    }

    public function test_a_new_schedule_cannot_reuse_an_existing_resource_reference(): void
    {
        $this->expectException(ValidationException::class);
        app(BiScheduleDefinition::class)->normalize($this->input(['resource' => 'schedule:20']));
    }

    public function test_renaming_preserves_notification_settings_and_unknown_native_fields(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $before = $this->source();
        $data = $definitions->normalize($this->update(['name' => 'Novo nome']));

        $after = $definitions->apply($before, $data);

        $this->assertSame('Novo nome', $after['nome']);
        unset($before['nome'], $after['nome']);
        $this->assertSame($before, $after);
    }

    public function test_source_order_can_change_without_dropping_binding_options(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $source = $this->source();
        $keys = array_keys($definitions->keyed($source['flows'], 'flow'));
        $data = $definitions->normalize($this->update(['flows' => [['key' => $keys[1]], ['key' => $keys[0]]]]));

        $after = $definitions->apply($source, $data);

        $this->assertSame([$source['flows'][1], $source['flows'][0]], $after['flows']);
        $this->assertSame($source['triggers'], $after['triggers']);
    }

    public function test_editing_a_daily_time_keeps_its_native_options_and_identity(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $source = $this->source();
        $key = array_key_first($definitions->keyed($source['triggers'], 'trigger'));
        $data = $definitions->normalize($this->update(['triggers' => [['key' => $key, 'time' => '04:00']]]));

        $after = $definitions->apply($source, $data);

        $this->assertSame('04:00', $after['triggers'][0]['start_when']);
        $this->assertSame($source['triggers'][0]['native_option'], $after['triggers'][0]['native_option']);
        $this->assertSame('07:00', $after['triggers'][0]['start_time']);
        $this->assertSame($source['flows'], $after['flows']);
    }

    public function test_an_unknown_native_trigger_can_be_kept_without_reconstructing_it(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $source = [...$this->source(), 'triggers' => [['type' => 'FutureType', 'private_config' => (object) ['keep' => 'exact']]]];
        $key = array_key_first($definitions->keyed($source['triggers'], 'trigger'));
        $data = $definitions->normalize($this->update(['name' => 'Novo nome', 'triggers' => [['key' => $key]]], $source));

        $this->assertSame($source['triggers'], $definitions->apply($source, $data)['triggers']);
    }

    public function test_a_key_from_another_schedule_cannot_select_a_local_trigger(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->update(['triggers' => [['key' => str_repeat('a', 64)]]]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('não pertence');
        $definitions->apply($this->source(), $data);
    }

    public function test_a_source_binding_key_cannot_be_used_to_change_the_identity_of_its_source(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $key = array_key_first($definitions->keyed($this->source()['flows'], 'flow'));
        $data = $definitions->normalize($this->update(['flows' => [['key' => $key, 'flow_id' => 99]]]));

        $this->expectException(ValidationException::class);
        $definitions->apply($this->source(), $data);
    }

    #[DataProvider('validNativeTriggers')]
    public function test_all_ordinary_native_trigger_types_have_an_explicit_shape(array $trigger, array $expected): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['triggers' => [$trigger]]));

        $this->assertSame([$expected], $definitions->apply($definitions->defaults(), $data)['triggers']);
    }

    public static function validNativeTriggers(): array
    {
        return [
            'daily' => [['type' => 'Day', 'time' => '05:00'], ['type' => 'Day', 'start_when' => '05:00']],
            'sunday' => [['type' => 'Week', 'period' => 0, 'time' => '05:00'], ['type' => 'Week', 'period' => 0, 'start_when' => '05:00']],
            'month_end' => [['type' => 'Month', 'period' => 31, 'time' => '05:00'], ['type' => 'Month', 'period' => 31, 'start_when' => '05:00']],
            'hourly' => [['type' => 'Hour', 'period' => 2, 'anchor' => '2026-01-01T09:00:00Z', 'window_start' => '06:00', 'window_end' => '22:00'], ['type' => 'Hour', 'period' => 2, 'start_when' => '2026-01-01T09:00:00Z', 'start_time' => '06:00', 'end_time' => '22:00']],
            'brasilia_offset' => [['type' => 'Hour', 'period' => 2, 'anchor' => '2026-01-01T06:00:00-03:00', 'window_start' => '06:00', 'window_end' => '22:00'], ['type' => 'Hour', 'period' => 2, 'start_when' => '2026-01-01T09:00:00Z', 'start_time' => '06:00', 'end_time' => '22:00']],
            'historical_offset_and_fraction' => [['type' => 'Minute', 'period' => 30, 'anchor' => '2018-12-01T06:00:00.1200-02:00', 'window_start' => '06:00', 'window_end' => '22:00'], ['type' => 'Minute', 'period' => 30, 'start_when' => '2018-12-01T08:00:00.12Z', 'start_time' => '06:00', 'end_time' => '22:00']],
            'minutes' => [['type' => 'Minute', 'period' => 30, 'anchor' => '2026-01-01T09:00:00Z', 'window_start' => '06:00', 'window_end' => '22:00'], ['type' => 'Minute', 'period' => 30, 'start_when' => '2026-01-01T09:00:00Z', 'start_time' => '06:00', 'end_time' => '22:00']],
        ];
    }

    #[DataProvider('invalidNativeTriggers')]
    public function test_invalid_trigger_units_and_dates_cannot_form_a_candidate(array $trigger): void
    {
        $definitions = app(BiScheduleDefinition::class);

        $this->expectException(ValidationException::class);
        $data = $definitions->normalize($this->input(['triggers' => [$trigger]]));
        $definitions->apply($definitions->defaults(), $data);
    }

    public static function invalidNativeTriggers(): array
    {
        return [
            'missing_type' => [['time' => '03:00']],
            'daily_absolute_anchor' => [['type' => 'Day', 'anchor' => '2026-01-01T03:00:00Z']],
            'weekly_day_seven' => [['type' => 'Week', 'period' => 7, 'time' => '05:00']],
            'month_day_zero' => [['type' => 'Month', 'period' => 0, 'time' => '05:00']],
            'hour_ambiguous_anchor' => [['type' => 'Hour', 'period' => 2, 'anchor' => '2026-01-01T09:00:00', 'window_start' => '06:00', 'window_end' => '22:00']],
            'hour_impossible_date' => [['type' => 'Hour', 'period' => 2, 'anchor' => '2026-02-30T09:00:00Z', 'window_start' => '06:00', 'window_end' => '22:00']],
            'hour_missing_window' => [['type' => 'Hour', 'period' => 2, 'anchor' => '2026-01-01T09:00:00Z']],
            'minute_unknown_overnight_window' => [['type' => 'Minute', 'period' => 30, 'anchor' => '2026-01-01T09:00:00Z', 'window_start' => '22:00', 'window_end' => '06:00']],
            'cdc_without_probe' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-01-01T09:00:00Z']],
        ];
    }

    public function test_probe_connection_keys_are_resolved_only_on_the_server(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['triggers' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-01-01T09:00:00Z', 'probe' => ['credential_id' => 7, 'sql' => 'SELECT COUNT(*) FROM items', 'timeout_seconds' => 5]]]]));

        $body = $definitions->apply($definitions->defaults(), $data, ['7' => 'synthetic-private-native-key']);

        $this->assertSame(['kind' => 'query-scalar', 'credential' => 'synthetic-private-native-key', 'sql' => 'SELECT COUNT(*) FROM items', 'timeout_seconds' => 5], $body['triggers'][0]['config']);
        $this->assertStringNotContainsString('synthetic-private-native-key', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_a_probe_timeout_must_be_smaller_than_its_interval(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['triggers' => [['type' => 'CDC', 'period' => 5, 'anchor' => '2026-01-01T09:00:00Z', 'probe' => ['credential_id' => 7, 'sql' => 'SELECT 1', 'timeout_seconds' => 5]]]]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tempo limite menor');
        $definitions->apply($definitions->defaults(), $data, ['7' => 'synthetic-private-native-key']);
    }

    #[DataProvider('unsafeProbeQueries')]
    public function test_probe_queries_cannot_include_writes_or_additional_statements(string $sql): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['triggers' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-01-01T09:00:00Z', 'probe' => ['credential_id' => 7, 'sql' => $sql, 'timeout_seconds' => 5]]]]));

        $this->expectException(ValidationException::class);
        $definitions->apply($definitions->defaults(), $data, ['7' => 'synthetic-private-native-key']);
    }

    public static function unsafeProbeQueries(): array
    {
        return ['delete' => ['DELETE FROM items'], 'cte_write' => ['WITH changed AS (DELETE FROM items RETURNING id) SELECT id FROM changed'], 'second_statement' => ['SELECT 1; UPDATE items SET value=1'], 'select_into' => ['SELECT * INTO copied_items FROM items'], 'extra_separators' => ['SELECT 1;;']];
    }

    public function test_query_literals_and_quoted_identifiers_are_not_treated_as_write_commands(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['triggers' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-01-01T09:00:00Z', 'probe' => ['credential_id' => 7, 'sql' => 'SELECT "update", \'delete; drop\' FROM "items";', 'timeout_seconds' => 5]]]]));

        $this->assertSame('CDC', $definitions->apply($definitions->defaults(), $data, ['7' => 'synthetic-private-native-key'])['triggers'][0]['type']);
    }

    public function test_duplicate_loads_are_refused_even_in_an_inactive_plan(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['active' => false, 'flows' => [['flow_id' => 10, 'context' => 'Total'], ['flow_id' => 10, 'context' => 'Incremental']]]));

        $this->expectException(ValidationException::class);
        $definitions->apply($definitions->defaults(), $data);
    }

    public function test_a_key_sweep_is_distinct_from_the_data_load_for_the_same_source(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->input(['flows' => [['flow_id' => 10, 'context' => 'Incremental'], ['flow_id' => 10, 'context' => 'KeySweep']]]));

        $this->assertSame([['flow_etl_id' => 10, 'context' => 'Incremental'], ['flow_etl_id' => 10, 'context' => 'KeySweep']], $definitions->apply($definitions->defaults(), $data)['flows']);
    }

    public function test_clearing_recipients_keeps_notification_settings_outside_the_edit(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $data = $definitions->normalize($this->update(['notifications' => ['emails' => []]]));

        $after = $definitions->apply($this->source(), $data);

        $this->assertSame([], $after['sendmail_emails']);
        $this->assertTrue($after['sendmail_missed']);
        $this->assertSame(30, $after['sendmail_missed_window']);
        $this->assertTrue($after['sendmail_error']);
    }

    public function test_runtime_logs_do_not_expire_an_unchanged_configuration_but_source_order_does(): void
    {
        $definitions = app(BiScheduleDefinition::class);
        $source = $this->source();

        $this->assertSame($definitions->version($source), $definitions->version([...$source, 'last_log' => ['run' => 'later'], 'last_log_date' => '2026-10-31T12:00:00Z']));
        $this->assertNotSame($definitions->version($source), $definitions->version([...$source, 'flows' => array_reverse($source['flows'])]));
        $this->assertNotSame($definitions->version($source), $definitions->version([...$source, 'future_configuration' => []]));
    }

    public function test_unknown_fields_cannot_bypass_the_form_schema(): void
    {
        $this->expectException(ValidationException::class);
        app(BiScheduleDefinition::class)->normalize($this->input(['tenant_id' => '999', 'credentials' => ['password' => 'not-accepted']]));
    }
}
