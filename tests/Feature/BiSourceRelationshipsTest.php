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

class BiSourceRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/source-relationships-'.Str::uuid());
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
        $state = (object) ['type' => $type, 'rows' => [$this->row()], 'commands' => [], 'paths' => [], 'callback' => null, 'query' => null, 'data' => null];

        return [$user, $connection, $state];
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['constraint_schema' => 'public', 'constraint_name' => 'orders_customer_fk', 'source_schema' => 'public', 'source_table' => 'orders', 'target_schema' => 'public', 'target_table' => 'customers', 'source_column' => 'customer_id', 'target_column' => 'id', 'position' => 1, 'column_count' => 1, 'validated' => true, 'enabled' => null], $overrides);
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
            if (array_slice($args, 0, 3) === ['credential', 'query', 'credential:11']) {
                $probe = str_starts_with($args[4], 'SELECT COUNT(*) AS metadata_row_count');
                $this->assertSame(['--limit', $probe ? '1' : '201', '--timeout', '45s'], array_slice($args, 5));
                $this->assertStringStartsWith('SELECT ', $args[4]);
                if ($state->callback) {
                    ($state->callback)($connection);
                }
                if ($state->query) {
                    return ['data' => ['rows' => ($state->query)($args[4])]];
                }
                if ($probe) {
                    return ['data' => $state->probe_data ?? ['rows' => []]];
                }

                return ['data' => $state->data ?? ['rows' => $state->rows, 'password' => 'SYNTHETIC-PRIVATE-PASSWORD']];
            }
            throw new RuntimeException('Unexpected native operation.');
        });
    }

    private function inspect(User $user, array $filters = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/relationships', $filters);
    }

    public function test_returns_declared_pairs_without_credentials_business_rows_or_write_operations(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);

        $response = $this->inspect($user)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('verified', true)->assertJsonPath('coverage', 'visible_declared_constraints')->assertJsonPath('relationships.0.from.table', 'orders')->assertJsonPath('relationships.0.to.table', 'customers')->assertJsonPath('relationships.0.columns', [['source' => 'customer_id', 'target' => 'id', 'position' => 1]])->assertJsonPath('relationships.0.complete', true)->assertJsonPath('relationships.0.enabled', null);

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-REFERENCE-KEY', $response->getContent());
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertSame(['tenant_id', 'resource', 'verified', 'relationship_count', 'truncated'], array_keys(AuditEvent::where('action', 'bi.credential.relationships_read')->sole()->metadata));
        foreach (array_unique($state->paths) as $path) {
            $this->assertDirectoryDoesNotExist($path);
        }
    }

    public function test_composite_pairs_keep_their_declared_order_without_crossing_target_columns(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [$this->row(['source_column' => 'tenant_id', 'target_column' => 'organization_id', 'position' => '1', 'column_count' => '2', 'validated' => 't']), $this->row(['source_column' => 'customer_id', 'target_column' => 'customer_number', 'position' => '2', 'column_count' => '2', 'validated' => 't'])];
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonCount(1, 'relationships')->assertJsonPath('relationships.0.columns', [['source' => 'tenant_id', 'target' => 'organization_id', 'position' => 1], ['source' => 'customer_id', 'target' => 'customer_number', 'position' => 2]])->assertJsonPath('relationships.0.complete', true);
    }

    public function test_repeated_constraint_names_on_different_tables_are_not_merged(): void
    {
        [$user, , $state] = $this->context();
        $state->rows[] = $this->row(['source_table' => 'archived_orders']);
        $this->native($state);

        $response = $this->inspect($user)->assertOk()->assertJsonCount(2, 'relationships');

        $this->assertNotSame($response->json('relationships.0.id'), $response->json('relationships.1.id'));
    }

    #[DataProvider('drivers')]
    public function test_each_supported_driver_uses_a_bounded_metadata_query_with_incoming_and_outgoing_schema_filters(string $nativeType, string $driver, string $catalog, string $pagination): void
    {
        [$user, , $state] = $this->context($nativeType);
        $this->native($state);

        $this->inspect($user, ['schema' => 'public', 'search' => 'pedido_01', 'offset' => 200])->assertOk()->assertJsonPath('driver', $driver)->assertJsonPath('offset', 200);

        $query = end($state->commands)[4];
        $this->assertStringContainsString($catalog, $query);
        $this->assertStringContainsString("source_schema = 'public' OR target_schema = 'public'", $query);
        $this->assertStringContainsString("'%pedido!_01%'", $query);
        $this->assertStringEndsWith($pagination, $query);
    }

    public static function drivers(): array
    {
        return ['postgres' => ['postgresql', 'postgres', 'pg_catalog.pg_constraint', 'LIMIT 201 OFFSET 200'], 'mysql' => ['mysql', 'mysql', 'information_schema.KEY_COLUMN_USAGE', 'LIMIT 201 OFFSET 200'], 'sqlserver' => ['sqlserver', 'sqlserver', 'sys.foreign_key_columns', 'OFFSET 200 ROWS FETCH NEXT 201 ROWS ONLY']];
    }

    public function test_mysql_column_visibility_cannot_be_mistaken_for_a_confirmed_complete_key(): void
    {
        [$user, , $state] = $this->context('mysql');
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('relationships.0.column_count_verified', false)->assertJsonPath('relationships.0.complete', false)->assertJsonCount(1, 'relationships.0.columns');
    }

    public function test_pagination_marks_split_composite_constraints_incomplete_until_a_refined_query_returns_all_pairs(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = array_map(fn (int $index): array => $this->row(['constraint_name' => sprintf('fk_%03d', $index)]), range(1, 199));
        $composite = [$this->row(['constraint_name' => 'fk_composite', 'column_count' => 2]), $this->row(['constraint_name' => 'fk_composite', 'column_count' => 2, 'position' => 2, 'source_column' => 'tenant_id', 'target_column' => 'tenant_id'])];
        array_push($state->rows, ...$composite);
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('truncated', true)->assertJsonPath('next_offset', 200)->assertJsonPath('relationships.199.complete', false)->assertJsonCount(1, 'relationships.199.columns');
        $state->rows = [$composite[1]];
        $this->inspect($user, ['offset' => 200])->assertOk()->assertJsonPath('next_offset', null)->assertJsonPath('relationships.0.complete', false);
        $state->rows = $composite;
        $this->inspect($user, ['search' => 'fk_composite'])->assertOk()->assertJsonPath('relationships.0.complete', true)->assertJsonCount(2, 'relationships.0.columns');
    }

    public function test_the_last_allowed_offset_requires_refinement_instead_of_emitting_an_invalid_next_page(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = array_map(fn (int $index): array => $this->row(['constraint_name' => sprintf('fk_%03d', $index)]), range(1, 201));
        $this->native($state);

        $this->inspect($user, ['offset' => 1000000])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('truncated', true)->assertJsonPath('next_offset', null)->assertJsonPath('pagination_limit_reached', true);
    }

    public function test_disabled_unvalidated_and_unknown_validation_states_remain_distinct(): void
    {
        [$user, , $state] = $this->context('sqlserver');
        $state->rows[0]['validated'] = 0;
        $state->rows[0]['enabled'] = '0';
        $state->rows[] = $this->row(['constraint_name' => 'fk_unknown', 'validated' => null]);
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('relationships.0.validated', false)->assertJsonPath('relationships.0.enabled', false)->assertJsonPath('relationships.1.validated', null);
    }

    public function test_an_empty_confirmed_query_does_not_claim_that_business_relationships_do_not_exist(): void
    {
        [$user, , $state] = $this->context();
        $state->data = ['rows' => [], 'columns' => array_keys($this->row())];
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('relationships', [])->assertJsonPath('scope_warning', 'São vínculos declarados e visíveis para esta conexão, não todos os relacionamentos possíveis do negócio. Uma lista vazia não comprova ausência de vínculos. Confirme os dados antes de combinar tabelas; vínculos incompletos, não validados ou desativados exigem investigação.');
    }

    public function test_an_empty_response_without_columns_or_a_successful_probe_remains_unconfirmed(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [];
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unconfirmed')->assertJsonMissingPath('relationships');
    }

    public function test_a_bounded_metadata_count_confirms_empty_results_when_the_agent_omits_columns(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [];
        $state->probe_data = ['rows' => [['METADATA_ROW_COUNT' => '0']]];
        $this->native($state);

        $this->inspect($user, ['schema' => 'clinical', 'search' => 'unmatched', 'offset' => 200])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('relationships', [])->assertJsonPath('empty_result_evidence', 'bounded_count');

        $queries = array_values(array_filter($state->commands, fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query']));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString($queries[0][4], $queries[1][4]);
        $this->assertStringContainsString("source_schema = 'clinical' OR target_schema = 'clinical'", $queries[1][4]);
        $this->assertStringEndsWith('LIMIT 201 OFFSET 200) AS relationship_probe', $queries[1][4]);
    }

    #[DataProvider('unconfirmedProbes')]
    public function test_a_missing_failed_or_changed_count_does_not_prove_an_empty_catalog(array $data): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [];
        $state->probe_data = $data;
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unconfirmed')->assertJsonMissingPath('relationships');
    }

    public static function unconfirmedProbes(): array
    {
        return ['changed-result' => [['rows' => [['metadata_row_count' => 1]]]], 'invalid-count' => [['rows' => [['metadata_row_count' => false]]]], 'failure' => [['Success' => false, 'rows' => [['metadata_row_count' => 0]]]], 'multiple-counts' => [['rows' => [['metadata_row_count' => 0], ['metadata_row_count' => 0]]]], 'no-envelope' => [[]]];
    }

    public function test_revocation_during_the_empty_result_probe_discards_its_confirmation(): void
    {
        [$user, , $state] = $this->context();
        $state->rows = [];
        $state->probe_data = ['rows' => [['metadata_row_count' => 0]]];
        $calls = 0;
        $state->callback = function (Connection $connection) use (&$calls): void {
            if (++$calls === 2) {
                User::whereKey($connection->user_id)->increment('access_version');
            }
        };
        $this->native($state);

        $this->inspect($user)->assertUnprocessable()->assertJsonMissingPath('relationships');
    }

    #[DataProvider('invalidResults')]
    public function test_malformed_or_failed_results_do_not_expose_partial_relationships(string $case): void
    {
        [$user, , $state] = $this->context();
        $row = $this->row();
        $state->data = match ($case) {
            'missing-rows' => ['Success' => true],
            'string-success' => ['Success' => 'false', 'rows' => [$row]],
            'failed' => ['Success' => false, 'rows' => [$row], 'Error' => 'SYNTHETIC-PRIVATE-PASSWORD'],
            'too-large' => ['rows' => array_fill(0, 202, $row)],
            'duplicate' => ['rows' => [$row, $row]],
            'column-missing' => ['rows' => [array_diff_key($row, ['target_column' => true])]],
            'bad-order' => ['rows' => [array_replace($row, ['position' => 1.5])]],
            'excess-position' => ['rows' => [array_replace($row, ['position' => 2])]],
            'divergent-target' => ['rows' => [$row, array_replace($row, ['target_table' => 'other'])]],
            'unknown-state' => ['rows' => [array_replace($row, ['validated' => 'unexpected'])]],
            'invalid-row' => ['rows' => ['unexpected']],
        };
        $this->native($state);

        $response = $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'failed')->assertJsonMissingPath('relationships');

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public static function invalidResults(): array
    {
        return array_combine($cases = ['missing-rows', 'string-success', 'failed', 'too-large', 'duplicate', 'column-missing', 'bad-order', 'excess-position', 'divergent-target', 'unknown-state', 'invalid-row'], array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_an_unsupported_connector_does_not_attempt_a_guessed_metadata_query(): void
    {
        [$user, , $state] = $this->context('oracle');
        $this->native($state);

        $this->inspect($user)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unsupported');

        $this->assertSame([], array_values(array_filter($state->commands, fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])));
    }

    public function test_guests_and_readers_cannot_investigate_connection_metadata(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->postJson('/api/catalog/100/credentials/11/inspect/relationships')->assertUnauthorized();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->inspect($user)->assertForbidden();
    }

    public function test_unavailable_connections_and_other_tenants_are_not_queried(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/credentials/99/inspect/relationships')->assertNotFound();
        $this->postJson('/api/catalog/999/credentials/11/inspect/relationships')->assertForbidden();

        $this->assertSame([], array_values(array_filter($state->commands, fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])));
    }

    #[DataProvider('invalidFilters')]
    public function test_arbitrary_sql_injected_filters_and_invalid_offsets_are_rejected_before_querying(array $input, string $field): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->inspect($user, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return ['sql' => [['sql' => 'SELECT private_data FROM sensitive_table'], 'schema'], 'schema-injection' => [['schema' => "public'; DELETE FROM orders;"], 'schema'], 'search-injection' => [['search' => "orders' UNION SELECT password"], 'search'], 'negative-offset' => [['offset' => -1], 'offset'], 'excess-offset' => [['offset' => 1000001], 'offset']];
    }

    public function test_revocation_during_query_discards_the_result(): void
    {
        [$user, , $state] = $this->context();
        $state->callback = fn (Connection $connection) => $connection->update(['metadata' => [...$connection->metadata, 'tenants' => []]]);
        $this->native($state);

        $this->inspect($user)->assertForbidden()->assertJsonMissingPath('relationships');
    }

    public function test_an_access_version_change_discards_an_in_flight_result(): void
    {
        [$user, , $state] = $this->context();
        $state->callback = fn (Connection $connection) => User::whereKey($connection->user_id)->increment('access_version');
        $this->native($state);

        $this->inspect($user)->assertUnprocessable()->assertJsonMissingPath('relationships');
    }

    public function test_the_agent_receives_the_same_pairs_in_its_current_project_without_a_write_review(): void
    {
        [$user, , $state] = $this->context();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'tenant_name' => 'Ambiente autorizado', 'name' => 'Análise financeira']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Investigar fonte']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->native($state);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'credential_relationships', 'resource' => 'credential:11', 'search' => 'orders']);

        $this->assertTrue($result['verified']);
        $this->assertSame('customer_id', $result['relationships'][0]['columns'][0]['source']);
        $audit = AuditEvent::where('action', 'horus.credential_relationships')->sole();
        $this->assertSame($project->id, $audit->resource_id);
        $this->assertSame(['resource' => 'credential:11', 'verified' => true, 'relationship_count' => 1, 'truncated' => false], $audit->metadata);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_the_postgres_query_reads_composite_pairs_from_actual_database_metadata(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('O contrato SQL nativo é executado na suíte PostgreSQL isolada.');
        }
        Schema::create('relationship_parent_fixture', function (Blueprint $table): void {
            $table->integer('alpha');
            $table->integer('beta');
            $table->unique(['beta', 'alpha']);
        });
        Schema::create('relationship_child_fixture', function (Blueprint $table): void {
            $table->integer('ref_x');
            $table->integer('ref_y');
            $table->foreign(['ref_x', 'ref_y'], 'relationship_fk_fixture')->references(['beta', 'alpha'])->on('relationship_parent_fixture');
        });
        [$user, , $state] = $this->context();
        $state->query = fn (string $sql): array => array_map(get_object_vars(...), DB::select($sql));
        $this->native($state);

        $this->inspect($user, ['search' => 'relationship_fk_fixture'])->assertOk()->assertJsonPath('verified', true)->assertJsonCount(1, 'relationships')->assertJsonPath('relationships.0.columns', [['source' => 'ref_x', 'target' => 'beta', 'position' => 1], ['source' => 'ref_y', 'target' => 'alpha', 'position' => 2]])->assertJsonPath('relationships.0.complete', true);
        $this->inspect($user, ['search' => 'relationship_unmatched_fixture'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('relationships', [])->assertJsonPath('empty_result_evidence', 'bounded_count');
    }
}
