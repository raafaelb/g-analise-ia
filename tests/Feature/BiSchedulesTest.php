<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $bodies = [];

    private array $commands = [];

    private bool $pauseFails = false;

    private bool $tamperSaved = false;

    private bool $failReadback = false;

    private bool $denyLint = false;

    private bool $awaitingReadback = false;

    private bool $denySchedules = false;

    private bool $changeSession = false;

    private int $nextId = 99;

    private string $newTimezone = 'America/Sao_Paulo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/schedule-editor-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-schedule-session']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $this->bodies = ['agent:5' => ['nome' => 'Agente principal'], 'agent:6' => ['nome' => 'Outro agente'], 'flow:10' => ['nome' => 'Receitas', 'deskId' => 7, 'tokenId' => 5, 'load_type' => 'Total'], 'flow:11' => ['nome' => 'Despesas', 'deskId' => 7, 'tokenId' => 5, 'load_type' => 'Incremental'], 'credential:8' => ['id' => 8, 'nome' => 'Origem da detecção', 'key' => 'PRIVATE-NATIVE-CREDENTIAL-KEY'], 'schedule:20' => ['nome' => 'Carga antiga', 'ativo' => false, 'tokenId' => 5, 'timezone' => 'America/Sao_Paulo', 'flows' => [['flow_etl_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'period' => 1, 'start_when' => '03:00', 'start_time' => '07:00', 'end_time' => '19:00', 'native_option' => (object) ['keep' => true]]], 'sendmail_error' => true, 'sendmail_success' => false, 'sendmail_emails' => ['alerts@example.test'], 'sendmail_missed' => true, 'sendmail_missed_window' => 30, 'parallel_lane' => false, 'future_configuration' => (object) []]];
        $this->mock(LumoClient::class)->shouldReceive('initialize')->andReturnNull();
        app(LumoClient::class)->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $arguments, ?Project $context): array {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'list') {
                $kind = $arguments[1];
                if ($kind === 'schedule' && $this->denySchedules) {
                    throw new RuntimeException('Synthetic schedule refusal.', 6);
                }
                $rows = [];
                foreach ($this->bodies as $ref => $body) {
                    [$type, $id] = explode(':', $ref);
                    if ($type === $kind) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome']];
                    }
                }

                return ['data' => ['matched' => count($rows), 'results' => $rows]];
            }
            if ($arguments[0] === 'pull') {
                if ($this->awaitingReadback) {
                    $this->awaitingReadback = false;
                    throw new RuntimeException('Synthetic lost response after push.');
                }
                $refs = array_values(array_filter(array_slice($arguments, 1), fn (string $value): bool => ! str_starts_with($value, '--')));
                if ($refs === []) {
                    throw new RuntimeException('Unexpected full catalog pull.');
                }
                foreach ($refs as $ref) {
                    if (isset($this->bodies[$ref])) {
                        $this->write($context, $ref, $this->bodies[$ref]);
                    }
                }
                if ($this->changeSession) {
                    $connection->fresh()->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]);
                }

                return ['data' => []];
            }
            if ($arguments[0] === 'new' && $arguments[1] === 'schedule') {
                $id = $this->nextId++;
                $body = ['nome' => $arguments[array_search('--name', $arguments, true) + 1], 'tokenId' => (int) $arguments[array_search('--agent', $arguments, true) + 1], 'ativo' => true, 'timezone' => $this->newTimezone, 'flows' => [], 'triggers' => [], 'sendmail_error' => false, 'sendmail_success' => false, 'sendmail_emails' => [], 'sendmail_missed' => false, 'parallel_lane' => false];
                $this->bodies['schedule:'.$id] = $body;
                $this->write($context, 'schedule:'.$id, $body);

                return ['data' => ['id' => $id]];
            }
            if ($arguments[0] === 'schedule' && $arguments[1] === 'pause') {
                if ($this->pauseFails) {
                    throw new RuntimeException('Synthetic pause failure.');
                }
                $this->bodies[$arguments[2]]['ativo'] = false;

                return ['data' => ['ok' => true]];
            }
            if ($arguments[0] === 'push') {
                $body = $this->read($context, $arguments[1]);
                foreach ($body['triggers'] as &$trigger) {
                    if (($trigger['type'] ?? null) === 'Day') {
                        $trigger += ['period' => 1, 'start_time' => '07:00', 'end_time' => '19:00'];
                    }
                }
                unset($trigger);
                if ($this->tamperSaved) {
                    $body['future_configuration'] = ['changed_outside_review' => true];
                }
                $this->bodies[$arguments[1]] = $body;
                $this->awaitingReadback = $this->failReadback;
                $this->write($context, $arguments[1], $body);

                return ['data' => ['ok' => true]];
            }
            if ($arguments[0] === 'lint') {
                if ($this->denyLint) {
                    return ['exit_code' => 8, 'data' => ['valid' => false, 'errorCount' => 1, 'results' => [['issues' => [['path' => '/body/triggers', 'message' => 'Horário sintético recusado.']]]]]];
                }

                return ['exit_code' => 0, 'data' => ['valid' => true, 'errorCount' => 0, 'results' => []]];
            }
            if ($arguments[0] === 'info' && str_starts_with($arguments[1], 'credential:')) {
                return ['data' => ['payload' => $this->bodies[$arguments[1]]]];
            }
            throw new RuntimeException('Unexpected mutation or query in schedule test.');
        });

        return [$user, $connection];
    }

    private function write(Project $context, string $ref, array $body): void
    {
        [$kind, $id] = explode(':', $ref);
        $folder = ['schedule' => 'schedules', 'flow' => 'flows', 'table' => 'tables', 'agent' => 'agents', 'credential' => 'credentials'][$kind];
        $path = app(WorkspaceFiles::class)->path($context).'/'.$folder;
        File::ensureDirectoryExists($path, 0700, true);
        File::put($path.'/resource-'.$id.'.yaml', Yaml::dump(['kind' => $kind, 'id' => (int) $id, 'tenantId' => 321])."---\n".Yaml::dump($body, 40, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function read(Project $context, string $ref): array
    {
        $path = app(WorkspaceFiles::class)->path($context).'/schedules/resource-'.substr($ref, 9).'.yaml';
        $parts = preg_split('/^---\s*$/m', File::get($path), 2);
        $body = (array) Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
        foreach (['flows', 'triggers'] as $field) {
            $body[$field] = array_map(fn (mixed $row): array => (array) $row, $body[$field]);
        }

        return $body;
    }

    private function create(array $changes = []): array
    {
        return [...['action' => 'create_schedule', 'request_id' => (string) Str::uuid(), 'name' => 'Nova carga', 'agent' => 5, 'active' => false, 'flows' => [['flow_id' => 10, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'time' => '05:00']]], ...$changes];
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        Queue::fake([ExecuteApprovedAction::class]);
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        Queue::assertPushed(ExecuteApprovedAction::class, fn (ExecuteApprovedAction $job): bool => $job->approvalId === $approval->id);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->refresh();
    }

    public function test_native_refusal_of_an_edit_is_reported_before_authorization_without_mutating_the_source(): void
    {
        [$user] = $this->context();
        $version = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/configuration')->assertOk()->json('version');
        $this->denyLint = true;
        $before = $this->bodies['schedule:20'];

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', ['action' => 'update_schedule', 'resource' => 'schedule:20', 'version' => $version, 'request_id' => (string) Str::uuid(), 'name' => 'Nome proposto'])->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $this->assertSame($before, $this->bodies['schedule:20']);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => in_array($args[0], ['new', 'push', 'schedule'], true)));
    }

    public function test_execution_validation_errors_remain_actionable_in_the_history(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->bodies['flow:10']['deskId'] = null;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('ainda está privada', $result->payload['execution']['message']);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => in_array($args[0], ['new', 'push', 'schedule'], true)));
    }

    public function test_a_new_schedule_uses_the_reviewed_brasilia_timezone_even_if_the_provider_stub_has_another_default(): void
    {
        [$user, $connection] = $this->context();
        $this->newTimezone = 'UTC';
        $approval = $this->prepare($user, $this->create());

        $sectionKey = 'bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':321:section:'.'schedule';
        Cache::put($sectionKey, ['outdated' => true], 90);
        $result = $this->execute($user, $approval);
        $this->assertFalse(Cache::has($sectionKey));

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame('America/Sao_Paulo', $this->bodies['schedule:99']['timezone']);
        $this->assertFalse($this->bodies['schedule:99']['ativo']);
    }

    public function test_guests_cannot_read_schedule_options_or_prepare_changes(): void
    {
        $this->getJson('/api/catalog/321/schedules/options')->assertUnauthorized();
        $this->postJson('/api/catalog/321/schedules/actions', $this->create())->assertUnauthorized();

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_schedule_outside_the_authorized_inventory_returns_404(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->getJson('/api/catalog/321/schedules/999/configuration')->assertNotFound();

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_the_duplicate_load_warning_identifies_the_source_and_existing_schedule_by_name(): void
    {
        [$user] = $this->context();
        $this->bodies['schedule:20']['ativo'] = true;

        $response = $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create(['active' => true]))->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $this->assertStringContainsString('“Receitas”', $response->json('errors.schedule.0'));
        $this->assertStringContainsString('“Carga antiga”', $response->json('errors.schedule.0'));
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_incomplete_existing_bindings_block_preparation_instead_of_ignoring_conflicts(): void
    {
        [$user] = $this->context();
        $this->bodies['schedule:20']['flows'] = [['context' => 'Total']];

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create())->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_new_detection_resolves_its_credential_on_the_server_without_exposing_the_native_key(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create(['triggers' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-10-01T00:00:00-03:00', 'probe' => ['credential_id' => 8, 'sql' => 'SELECT MAX(updated_at) FROM synthetic', 'timeout_seconds' => 4]]]]));
        $this->actingAs($user)->getJson('/api/bi-actions/'.$approval->id)->assertOk()->assertDontSee('PRIVATE-NATIVE-CREDENTIAL-KEY');

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame('PRIVATE-NATIVE-CREDENTIAL-KEY', ((array) $this->bodies['schedule:99']['triggers'][0]['config'])['credential']);
        $this->assertStringNotContainsString('PRIVATE-NATIVE-CREDENTIAL-KEY', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_mismatched_detection_connection_identity_stops_before_review_or_mutation(): void
    {
        [$user] = $this->context();
        $this->bodies['credential:8']['id'] = 9;

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create(['triggers' => [['type' => 'CDC', 'period' => 10, 'anchor' => '2026-10-01T00:00:00-03:00', 'probe' => ['credential_id' => 8, 'sql' => 'SELECT 1', 'timeout_seconds' => 4]]]]))->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_key_sweep_is_validated_against_the_authorized_unique_destination_table(): void
    {
        [$user] = $this->context();
        $this->bodies['flow:11'] += ['key_sweep' => ['sql' => 'SELECT id FROM synthetic'], 'table' => ['id' => 50]];
        $this->bodies['table:50'] = ['nome' => 'Destino', 'table_type' => 'table', 'key_type' => 'unique', 'key_columns' => ['ID']];
        $approval = $this->prepare($user, $this->create(['flows' => [['flow_id' => 11, 'context' => 'KeySweep']]]));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame('KeySweep', $this->bodies['schedule:99']['flows'][0]['context']);
        $this->assertContains(['pull', 'table:50'], $this->commands);
    }

    public function test_key_sweep_refuses_a_destination_without_unique_keys(): void
    {
        [$user] = $this->context();
        $this->bodies['flow:11'] += ['key_sweep' => ['sql' => 'SELECT id FROM synthetic'], 'table' => ['id' => 50]];
        $this->bodies['table:50'] = ['nome' => 'Destino', 'table_type' => 'table', 'key_type' => 'duplicate', 'key_columns' => []];

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create(['flows' => [['flow_id' => 11, 'context' => 'KeySweep']]]))->assertUnprocessable()->assertJsonValidationErrors('flows');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_time_passing_over_an_anchor_does_not_invalidate_an_unchanged_signed_configuration(): void
    {
        $this->travelTo('2026-10-31T12:00:00Z');
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create(['triggers' => [['type' => 'Hour', 'period' => 1, 'anchor' => '2026-10-31T12:01:00Z', 'window_start' => '00:00', 'window_end' => '23:59']]]));
        $fingerprint = $approval->fingerprint;
        $this->travelTo('2026-10-31T12:02:00Z');

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame($fingerprint, $result->fingerprint);
    }

    public function test_a_lost_readback_can_be_checked_without_resending_and_keeps_the_original_failure(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->failReadback = true;
        $failed = $this->execute($user, $approval);
        $this->assertSame('failed', $failed->status);
        $count = count($this->commands);

        $response = $this->actingAs($user)->postJson('/api/bi-actions/'.$failed->id.'/schedule-verification')->assertOk()->assertJsonPath('verification.configuration_matches', true)->assertJsonPath('verification.test_load_requested', false)->assertJsonPath('approval.status', 'failed');

        $this->assertSame([['list', 'schedule', '--remote', '--limit', '500', '--offset', '0'], ['pull', 'schedule:99']], array_slice($this->commands, $count));
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'schedule:99', 'status' => 'configured']);
        $this->assertSame($failed->payload['execution'], $response->json('approval.payload.execution'));
    }

    public function test_verification_reports_a_real_difference_and_does_not_overwrite_the_provider(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->tamperSaved = true;
        $this->execute($user, $approval);
        $count = count($this->commands);

        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/schedule-verification')->assertOk()->assertJsonPath('verification.configuration_matches', false)->assertJsonPath('approval.status', 'failed');

        $this->assertCount(0, array_filter(array_slice($this->commands, $count), fn (array $args): bool => in_array($args[0], ['new', 'push', 'schedule'], true)));
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'schedule:99', 'status' => 'config_pending']);
    }

    public function test_verification_hides_another_users_request_and_refuses_revoked_access(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->failReadback = true;
        $this->execute($user, $approval);
        $other = User::factory()->create();
        $count = count($this->commands);

        $this->actingAs($other)->postJson('/api/bi-actions/'.$approval->id.'/schedule-verification')->assertNotFound();
        $user->forceFill(['access_version' => 1])->save();
        $this->actingAs($user->fresh())->postJson('/api/bi-actions/'.$approval->id.'/schedule-verification')->assertUnauthorized();

        $this->assertCount($count, $this->commands);
    }

    public function test_options_show_named_sources_and_agents_without_private_connection_keys(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->getJson('/api/catalog/321/schedules/options')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('flows.0.name', 'Receitas')->assertJsonPath('flows.0.eligible', true)->assertJsonPath('agents.0.name', 'Agente principal')->assertJsonPath('credentials.0.name', 'Origem da detecção');

        $this->assertStringNotContainsString('PRIVATE-NATIVE-CREDENTIAL-KEY', $response->getContent());
        $this->assertDatabaseCount('approvals', 0);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => in_array($args[0], ['new', 'push', 'schedule'], true)));
    }

    public function test_configuration_exposes_stable_row_keys_and_keeps_unedited_options_private(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/configuration')->assertOk()->assertJsonPath('configuration.name', 'Carga antiga')->assertJsonPath('configuration.triggers.0.label', 'Diariamente às 03:00');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $response->json('configuration.triggers.0.key'));
        $this->assertStringNotContainsString('native_option', $response->getContent());
    }

    public function test_creating_requires_review_and_pauses_the_empty_stub_before_attaching_sources(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->assertArrayNotHasKey('schedule:99', $this->bodies);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertFalse($this->bodies['schedule:99']['ativo']);
        $this->assertSame('05:00', $this->bodies['schedule:99']['triggers'][0]['start_when']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'schedule:99', 'user_id' => $user->id, 'origin' => 'manual_schedule', 'status' => 'configured']);
        $pause = array_search(['schedule', 'pause', 'schedule:99'], $this->commands, true);
        $push = array_search(['push', 'schedule:99'], $this->commands, true);
        $this->assertIsInt($pause);
        $this->assertIsInt($push);
        $this->assertLessThan($push, $pause);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => $args[0] === 'schedule' && ($args[1] ?? '') === 'run'));
    }

    public function test_an_active_daily_schedule_can_be_renamed_without_losing_nested_trigger_options(): void
    {
        [$user] = $this->context();
        $this->bodies['schedule:20']['ativo'] = true;
        $version = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/configuration')->assertOk()->json('version');
        $before = $this->bodies['schedule:20'];
        $approval = $this->prepare($user, ['action' => 'update_schedule', 'resource' => 'schedule:20', 'version' => $version, 'request_id' => (string) Str::uuid(), 'name' => 'Novo nome']);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame('Novo nome', $this->bodies['schedule:20']['nome']);
        $this->assertSame($before['sendmail_missed_window'], $this->bodies['schedule:20']['sendmail_missed_window']);
        $this->assertSame((array) $before['triggers'][0]['native_option'], (array) $this->bodies['schedule:20']['triggers'][0]['native_option']);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => $args[0] === 'new'));
    }

    public function test_a_failed_pause_never_sends_sources_to_the_active_new_stub_and_never_retries_creation(): void
    {
        [$user] = $this->context();
        $input = $this->create();
        $approval = $this->prepare($user, $input);
        $this->pauseFails = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame([], $this->bodies['schedule:99']['flows']);
        $this->assertSame('schedule:99', $result->payload['schedule_attempt']['created_resource']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'schedule:99', 'status' => 'config_pending']);
        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $input)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);
        $this->assertCount(1, array_filter($this->commands, fn (array $args): bool => $args[0] === 'new'));
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => $args[0] === 'push'));
    }

    public function test_a_second_prepared_activation_cannot_duplicate_the_source_after_the_first_one_succeeds(): void
    {
        [$user] = $this->context();
        $first = $this->prepare($user, $this->create(['name' => 'Primeira carga', 'active' => true]));
        $second = $this->prepare($user, $this->create(['name' => 'Outra carga', 'active' => true]));

        $this->assertSame('completed', $this->execute($user, $first)->status);
        $result = $this->execute($user, $second);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('já possui a agenda ativa', $result->payload['execution']['message']);
        $this->assertCount(1, array_filter($this->commands, fn (array $args): bool => $args[0] === 'new'));
    }

    public function test_an_unreviewed_remote_change_stops_the_write_before_it_starts(): void
    {
        [$user] = $this->context();
        $version = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/configuration')->assertOk()->json('version');
        $approval = $this->prepare($user, ['action' => 'update_schedule', 'resource' => 'schedule:20', 'version' => $version, 'request_id' => (string) Str::uuid(), 'name' => 'Novo nome']);
        $this->bodies['schedule:20']['sendmail_missed_window'] = 90;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame('Carga antiga', $this->bodies['schedule:20']['nome']);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => $args[0] === 'push'));
    }

    public function test_missing_schedule_access_does_not_appear_as_an_empty_conflict_inventory(): void
    {
        [$user] = $this->context();
        $this->denySchedules = true;

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create(['active' => true]))->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => $args[0] === 'new'));
    }

    public function test_a_changed_source_definition_invalidates_the_review_even_if_its_name_and_load_type_stay_the_same(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->bodies['flow:10']['nodes'] = [['kind' => 'Extract', 'sql' => 'SELECT other_amount FROM synthetic']];

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('suas fontes', $result->payload['execution']['message']);
        $this->assertCount(0, array_filter($this->commands, fn (array $args): bool => in_array($args[0], ['new', 'push', 'schedule'], true)));
    }

    public function test_sources_from_another_agent_cannot_be_silently_skipped(): void
    {
        [$user] = $this->context();
        $this->bodies['flow:10']['tokenId'] = 6;

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create())->assertUnprocessable()->assertJsonValidationErrors('flows');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_private_sources_require_their_own_publication_instead_of_being_scheduled(): void
    {
        [$user] = $this->context();
        $this->bodies['flow:10']['deskId'] = null;

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create())->assertUnprocessable()->assertJsonValidationErrors('flows');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_temporal_window_cannot_be_applied_to_a_total_reload_source(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create(['flows' => [['flow_id' => 10, 'context' => 'Temporal:Days:7']]]))->assertUnprocessable()->assertJsonValidationErrors('flows');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_changed_session_discards_the_old_metadata_response(): void
    {
        [$user] = $this->context();
        $this->changeSession = true;

        $this->actingAs($user)->getJson('/api/catalog/321/schedules/options')->assertUnprocessable()->assertJsonValidationErrors('schedule');
    }

    public function test_viewers_cannot_prepare_agenda_mutations(): void
    {
        [$user] = $this->context(['role' => 'viewer']);

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/actions', $this->create())->assertForbidden();

        $this->assertSame([], $this->commands);
    }

    public function test_a_foreign_tenant_is_rejected_before_reading_native_resources(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->postJson('/api/catalog/999/schedules/actions', $this->create())->assertForbidden();

        $this->assertSame([], $this->commands);
    }

    public function test_a_provider_change_outside_the_review_is_not_reported_as_success(): void
    {
        [$user] = $this->context();
        $approval = $this->prepare($user, $this->create());
        $this->tamperSaved = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertTrue($result->payload['schedule_attempt']['push_started']);
        $this->assertCount(1, array_filter($this->commands, fn (array $args): bool => $args[0] === 'push'));
    }
}
