<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiSourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/source-schema-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(string $type = 'postgresql'): array
    {
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Ambiente autorizado']]]]);
        $state = (object) ['type' => $type, 'rows' => [$this->row()], 'commands' => [], 'paths' => [], 'data' => null, 'callback' => null, 'query' => null, 'native_data' => ['Tables' => []]];

        return [$user, $connection, $state];
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['schema_name' => 'public', 'table_name' => 'orders', 'column_name' => 'amount', 'data_type' => 'numeric', 'is_nullable' => 'NO', 'ordinal_position' => 1, 'visible_column_count' => 1], $overrides);
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->withArgs(fn ($connection, $context, bool $readOnly): bool => $readOnly)->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, Project $context) use ($state): array {
            $state->commands[] = $args;
            $state->paths[] = app(WorkspaceFiles::class)->path($context);
            if ($args[0] === 'list' && $args[1] === 'credential') {
                return ['data' => ['results' => [['id' => 11, 'nome' => 'Fonte autorizada']], 'matched' => 1]];
            }
            if ($args === ['pull', 'credential:11']) {
                $folder = app(WorkspaceFiles::class)->path($context).'/credentials';
                File::ensureDirectoryExists($folder, 0700, true);
                File::put($folder.'/source--11.yaml', Yaml::dump(['id' => 11, 'kind' => 'credential', 'lumo' => 'v2', 'tenantId' => 100])."---\n".Yaml::dump(['nome' => 'Fonte autorizada', 'type' => $state->type, 'config' => ['password' => 'SYNTHETIC-PRIVATE-PASSWORD'], 'key' => 'SYNTHETIC-REFERENCE-KEY'], 10, 2));

                return ['data' => []];
            }
            if (array_slice($args, 0, 3) === ['credential', 'schema', 'credential:11']) {
                return ['data' => $state->native_data];
            }
            if (array_slice($args, 0, 3) === ['credential', 'query', 'credential:11']) {
                $probe = str_starts_with($args[4], 'SELECT COUNT(*) AS metadata_row_count');
                $this->assertSame(['--limit', $probe ? '1' : '501', '--timeout', '45s'], array_slice($args, 5));
                if ($state->callback) {
                    ($state->callback)($connection);
                }
                if ($state->query) {
                    return ['data' => ['rows' => ($state->query)($args[4])]];
                }

                return ['data' => $probe ? ($state->probe_data ?? ['rows' => []]) : ($state->data ?? ['rows' => $state->rows, 'password' => 'SYNTHETIC-PRIVATE-PASSWORD'])];
            }
            throw new RuntimeException('Unexpected native operation.');
        });
    }

    private function inspect(User $user, array $input = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', $input);
    }

    public function test_structure_is_only_metadata_and_keeps_column_order_type_and_unknown_nullability(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [$this->row(['column_name' => 'when_created', 'ordinal_position' => 3, 'visible_column_count' => 2, 'is_nullable' => null]), $this->row(['column_name' => 'amount', 'ordinal_position' => 1, 'visible_column_count' => 2])];
        $this->native($state);

        $response = $this->inspect($user)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('coverage', 'visible_columns')->assertJsonPath('tables.0.visible_column_count', 2)->assertJsonPath('tables.0.columns_complete', true)->assertJsonPath('tables.0.columns.0.name', 'amount')->assertJsonPath('tables.0.columns.1.nullable', null);

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-REFERENCE-KEY', $response->getContent());
        foreach ($state->paths as $path) {
            $this->assertDirectoryDoesNotExist($path);
        }
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertSame(1, AuditEvent::where('action', 'bi.credential.schema_read')->count());
    }

    #[DataProvider('drivers')]
    public function test_each_driver_searches_table_or_column_names_without_slicing_matching_tables(string $type, string $driver, string $catalog, string $ending): void
    {
        [$user, , $state] = $this->context($type);
        $this->native($state);

        $this->inspect($user, ['schema' => 'Saúde', 'search' => 'latitude_value', 'offset' => 500])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('driver', $driver)->assertJsonPath('schema', 'Saúde')->assertJsonPath('search', 'latitude_value')->assertJsonPath('offset', 500)->assertJsonPath('pagination_supported', true);

        $query = collect($state->commands)->first(fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])[4];
        $this->assertStringContainsString($catalog, $query);
        $this->assertStringContainsString('EXISTS (SELECT 1', $query);
        $this->assertStringContainsString('latitude!_value', $query);
        $this->assertStringContainsString($ending, $query);
        $this->assertStringContainsString($driver === 'sqlserver' ? "N'Saúde'" : "'Saúde'", $query);
        $this->assertSame([], array_values(array_filter($state->commands, fn (array $args): bool => $args[0] === 'push' || array_slice($args, 0, 2) === ['credential', 'schema'])));
    }

    public static function drivers(): array
    {
        return [['postgresql', 'postgres', 'FROM information_schema.columns', 'LIMIT 501 OFFSET 500'], ['mysql', 'mysql', 'FROM information_schema.columns', 'LIMIT 501 OFFSET 500'], ['sqlserver', 'sqlserver', 'FROM sys.objects', 'OFFSET 500 ROWS FETCH NEXT 501 ROWS ONLY']];
    }

    public function test_large_tables_are_explicitly_partial_and_next_pages_keep_all_applied_filters(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = array_map(fn (int $i): array => $this->row(['column_name' => 'field_'.$i, 'ordinal_position' => $i, 'visible_column_count' => 502]), range(1, 501));
        $this->native($state);
        $first = $this->inspect($user, ['schema' => 'public', 'search' => 'orders'])->assertOk()->assertJsonPath('next_offset', 500)->assertJsonPath('page_size', 500)->assertJsonPath('truncated', true)->assertJsonCount(500, 'tables.0.columns')->assertJsonPath('tables.0.columns_complete', false)->json();
        $state->rows = [$this->row(['column_name' => 'field_501', 'ordinal_position' => 501, 'visible_column_count' => 502]), $this->row(['column_name' => 'field_502', 'ordinal_position' => 502, 'visible_column_count' => 502])];
        $this->inspect($user, ['schema' => $first['schema'], 'search' => $first['search'], 'offset' => $first['next_offset']])->assertOk()->assertJsonPath('offset', 500)->assertJsonPath('next_offset', null)->assertJsonPath('truncated', false)->assertJsonCount(2, 'tables.0.columns')->assertJsonPath('tables.0.columns_complete', false);
    }

    public function test_names_shared_by_different_schemas_are_not_merged(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [$this->row(), $this->row(['schema_name' => 'finance'])];
        $this->native($state);
        $this->inspect($user)->assertOk()->assertJsonCount(2, 'tables')->assertJsonPath('tables.0.schema', 'public')->assertJsonPath('tables.1.schema', 'finance');
    }

    public function test_the_last_offset_returns_a_refinement_notice_instead_of_an_invalid_next_page(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = array_map(fn (int $i): array => $this->row(['table_name' => 'table_'.$i]), range(1, 501));
        $this->native($state);
        $this->inspect($user, ['offset' => 1000000])->assertOk()->assertJsonPath('truncated', true)->assertJsonPath('pagination_limit_reached', true)->assertJsonPath('next_offset', null);
    }

    public function test_empty_metadata_is_confirmed_by_columns_or_a_bounded_count_without_claiming_no_tables_exist(): void
    {
        [$user, , $state] = $this->context();
        $state->data = ['rows' => [], 'columns' => ['schema_name', 'table_name', 'column_name', 'data_type', 'is_nullable', 'ordinal_position', 'visible_column_count']];
        $this->native($state);
        $this->inspect($user, ['search' => 'unmatched'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables', [])->assertJsonPath('empty_result_evidence', 'result_columns');
        $state->data = ['rows' => []];
        $state->probe_data = ['rows' => [['METADATA_ROW_COUNT' => '0']]];
        $this->inspect($user, ['search' => 'unmatched', 'offset' => 500])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables', [])->assertJsonPath('offset', 500)->assertJsonPath('empty_result_evidence', 'bounded_count');
        $query = collect($state->commands)->last()[4];
        $this->assertStringStartsWith('SELECT COUNT(*) AS metadata_row_count FROM (SELECT ', $query);
        $this->assertStringContainsString('LIMIT 501 OFFSET 500', $query);
        $this->assertStringContainsString('unmatched', $query);
    }

    #[DataProvider('unconfirmedEmpty')]
    public function test_an_empty_failed_or_changed_probe_is_not_a_confirmed_empty_source(array $probe): void
    {
        [$user, , $state] = $this->context();
        $state->data = ['rows' => []];
        $state->probe_data = $probe;
        $this->native($state);
        $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unconfirmed')->assertJsonMissingPath('tables');
    }

    public static function unconfirmedEmpty(): array
    {
        return [[['rows' => []]], [['rows' => [['metadata_row_count' => 1]]]], [['ok' => false, 'rows' => [['metadata_row_count' => 0]]]], [['rows' => [['metadata_row_count' => 0], ['metadata_row_count' => 0]]]]];
    }

    #[DataProvider('malformedResults')]
    public function test_malformed_or_failed_metadata_is_not_released_as_a_valid_structure(string $kind): void
    {
        [$user, , $state] = $this->context();
        $state->rows = match ($kind) {
            'duplicate' => [$this->row(), $this->row()],
            'count_changed' => [$this->row(), $this->row(['column_name' => 'other', 'ordinal_position' => 2, 'visible_column_count' => 2])],
            'bad_position' => [$this->row(['ordinal_position' => 0])],
            'bad_nullable' => [$this->row(['is_nullable' => 'unknown'])],
            'missing_name' => [$this->row(['table_name' => null])],
            'too_many' => array_fill(0, 502, $this->row()),
            default => [$this->row()],
        };
        if ($kind === 'failed') {
            $state->data = ['success' => false, 'rows' => $state->rows, 'error' => 'SYNTHETIC-PRIVATE-PASSWORD'];
        }
        $this->native($state);
        $response = $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'failed')->assertJsonMissingPath('tables');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public static function malformedResults(): array
    {
        return array_map(fn (string $kind): array => [$kind], ['duplicate', 'count_changed', 'bad_position', 'bad_nullable', 'missing_name', 'too_many', 'failed']);
    }

    public function test_the_native_connector_reports_column_truncation_and_does_not_offer_unsupported_remote_search(): void
    {
        [$user, , $state] = $this->context('oracle');
        $state->native_data = ['Tables' => [['Name' => 'ORDERS', 'Schema' => 'FINANCE', 'Columns' => array_map(fn (int $i): array => ['Name' => 'FIELD_'.$i, 'Type' => 'NUMBER'], range(1, 301))]]];
        $this->native($state);
        $this->inspect($user)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('method', 'native')->assertJsonPath('truncated', true)->assertJsonPath('tables.0.columns_truncated', true)->assertJsonPath('search_supported', false)->assertJsonPath('pagination_supported', false)->assertJsonCount(300, 'tables.0.columns');
        $this->inspect($user, ['search' => 'amount'])->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unsupported');
        $this->assertSame([], array_values(array_filter($state->commands, fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])));
    }

    public function test_a_protected_tenant_keeps_native_metadata_reading_without_permitting_generic_queries(): void
    {
        [$user, , $state] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $state->native_data = ['Tables' => [['Name' => 'orders', 'Schema' => 'public', 'Columns' => [['Name' => 'id', 'Type' => 'integer']]]]];
        $this->native($state);
        $this->inspect($user)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('method', 'native')->assertJsonPath('pagination_supported', false)->assertJsonPath('tables.0.name', 'orders');
        $this->inspect($user, ['search' => 'orders'])->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unsupported');
        $this->assertSame([], array_values(array_filter($state->commands, fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])));
    }

    #[DataProvider('invalidNativeReports')]
    public function test_malformed_native_connector_reports_remain_unconfirmed_instead_of_failing_with_a_server_error(array $data): void
    {
        [$user, , $state] = $this->context('oracle');
        $state->native_data = $data;
        $this->native($state);
        $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonMissingPath('tables');
    }

    public static function invalidNativeReports(): array
    {
        return [[['success' => 1, 'Tables' => []]], [['Tables' => [['Name' => '']]]], [['Tables' => [['Name' => 'ORDERS', 'Columns' => ['unknown-format']]]]], [['Tables' => [['Name' => 'ORDERS', 'Columns' => [['Name' => 'ID', 'Type' => ['nested']]]]]]], [['Tables' => [['Name' => 'ORDERS', 'Schema' => ['nested']]]]]];
    }

    public function test_guests_readers_unknown_connections_and_other_tenants_cannot_query_metadata(): void
    {
        [$user, , $state] = $this->context();
        $this->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertForbidden();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/200/credentials/11/inspect/schema')->assertForbidden();
        $this->postJson('/api/catalog/100/credentials/22/inspect/schema')->assertNotFound();
        $this->assertCount(1, $state->commands);
        $this->assertSame('list', $state->commands[0][0]);
    }

    #[DataProvider('revocations')]
    public function test_access_changes_during_query_discard_the_result(string $change): void
    {
        [$user, , $state] = $this->context();
        $state->callback = function (Connection $connection) use ($change): void {
            if ($change === 'version') {
                User::findOrFail($connection->user_id)->increment('access_version');
            } elseif ($change === 'tenant') {
                $connection->forceFill(['metadata' => ['session_generation' => 'session-a', 'tenants' => []]])->save();
            } else {
                $connection->forceFill(['status' => 'disconnected'])->save();
            }
        };
        $this->native($state);
        $this->inspect($user)->assertStatus($change === 'tenant' ? 403 : 422)->assertJsonMissingPath('tables');
        $this->assertSame(0, AuditEvent::where('action', 'bi.credential.schema_read')->count());
    }

    public static function revocations(): array
    {
        return [['version'], ['tenant'], ['disconnect']];
    }

    public function test_the_agent_gets_current_project_metadata_without_creating_a_write_review(): void
    {
        [$user, , $state] = $this->context();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'tenant_name' => 'Ambiente autorizado', 'name' => 'Análise financeira']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Investigar estrutura']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->native($state);
        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'credential_schema', 'resource' => 'credential:11', 'search' => 'amount']);
        $this->assertTrue($result['verified']);
        $this->assertSame(1, $result['tables'][0]['visible_column_count']);
        $this->assertTrue($result['tables'][0]['columns_complete']);
        $this->assertSame($project->id, AuditEvent::where('action', 'horus.credential_schema')->sole()->resource_id);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_the_postgres_query_preserves_other_fields_for_a_column_match_and_pages_actual_metadata(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('O contrato SQL nativo é executado na suíte PostgreSQL isolada.');
        }
        Schema::create('source_schema_paging_fixture', function (Blueprint $table): void {
            for ($i = 1; $i <= 510; $i++) {
                $table->integer('field_'.$i)->nullable();
            }
            $table->decimal('source_latitude_fixture', 10, 6);
        });
        DB::statement('ALTER TABLE source_schema_paging_fixture ADD COLUMN samples integer[]');
        DB::statement('CREATE VIEW source_schema_view_fixture AS SELECT field_1 FROM source_schema_paging_fixture');
        [$user, , $state] = $this->context();
        $state->query = fn (string $sql): array => array_map(get_object_vars(...), DB::select($sql));
        $this->native($state);
        $this->inspect($user, ['search' => 'source_latitude_fixture'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables.0.columns.0.name', 'field_1')->assertJsonPath('tables.0.visible_column_count', 512)->assertJsonPath('tables.0.columns_complete', false)->assertJsonCount(500, 'tables.0.columns')->assertJsonPath('next_offset', 500);
        $this->inspect($user, ['search' => 'source_latitude_fixture', 'offset' => 500])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('next_offset', null)->assertJsonCount(12, 'tables.0.columns')->assertJsonPath('tables.0.columns.10.name', 'source_latitude_fixture')->assertJsonPath('tables.0.columns.10.nullable', false)->assertJsonPath('tables.0.columns.11.type', 'pg_catalog._int4');
        $this->inspect($user, ['search' => 'source_schema_view_fixture'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables.0.name', 'source_schema_view_fixture')->assertJsonPath('tables.0.visible_column_count', 1)->assertJsonPath('tables.0.columns_complete', true);
        $this->inspect($user, ['search' => 'source_schema_unmatched_fixture'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables', [])->assertJsonPath('empty_result_evidence', 'bounded_count');
    }
}
