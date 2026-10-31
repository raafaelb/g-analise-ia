<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiCredentialDrafts;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/credentials-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
        $body = ['nome' => 'Conexão existente', 'type' => 'postgresql', 'agentId' => 7, 'config' => ['hostname' => 'old.example.invalid', 'database' => 'existing_db', 'username' => 'readonly_test', 'port' => 5432], 'owners' => [1], 'key' => 'synthetic-reference-key'];
        $state = (object) ['bodies' => [11 => $body], 'commands' => [], 'created' => 0, 'pushes' => 0, 'passwords' => [11 => 'EXISTING-SYNTHETIC-SECRET'], 'last_written' => null, 'agent_available' => true, 'fail_push' => false, 'test_data' => ['ok' => true, 'message' => 'Native confirmation'], 'schema_data' => ['Success' => true, 'Tables' => [['Name' => 'example_table', 'Schema' => 'public', 'Columns' => [['Name' => 'id', 'Type' => 'integer']]]]], 'query_rows' => [['SCHEMA_NAME' => 'public', 'TABLE_NAME' => 'example_table', 'COLUMN_NAME' => 'id', 'DATA_TYPE' => 'integer', 'IS_NULLABLE' => 'NO', 'ORDINAL_POSITION' => 1, 'VISIBLE_COLUMN_COUNT' => 1]]];

        return [$user, $connection, $state];
    }

    private function input(array $changes = []): array
    {
        return array_replace(['action' => 'create_credential', 'request_id' => (string) Str::uuid(), 'driver' => 'postgres', 'name' => 'Nova conexão de teste', 'agent' => 7, 'values' => ['host' => 'new.example.invalid', 'port' => 5432, 'database' => 'test_db', 'username' => 'readonly_test', 'password' => 'SYNTHETIC-PRIVATE-PASSWORD']], $changes);
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->withArgs(fn ($connection, $context, bool $readOnly): bool => $readOnly)->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, $context) use ($state): array {
            $state->commands[] = $args;
            $write = function (int $id) use ($context, $state): string {
                $folder = app(WorkspaceFiles::class)->path($context).'/credentials';
                File::ensureDirectoryExists($folder, 0700, true);
                $path = $folder.'/connection--'.$id.'.yaml';
                File::put($path, Yaml::dump(['id' => $id, 'kind' => 'credential', 'lumo' => 'v2', 'tenantId' => (int) $context->tenant_id])."---\n".Yaml::dump($state->bodies[$id], 20, 2));

                return $path;
            };
            if ($args[0] === 'list') {
                if ($args[1] === 'agent') {
                    $rows = $state->agent_available ? [['id' => 7, 'nome' => 'Agente de homologação']] : [];
                } else {
                    $rows = array_map(fn (int $id, array $body): array => ['id' => $id, 'nome' => $body['nome']], array_keys($state->bodies), array_values($state->bodies));
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'pull') {
                $write((int) substr($args[1], 11));

                return ['data' => []];
            }
            if (array_slice($args, 0, 2) === ['new', 'credential']) {
                $state->created++;
                $type = $args[3] === 'postgres' ? 'postgresql' : $args[3];
                $state->bodies[12] = ['nome' => $args[5], 'type' => $type, 'agentId' => $state->default_new_agent ?? 7, 'config' => [], 'key' => 'synthetic-created-key'];
                $write(12);

                return ['data' => ['resource' => 'credential:12']];
            }
            if (array_slice($args, 0, 2) === ['credential', 'transfer']) {
                if ($state->fail_transfer ?? false) {
                    throw new RuntimeException('Synthetic transfer failure');
                }
                $state->bodies[(int) substr($args[2], 11)]['agentId'] = (int) $args[4];

                return ['data' => ['ok' => true]];
            }
            if ($args[0] === 'lint') {
                $this->assertCount(2, $args);
                $this->assertFileExists($args[1]);
                $this->assertStringEndsWith('.yaml', $args[1]);
                if ($state->downgrade_on_lint ?? false) {
                    User::findOrFail($connection->user_id)->forceFill(['role' => 'viewer'])->save();
                }

                return ['data' => ['valid' => true]];
            }
            if ($args[0] === 'push') {
                $state->pushes++;
                if ($state->fail_push) {
                    throw new RuntimeException('Driver message contains password=SYNTHETIC-PRIVATE-PASSWORD');
                }
                $id = (int) substr($args[1], 11);
                $path = app(WorkspaceFiles::class)->path($context).'/credentials/connection--'.$id.'.yaml';
                $parts = preg_split('/^---\s*$/m', File::get($path), 2);
                $body = Yaml::parse($parts[1]);
                $state->last_written = $body;
                if (array_key_exists('password', $body)) {
                    $state->passwords[$id] = $body['password'];
                }
                unset($body['password']);
                $state->bodies[$id] = $body;

                return ['data' => ['saved' => true, 'DO_NOT_RETURN' => 'SYNTHETIC-PRIVATE-PASSWORD']];
            }
            if (array_slice($args, 0, 2) === ['credential', 'test']) {
                if ($state->test_throws ?? false) {
                    throw new RuntimeException('password=SYNTHETIC-PRIVATE-PASSWORD', 7);
                }

                return ['data' => $state->test_data];
            }
            if (array_slice($args, 0, 2) === ['credential', 'schema']) {
                return ['data' => $state->schema_data];
            }
            if (array_slice($args, 0, 2) === ['credential', 'query']) {
                $this->assertSame('credential:11', $args[2]);
                $this->assertStringContainsString('FROM information_schema.columns', $args[4]);
                $probe = str_starts_with($args[4], 'SELECT COUNT(*) AS metadata_row_count');
                $this->assertSame(['--limit', $probe ? '1' : '501', '--timeout', '45s'], array_slice($args, 5));

                return ['data' => $probe ? ($state->probe_data ?? ['rows' => []]) : ($state->query_data ?? ['count' => count($state->query_rows), 'rows' => $state->query_rows])];
            }
            throw new RuntimeException('Unexpected native command.');
        });
    }

    private function prepare(User $user, array $data): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/credentials/actions', $data)->assertOk()->assertJsonPath('approval.status', 'pending')->assertJsonPath('project', null);

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_private_inputs_are_encrypted_and_never_appear_in_the_review_or_bootstrap(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $data = $this->input(['values' => ['host' => 'source.example.invalid', 'port' => 5432, 'database' => 'db', 'username' => 'test', 'password' => '  SYNTHETIC-PRIVATE-PASSWORD  ']]);
        $approval = $this->prepare($user, $data);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', json_encode($approval->toArray()));
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $this->getJson('/api/bootstrap')->assertOk()->getContent());
        $encrypted = Cache::get(app(BiCredentialDrafts::class)->key($approval));
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $encrypted);
        $decoded = json_decode(Crypt::decryptString($encrypted), true);
        $this->assertSame('  SYNTHETIC-PRIVATE-PASSWORD  ', $decoded['input']['password']);
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame(0, $state->created);
        $this->assertSame('Agente de homologação', $approval->payload['revision']['credential']['agent']['name']);
    }

    public function test_creation_is_authorized_once_verified_and_not_confused_with_a_connectivity_test(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        $this->assertSame(0, $state->created);
        $this->execute($user, $approval);
        $this->getJson('/api/bi-actions/'.$approval->id)->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.resource', 'credential:12')->assertJsonPath('approval.payload.execution.result.configuration_verified', true)->assertJsonPath('approval.payload.execution.result.connection_tested', false)->assertJsonPath('project', null);
        $this->assertSame('SYNTHETIC-PRIVATE-PASSWORD', $state->passwords[12]);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'credential:12', 'project_id' => null, 'status' => 'configured']);
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
        $this->assertDirectoryDoesNotExist($this->root.'/'.$user->id.'/credential-operation-'.$approval->id);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->created);
        $this->assertSame(1, $state->pushes);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_editing_preserves_an_unprovided_password_and_the_original_resource(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $data = $this->input(['action' => 'update_credential', 'resource' => 'credential:11', 'values' => ['host' => 'updated.example.invalid', 'port' => 5433, 'database' => 'existing_db', 'username' => 'readonly_test', 'password' => '']]);
        $approval = $this->prepare($user, $data);
        $this->execute($user, $approval);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertArrayNotHasKey('password', $state->last_written);
        $this->assertSame('EXISTING-SYNTHETIC-SECRET', $state->passwords[11]);
        $this->assertSame('updated.example.invalid', $state->bodies[11]['config']['hostname']);
        $this->assertSame(0, $state->created);
    }

    public function test_partial_creation_is_not_repeated_and_can_continue_by_editing_the_created_connection(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->fail_push = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('credential:12 foi criada', $approval->fresh()->payload['execution']['message']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', json_encode($approval->fresh()->toArray()));
        $this->assertSame('credential:12', $approval->fresh()->payload['credential_attempt']['created_resource']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'credential:12', 'status' => 'config_pending']);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->created);
        $state->fail_push = false;
        $retry = $this->prepare($user, $this->input(['action' => 'update_credential', 'resource' => 'credential:12']));
        $this->execute($user, $retry);
        $this->assertSame('completed', $retry->fresh()->status);
        $this->assertSame(1, $state->created);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'credential:12', 'status' => 'configured']);
    }

    public function test_reusing_a_request_preserves_its_review_but_changed_secrets_require_a_new_request(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $data = $this->input();
        $approval = $this->prepare($user, $data);
        $this->postJson('/api/catalog/100/credentials/actions', $data)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);
        $data['values']['password'] = 'OTHER-SYNTHETIC-SECRET';
        $this->postJson('/api/catalog/100/credentials/actions', $data)->assertConflict();
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->created);
    }

    public function test_rejection_erases_private_inputs_without_creating_a_resource(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'rejected', 'fingerprint' => $approval->fingerprint])->assertOk();
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
        $this->assertSame(0, $state->created);
    }

    public function test_expiration_erases_private_inputs_without_running_the_native_tool(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        $this->travel(13)->minutes();
        $this->artisan('ganalise:expire-reviews')->assertSuccessful();
        $this->assertSame('expired', $approval->fresh()->status);
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
        $this->assertSame(0, $state->created);
        $this->travelBack();
    }

    /** @return array<string, array{string}> */
    public static function changedContexts(): array
    {
        return ['changed resource' => ['resource'], 'reconnected account' => ['session'], 'viewer role' => ['role'], 'missing encrypted input' => ['input'], 'changed encrypted binding' => ['binding'], 'agent unavailable' => ['agent']];
    }

    #[DataProvider('changedContexts')]
    public function test_changes_after_review_prevent_any_write(string $case): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input(['action' => 'update_credential', 'resource' => 'credential:11']));
        if ($case === 'resource') {
            $state->bodies[11]['config']['database'] = 'changed_elsewhere';
        } elseif ($case === 'session') {
            $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        } elseif ($case === 'role') {
            $user->forceFill(['role' => 'viewer'])->save();
        } elseif ($case === 'input') {
            app(BiCredentialDrafts::class)->forget($approval);
        } elseif ($case === 'binding') {
            Cache::put(app(BiCredentialDrafts::class)->key($approval), Crypt::encryptString(json_encode(['user_id' => $user->id, 'approval_id' => 'foreign', 'fingerprint' => $approval->fingerprint, 'input' => []])), now()->addMinutes(5));
        } elseif ($case === 'agent') {
            $state->agent_available = false;
        }
        if ($case === 'role') {
            $approval->update(['status' => 'approved', 'decided_at' => now()]);
            (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        } else {
            $this->execute($user, $approval);
        }
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->created);
        $this->assertSame(0, $state->pushes);
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
    }

    public function test_role_revocation_during_preflight_stops_the_configuration_push(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->downgrade_on_lint = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input(['action' => 'update_credential', 'resource' => 'credential:11']));
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_another_user_cannot_decide_or_read_a_private_review(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        $this->actingAs(User::factory()->create())->getJson('/api/bi-actions/'.$approval->id)->assertNotFound();
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertNotFound();
        $this->assertSame(0, $state->created);
    }

    public function test_guests_and_viewers_do_not_reach_native_connection_tools(): void
    {
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->postJson('/api/catalog/100/credentials/actions', $this->input())->assertUnauthorized();
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->getJson('/api/catalog/100/credentials/options')->assertForbidden();
    }

    public function test_an_unavailable_tenant_is_rejected_before_native_tools(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/999/credentials/actions', $this->input())->assertForbidden();
    }

    public function test_unknown_form_keys_are_rejected_without_reflecting_the_private_value(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $data = $this->input();
        $data['values']['injected_secret'] = 'SYNTHETIC-PRIVATE-PASSWORD';
        $response = $this->actingAs($user)->postJson('/api/catalog/100/credentials/actions', $data)->assertUnprocessable();
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
        $this->assertSame(0, $state->created);
    }

    public function test_configuration_read_never_returns_passwords_or_embedded_service_keys(): void
    {
        [$user, , $state] = $this->context();
        $state->bodies[11] = ['nome' => 'BigQuery existente', 'type' => 'bigquery', 'agentId' => 7, 'config' => ['ProjectId' => 'example-project', 'JsonKey' => 'DO-NOT-RETURN-SERVICE-KEY'], 'password' => 'SYNTHETIC-PRIVATE-PASSWORD'];
        $this->native($state);
        $response = $this->actingAs($user)->getJson('/api/catalog/100/credentials/11/configuration')->assertOk()->assertJsonPath('values.JsonKey', '')->assertJsonPath('values.ProjectId', 'example-project');
        $this->assertStringNotContainsString('DO-NOT-RETURN-SERVICE-KEY', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public function test_the_connectivity_result_requires_the_native_confirmation_and_hides_driver_details(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $url = '/api/catalog/100/credentials/11/inspect/test';
        $this->actingAs($user)->postJson($url)->assertOk()->assertJsonPath('verified', true)->assertJsonPath('state', 'connected');
        $state->test_data = ['message' => 'password=SYNTHETIC-PRIVATE-PASSWORD'];
        $response = $this->postJson($url)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unconfirmed');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
        $state->test_throws = true;
        $response = $this->postJson($url)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'failed');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public function test_native_schema_is_limited_to_table_and_column_metadata(): void
    {
        [$user, , $state] = $this->context();
        $state->bodies[11]['type'] = 'oracle';
        $state->schema_data['password'] = 'SYNTHETIC-PRIVATE-PASSWORD';
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertOk()->assertJsonPath('verified', true)->assertJsonPath('tables.0.name', 'example_table')->assertJsonPath('tables.0.columns.0.name', 'id');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public function test_known_postgresql_union_failure_uses_a_fixed_bounded_metadata_query(): void
    {
        [$user, , $state] = $this->context();
        $state->schema_data = ['Success' => false, 'Error' => 'PostgreSQL: 42601: cada consulta UNION deve ter o mesmo número de colunas'];
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['schema' => 'public'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('method', 'postgres_information_schema')->assertJsonPath('tables.0.schema', 'public')->assertJsonPath('tables.0.columns.0.type', 'integer')->assertJsonPath('tables.0.columns.0.nullable', false);
        $queries = array_values(array_filter($state->commands, fn (array $command): bool => array_slice($command, 0, 2) === ['credential', 'query']));
        $this->assertCount(1, $queries);
        $this->assertSame([], array_values(array_filter($state->commands, fn (array $command): bool => array_slice($command, 0, 2) === ['credential', 'schema'])));
        $this->assertStringContainsString("table_schema = 'public'", $queries[0][4]);
        $this->assertSame(0, $state->pushes);
        $this->assertSame(0, $state->created);
    }

    public function test_empty_or_failed_schema_queries_are_not_presented_as_an_empty_database(): void
    {
        [$user, , $state] = $this->context();
        $state->schema_data = ['Success' => false, 'Error' => '42601 UNION'];
        $state->query_rows = [];
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unconfirmed')->assertJsonMissingPath('tables');
        $state->query_data = ['Success' => false, 'rows' => [], 'Error' => 'password=SYNTHETIC-PRIVATE-PASSWORD'];
        $response = $this->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'failed');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public function test_sql_cannot_be_submitted_by_the_browser_as_a_schema_selection(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['schema' => "public'; DROP TABLE example;"])->assertUnprocessable();
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function driverForms(): array
    {
        return [
            'PostgreSQL' => ['postgres', ['host' => 'pg.example.invalid', 'port' => 5432, 'database' => 'demo', 'username' => 'demo', 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'postgresql'],
            'MySQL' => ['mysql', ['hostname' => 'mysql.example.invalid', 'port' => 3306, 'database' => 'demo', 'username' => 'demo', 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'mysql'],
            'SQL Server' => ['sqlserver', ['hostname' => 'sql.example.invalid', 'database' => 'demo', 'username' => 'demo', 'validateCertificate' => true, 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'sqlserver'],
            'Oracle' => ['oracle', ['datasource' => 'oracle.example.invalid:1521/demo', 'username' => 'demo', 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'oracle'],
            'Firebird' => ['firebird', ['database' => 'server.example.invalid:/srv/demo.fdb', 'username' => 'demo', 'port' => 3050, 'charset' => 'UTF8', 'serverType' => 'Default', 'dialect' => 3, 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'firebird'],
            'BigQuery' => ['bigquery', ['ProjectId' => 'synthetic-project', 'JsonKey' => '{"type":"service_account","client_email":"service@example.invalid","private_key":"-----BEGIN PRIVATE KEY-----SYNTHETIC-DRIVER-SECRET"}'], 'bigquery'],
            'Informix' => ['informix', ['hostname' => 'informix.example.invalid', 'port' => 9088, 'database' => 'demo', 'informixserver' => 'ol_demo', 'username' => 'demo', 'password' => 'SYNTHETIC-DRIVER-SECRET'], 'informix'],
            'ODBC' => ['odbc', ['odbcconnection' => 'DSN=Demo;UID=demo;PWD={SYNTHETIC-DRIVER-SECRET;with;semicolons};ApiKey=SYNTHETIC-DRIVER-TOKEN'], 'odbc'],
        ];
    }

    #[DataProvider('driverForms')]
    public function test_each_supported_driver_saves_its_distinct_fields_without_disclosing_its_secret(string $driver, array $values, string $nativeType): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input(['driver' => $driver, 'values' => $values]));
        $this->assertStringNotContainsString('SYNTHETIC-DRIVER-SECRET', json_encode($approval->toArray()));
        $this->execute($user, $approval);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame($nativeType, $state->bodies[12]['type']);
        $this->assertStringNotContainsString('SYNTHETIC-DRIVER-SECRET', $this->getJson('/api/bi-actions/'.$approval->id)->assertOk()->getContent());
        if ($driver === 'mysql') {
            $this->assertSame('mysql.example.invalid', $state->bodies[12]['config']['hostname']);
            $this->assertArrayNotHasKey('host', $state->bodies[12]['config']);
        } elseif ($driver === 'sqlserver') {
            $this->assertSame('false', $state->bodies[12]['config']['trustServerCertificate']);
        } elseif ($driver === 'bigquery') {
            $this->assertArrayNotHasKey('password', $state->last_written);
            $this->assertSame($values['JsonKey'], $state->bodies[12]['config']['JsonKey']);
        } elseif ($driver === 'odbc') {
            $this->assertArrayNotHasKey('password', $state->last_written);
            $this->assertSame(['dsn', 'uid', 'pwd', 'apikey'], $state->bodies[12]['config']['secureFields']);
        }
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
    }

    public function test_bi_disconnect_discards_a_private_review_while_chatgpt_disconnect_preserves_it(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input());
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['refresh_token' => 'synthetic']]);
        $this->mock(OpenAiIdentity::class)->shouldReceive('revoke')->once()->andReturnTrue();
        $this->deleteJson('/api/connections/openai')->assertOk();
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertTrue(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
        $this->deleteJson('/api/connections/horus')->assertOk();
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertFalse(Cache::has(app(BiCredentialDrafts::class)->key($approval)));
        $this->assertSame(0, $state->created);
    }

    public function test_an_explicit_read_only_tenant_cannot_prepare_a_configuration(): void
    {
        [$user] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/actions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_server_side_sql_parameter_is_never_accepted_from_the_browser(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['sql' => 'SELECT private_data FROM business_table'])->assertUnprocessable()->assertJsonPath('errors.schema.0', 'Esta consulta aceita somente schema, termo de busca e posição da estrutura.');
    }

    public function test_schema_limits_are_reported_instead_of_claiming_all_columns_were_returned(): void
    {
        [$user, , $state] = $this->context();
        $state->schema_data = ['Success' => false, 'Error' => '42601 UNION'];
        $state->query_rows = array_map(fn (int $index): array => ['SCHEMA_NAME' => 'public', 'TABLE_NAME' => 'table_'.intdiv($index, 10), 'COLUMN_NAME' => 'column_'.$index, 'DATA_TYPE' => 'text', 'IS_NULLABLE' => 'YES', 'ORDINAL_POSITION' => ($index % 10) + 1, 'VISIBLE_COLUMN_COUNT' => 10], range(0, 500));
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertOk()->assertJsonPath('verified', true)->assertJsonPath('truncated', true)->assertJsonCount(50, 'tables')->assertJsonPath('tables.0.columns_complete', true);
        $this->assertSame(0, $state->pushes);
    }

    public function test_existing_legacy_postgresql_fields_are_shown_without_a_password(): void
    {
        [$user, , $state] = $this->context();
        unset($state->bodies[11]['config']['port']);
        $state->bodies[11]['config']['extra'] = 'Port=5444;Encoding=UTF8';
        $this->native($state);
        $this->actingAs($user)->getJson('/api/catalog/100/credentials/11/configuration')->assertOk()->assertJsonPath('values.host', 'old.example.invalid')->assertJsonPath('values.port', 5444)->assertJsonPath('values.password', '');
    }

    public function test_a_raw_sql_server_configuration_is_not_overwritten_with_a_structured_form(): void
    {
        [$user, , $state] = $this->context();
        $state->bodies[11]['type'] = 'sqlserver';
        $state->bodies[11]['config'] = ['useconnectionstring' => true, 'connectionstring' => 'Data Source=test;Password=SYNTHETIC-PRIVATE-PASSWORD'];
        $this->native($state);
        $response = $this->actingAs($user)->getJson('/api/catalog/100/credentials/11/configuration')->assertOk()->assertJsonPath('editable', false)->assertJsonPath('values', []);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', $response->getContent());
    }

    public function test_new_stub_is_transferred_to_the_reviewed_agent_before_configuration_is_pushed(): void
    {
        [$user, , $state] = $this->context();
        Queue::fake();
        $this->native($state);
        $state->default_new_agent = 8;
        $approval = $this->prepare($user, $this->input());
        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame(7, $state->bodies[12]['agentId']);
        $this->assertContains(['credential', 'transfer', 'credential:12', '--to-agent', '7'], $state->commands);
        $this->assertSame(1, $state->created);
        $this->assertSame(1, $state->pushes);
    }

    public function test_failed_new_agent_binding_retains_the_creation_receipt_and_never_pushes_configuration(): void
    {
        [$user, , $state] = $this->context();
        Queue::fake();
        $this->native($state);
        $state->default_new_agent = 8;
        $state->fail_transfer = true;
        $approval = $this->prepare($user, $this->input());
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame('credential:12', $approval->fresh()->payload['credential_attempt']['created_resource']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'credential:12', 'status' => 'config_pending']);
        $this->assertSame(1, $state->created);
        $this->assertSame(0, $state->pushes);
    }

    public function test_postgres_structure_search_uses_a_fixed_metadata_query_and_escapes_like_wildcards(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['schema' => 'public', 'search' => 'visita_domiciliar', 'offset' => 5000])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('offset', 5000)->assertJsonPath('next_offset', null);

        $queries = array_values(array_filter($state->commands, fn (array $command): bool => array_slice($command, 0, 2) === ['credential', 'query']));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString("LOWER(c.table_name) LIKE LOWER('%visita!_domiciliar%') ESCAPE '!'", $queries[0][4]);
        $this->assertStringContainsString('LIMIT 501 OFFSET 5000', $queries[0][4]);
        $this->assertSame(0, $state->pushes);
    }

    public function test_large_postgres_structure_includes_a_cursor_instead_of_claiming_a_complete_catalog(): void
    {
        [$user, , $state] = $this->context();
        $state->schema_data = ['Success' => false, 'Error' => '42601 UNION'];
        $state->query_rows = array_map(fn (int $index): array => ['SCHEMA_NAME' => 'public', 'TABLE_NAME' => 'wide_table', 'COLUMN_NAME' => 'column_'.$index, 'DATA_TYPE' => 'text', 'IS_NULLABLE' => 'YES', 'ORDINAL_POSITION' => $index + 1, 'VISIBLE_COLUMN_COUNT' => 501], range(0, 500));
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema')->assertOk()->assertJsonPath('truncated', true)->assertJsonPath('next_offset', 500)->assertJsonCount(500, 'tables.0.columns')->assertJsonPath('tables.0.columns_complete', false);
    }

    public function test_structure_search_rejects_sql_and_invalid_pagination_before_starting_the_cli(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['search' => "visita'; DROP TABLE visitas;"])->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->postJson('/api/catalog/100/credentials/11/inspect/schema', ['offset' => -1])->assertUnprocessable()->assertJsonValidationErrors('offset');
    }

    public function test_refined_structure_search_does_not_send_postgres_sql_to_another_connector(): void
    {
        [$user, , $state] = $this->context();
        $state->bodies[11]['type'] = 'oracle';
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/credentials/11/inspect/schema', ['search' => 'visita'])->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'unsupported');

        $this->assertSame([], array_values(array_filter($state->commands, fn (array $command): bool => array_slice($command, 0, 2) === ['credential', 'query'])));
    }
}
