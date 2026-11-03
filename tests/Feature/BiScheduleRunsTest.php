<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\ObserveBiLoadExecution;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\BiLoadExecution;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiNativeFailure;
use App\Services\BiScheduleRuns;
use App\Services\LumoClient;
use App\Services\UserAccessRevocation;
use App\Services\WorkspaceFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiScheduleRunsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $bodies = [];

    private array $history = [];

    private array $info = [];

    private array $commands = [];

    private ?int $sendError = null;

    private bool $busySource = false;

    private ?RuntimeException $sourceError = null;

    private bool $denySchedules = false;

    private bool $recordSeenBeforeSend = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/load-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(array $attributes = []): array
    {
        $this->travelTo('2026-10-31T06:00:00Z');
        $user = User::factory()->create($attributes);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-load-session']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $this->bodies = ['flow:10' => ['nome' => 'Receitas', 'deskId' => 7, 'tokenId' => 5, 'load_type' => 'Total', 'nodes' => ['Processors' => [['options' => ['SQL' => 'PRIVATE-QUERY']]]]], 'schedule:20' => ['nome' => 'Carga diária', 'ativo' => true, 'tokenId' => 5, 'timezone' => 'America/Sao_Paulo', 'triggers' => [['type' => 'Day', 'start_when' => '03:00']], 'flows' => [['flow_etl_id' => 10, 'context' => 'Total']]]];
        $this->info = ['payload' => ['id' => 20, 'last_log' => null]];
        $this->mock(LumoClient::class)->shouldReceive('initialize')->andReturnNull();
        app(LumoClient::class)->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $arguments, ?Project $context): array {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'list') {
                $kind = $arguments[1];
                if ($kind === 'schedule' && $this->denySchedules) {
                    return ['data' => ['results' => [], 'matched' => 0]];
                }
                $rows = [];
                foreach ($this->bodies as $ref => $body) {
                    [$type, $id] = explode(':', $ref);
                    if ($type === $kind) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome']];
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($arguments[0] === 'pull') {
                foreach (array_slice($arguments, 1) as $ref) {
                    $body = $this->bodies[$ref] ?? throw new RuntimeException('Unknown synthetic resource');
                    [$kind, $id] = explode(':', $ref);
                    $folder = $kind === 'schedule' ? 'schedules' : 'flows';
                    $path = app(WorkspaceFiles::class)->path($context).'/'.$folder;
                    File::ensureDirectoryExists($path);
                    File::put($path.'/'.$id.'.yaml', Yaml::dump(['kind' => $kind, 'id' => (int) $id, 'tenantId' => (int) $context->tenant_id])."---\n".Yaml::dump($body, 20, 2));
                }

                return ['data' => []];
            }
            if ($arguments[0] === 'diff') {
                return ['data' => ['workingVsServer' => ['equal' => true]]];
            }
            if ($arguments[0] === 'info') {
                return ['data' => $this->info];
            }
            if (array_slice($arguments, 0, 2) === ['schedule', 'logs']) {
                return ['data' => ['schedule' => ['id' => 20], 'lista' => $this->history]];
            }
            if (array_slice($arguments, 0, 2) === ['flow', 'status']) {
                if ($this->sourceError !== null) {
                    throw $this->sourceError;
                }

                return ['data' => $this->busySource ? ['startedWhen' => '2026-10-31T05:59:00Z', 'finishedWhen' => null] : ['startedWhen' => '2026-10-30T06:00:00Z', 'finishedWhen' => '2026-10-30T06:00:10Z', 'success' => true]];
            }
            if (array_slice($arguments, 0, 2) === ['schedule', 'run']) {
                $record = BiLoadExecution::sole();
                $this->recordSeenBeforeSend = $record->status === 'requesting' && Approval::findOrFail($record->approval_id)->payload['load_execution_id'] === $record->id;
                if ($this->sendError !== null) {
                    throw new RuntimeException('Synthetic transport or native refusal', $this->sendError);
                }

                return ['exit_code' => 0, 'data' => ['accepted' => true]];
            }
            throw new RuntimeException('Unexpected synthetic command: '.json_encode($arguments));
        });

        return [$user, $connection];
    }

    private function prepare(User $user, ?string $requestId = null): array
    {
        $plan = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/load')->assertOk()->json();
        $input = ['request_id' => $requestId ?? (string) Str::uuid(), 'version' => $plan['version']];
        $review = $this->postJson('/api/catalog/321/schedules/20/loads', $input)->assertOk()->assertJsonPath('approval.status', 'pending')->json('approval');

        return [$review, $input];
    }

    private function send(User $user): Approval
    {
        [$review] = $this->prepare($user);
        $this->postJson('/api/approvals/'.$review['id'].'/decide', ['decision' => 'approved', 'fingerprint' => $review['fingerprint']])->assertOk();
        (new ExecuteApprovedAction($review['id']))->handle(app(ActionBroker::class));

        return Approval::findOrFail($review['id']);
    }

    private function finished(string $id = 'native-run'): array
    {
        return ['execution_id' => $id, 'schedule_id' => 20, 'start_time' => '2026-10-31T06:00:01Z', 'end_time' => '2026-10-31T06:00:10Z', 'status' => null, 'flows' => [['flow_id' => 10, 'success' => 1, 'message' => null]]];
    }

    private function sends(): int
    {
        return count(array_filter($this->commands, fn (array $command): bool => array_slice($command, 0, 2) === ['schedule', 'run']));
    }

    public function test_manual_review_is_idempotent_and_exposes_ordered_sources_without_private_queries(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        [$review, $input] = $this->prepare($user);

        $this->postJson('/api/catalog/321/schedules/20/loads', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('approval.id', $review['id'])->assertDontSee('PRIVATE-QUERY');
        $this->assertSame('Receitas', $review['payload']['revision']['load_plan']['bindings'][0]['name']);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('bi_load_executions', 0);
        $this->assertSame(0, $this->sends());
        Queue::assertNothingPushed();
    }

    public function test_authorized_send_records_intent_before_rpc_and_acceptance_is_not_success(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $before = $this->bodies;

        $review = $this->send($user);

        $this->assertSame('completed', $review->status);
        $this->assertTrue($this->recordSeenBeforeSend);
        $this->assertSame('accepted', BiLoadExecution::sole()->status);
        $this->assertFalse($review->payload['execution']['result']['execution_request']['verified']);
        $this->assertSame(1, $this->sends());
        $this->assertSame($before, $this->bodies);
        Queue::assertPushedOn('actions', ObserveBiLoadExecution::class, fn ($job) => $job->executionId === BiLoadExecution::sole()->id);
    }

    public function test_a_published_source_without_execution_history_can_receive_its_first_load(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->sourceError = BiNativeFailure::detect(['flow', 'status', 'flow:10'], 9, '', 'Error: status flow:10: Flow 10 ainda não tem execução registrada.');
        $this->assertInstanceOf(BiNativeFailure::class, $this->sourceError);

        $review = $this->send($user);

        $this->assertSame('completed', $review->status);
        $this->assertSame('accepted', BiLoadExecution::sole()->status);
        $this->assertFalse($review->payload['execution']['result']['execution_request']['verified']);
        $this->assertSame(1, $this->sends());
        Queue::assertPushedOn('actions', ObserveBiLoadExecution::class, fn ($job) => $job->executionId === BiLoadExecution::sole()->id);
    }

    #[TestWith([6])]
    #[TestWith([9])]
    #[TestWith([7])]
    public function test_a_status_read_refusal_or_unknown_failure_never_sends_a_load(int $code): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->sourceError = new RuntimeException('Synthetic status read refusal', $code);

        $review = $this->send($user);

        $this->assertSame('failed', $review->status);
        $this->assertDatabaseCount('bi_load_executions', 0);
        $this->assertSame(0, $this->sends());
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    public function test_a_missing_execution_reason_for_another_source_cannot_authorize_a_load(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->sourceError = BiNativeFailure::detect(['flow', 'status', 'flow:11'], 9, '', 'Error: status flow:11: Flow 11 ainda não tem execução registrada.');
        $this->assertInstanceOf(BiNativeFailure::class, $this->sourceError);

        $review = $this->send($user);

        $this->assertSame('failed', $review->status);
        $this->assertDatabaseCount('bi_load_executions', 0);
        $this->assertSame(0, $this->sends());
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    public function test_network_loss_keeps_a_durable_uncertain_attempt_without_resending(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->sendError = 7;
        $review = $this->send($user);
        $execution = BiLoadExecution::sole();

        $this->postJson('/api/bi-loads/'.$execution->id.'/observe')->assertOk()->assertJsonPath('status', 'uncertain')->assertJsonPath('proof', null);
        $this->assertSame('completed', $review->status);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_terminal_history_can_confirm_the_request_after_a_later_run_overwrites_last_log(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);
        $this->travelTo('2026-10-31T06:01:00Z');
        $this->history = [$this->finished()];
        $execution = BiLoadExecution::sole();

        $this->postJson('/api/bi-loads/'.$execution->id.'/observe')->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('proof.verified', true)->assertJsonPath('correlation', 'unique_after_request')->assertJsonPath('proof.flows.0.rows_out', null);
        $this->assertSame(Carbon::parse('2026-10-31T06:00:10Z')->timestamp, $execution->fresh()->finished_at->timestamp);
        $this->assertSame(Carbon::parse('2026-10-31T06:00:01Z')->timestamp, $execution->fresh()->started_at->timestamp);
        $this->history = [];
        $this->postJson('/api/bi-loads/'.$execution->id.'/observe')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_a_failed_source_is_reported_and_cannot_become_a_successful_test(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);
        $this->travelTo('2026-10-31T06:01:00Z');
        $row = $this->finished();
        $row['flows'][0] = ['flow_id' => 10, 'success' => 0, 'message' => 'password=secret-value'];
        $this->history = [$row];

        $this->postJson('/api/bi-loads/'.BiLoadExecution::sole()->id.'/observe')->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('proof.verified', false)->assertDontSee('secret-value');
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_ambiguous_history_does_not_choose_a_result_and_requires_conference(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);
        $this->travelTo('2026-10-31T06:01:00Z');
        $this->history = [$this->finished('one'), $this->finished('two')];

        $this->postJson('/api/bi-loads/'.BiLoadExecution::sole()->id.'/observe')->assertOk()->assertJsonPath('status', 'ambiguous')->assertJsonPath('run_id', null);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_native_refusal_is_kept_as_refusal_and_not_enqueued_for_observation(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->sendError = 9;

        $review = $this->send($user);

        $this->assertSame('failed', $review->status);
        $this->assertSame('rejected', BiLoadExecution::sole()->status);
        $this->assertSame(1, $this->sends());
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    public function test_changed_source_configuration_invalidates_review_before_sending(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        [$review] = $this->prepare($user);
        $this->bodies['flow:10']['nodes']['Processors'][0]['options']['SQL'] = 'CHANGED-QUERY';
        $this->postJson('/api/approvals/'.$review['id'].'/decide', ['decision' => 'approved', 'fingerprint' => $review['fingerprint']])->assertOk();

        (new ExecuteApprovedAction($review['id']))->handle(app(ActionBroker::class));

        $this->assertSame('failed', Approval::find($review['id'])->status);
        $this->assertDatabaseCount('bi_load_executions', 0);
        $this->assertSame(0, $this->sends());
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    #[TestWith(['inactive'])]
    #[TestWith(['busy'])]
    #[TestWith(['foreign_agent'])]
    #[TestWith(['private'])]
    public function test_invalid_or_busy_sources_never_send_a_load(string $case): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        if ($case === 'inactive') {
            $this->bodies['schedule:20']['ativo'] = false;
            $plan = $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/load')->assertOk()->json();
            $this->postJson('/api/catalog/321/schedules/20/loads', ['request_id' => (string) Str::uuid(), 'version' => $plan['version']])->assertUnprocessable();
        } elseif ($case === 'busy') {
            $this->busySource = true;
            $review = $this->send($user);
            $this->assertSame('failed', $review->status);
        } else {
            $this->bodies['flow:10'][$case === 'private' ? 'deskId' : 'tokenId'] = $case === 'private' ? null : 6;
            $this->actingAs($user)->getJson('/api/catalog/321/schedules/20/load')->assertUnprocessable();
        }

        $this->assertSame(0, $this->sends());
        $this->assertDatabaseCount('bi_load_executions', 0);
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    public function test_second_review_cannot_send_a_load_while_the_same_source_is_unresolved(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);

        $second = $this->send($user);

        $this->assertSame('failed', $second->status);
        $this->assertSame(1, $this->sends());
        $this->assertDatabaseCount('bi_load_executions', 1);
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_record_and_observer_are_isolated_from_other_users_and_tenants(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);
        $id = BiLoadExecution::sole()->id;
        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/api/bi-loads/'.$id)->assertNotFound();
        $this->postJson('/api/bi-loads/'.$id.'/observe')->assertNotFound();
        $this->actingAs($user)->getJson('/api/catalog/999/schedules/20/load')->assertForbidden();
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_viewer_and_guest_cannot_prepare_a_manual_load(): void
    {
        [$viewer] = $this->context(['role' => 'viewer']);

        $this->postJson('/api/catalog/321/schedules/20/loads', [])->assertUnauthorized();
        $this->actingAs($viewer)->getJson('/api/catalog/321/schedules/20/load')->assertForbidden();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $this->sends());
    }

    public function test_revocation_pauses_observation_without_claiming_native_cancellation(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);

        app(UserAccessRevocation::class)->revoke($user);

        $this->assertSame('access_changed', BiLoadExecution::sole()->status);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_explicit_revalidation_after_reconnect_checks_visibility_and_never_resends(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user, $connection] = $this->context();
        $this->send($user);
        $load = BiLoadExecution::sole();
        $load->update(['status' => 'access_changed', 'connection_id' => null]);
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]);
        $this->travelTo('2026-10-31T06:01:00Z');
        $this->history = [$this->finished()];

        $this->postJson('/api/bi-loads/'.$load->id.'/observe')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame($connection->id, $load->fresh()->connection_id);
        $this->assertSame($connection->metadata['session_generation'], $load->fresh()->metadata['connection_generation']);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_request_cannot_be_reused_with_new_configuration_or_unknown_fields(): void
    {
        [$user] = $this->context();
        [, $input] = $this->prepare($user);

        $this->postJson('/api/catalog/321/schedules/20/loads', [...$input, 'version' => str_repeat('b', 64)])->assertConflict();
        $this->postJson('/api/catalog/321/schedules/20/loads', [...$input, 'force' => true])->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $this->sends());
    }

    public function test_detection_probe_credentials_are_not_exposed_in_the_load_plan_or_review(): void
    {
        [$user] = $this->context();
        $this->bodies['schedule:20']['triggers'][] = ['type' => 'CDC', 'period' => 60, 'probe_config' => ['credentialKey' => 'PRIVATE-PROBE-KEY', 'sql' => 'PRIVATE-PROBE-SQL']];

        [$review] = $this->prepare($user);

        $encoded = json_encode($review, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-PROBE', $encoded);
        $this->assertSame(['type' => 'CDC', 'period' => 60], $review['payload']['revision']['load_plan']['schedule']['triggers'][1]);
        $this->assertSame(0, $this->sends());
    }

    public function test_worker_observes_once_and_schedules_only_another_read_while_pending(): void
    {
        Queue::fake([ObserveBiLoadExecution::class]);
        [$user, $connection] = $this->context();
        $load = BiLoadExecution::factory()->create(['user_id' => $user->id, 'connection_id' => $connection->id]);
        $load->update(['metadata' => [...$load->metadata, 'connection_generation' => $connection->metadata['session_generation'], 'access_version' => $user->access_version]]);

        (new ObserveBiLoadExecution($load->id))->handle(app(BiScheduleRuns::class));

        $this->assertSame('accepted', $load->fresh()->status);
        $this->assertSame(0, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class, fn ($job) => $job->executionId === $load->id && $job->queue === 'actions');
    }

    public function test_observation_stops_after_four_hours_without_claiming_failure_of_the_native_job(): void
    {
        Queue::fake([ObserveBiLoadExecution::class]);
        [$user, $connection] = $this->context();
        $load = BiLoadExecution::factory()->create(['user_id' => $user->id, 'connection_id' => $connection->id, 'requested_at' => now()->subHours(5)]);
        $load->update(['metadata' => [...$load->metadata, 'connection_generation' => $connection->metadata['session_generation'], 'access_version' => $user->access_version]]);

        (new ObserveBiLoadExecution($load->id))->handle(app(BiScheduleRuns::class));

        $this->assertSame('needs_attention', $load->fresh()->status);
        $this->assertSame(0, $this->sends());
        Queue::assertNotPushed(ObserveBiLoadExecution::class);
    }

    public function test_ai_review_uses_the_same_durable_tracking_and_scopes_status_to_its_project(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class, RunAgentTurn::class]);
        [$user, $connection] = $this->context();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '321', 'name' => 'Teste de atualização']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Atualização']);
        $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Atualize os dados', 'status' => 'completed']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'input_message_id' => $message->id, 'status' => 'running']);
        app(LumoClient::class)->run($connection, ['pull', 'schedule:20'], $project);
        $broker = app(ActionBroker::class);
        $review = $broker->execute($run, 'horus_action', ['action' => 'run_schedule', 'resource' => 'schedule:20']);
        $approval = Approval::findOrFail($review['approval_id']);
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle($broker);
        $this->assertSame('completed', $approval->fresh()->status, $approval->fresh()->payload['execution']['message'] ?? 'Carga sem recibo');
        $execution = BiLoadExecution::sole();
        $this->travelTo('2026-10-31T06:01:00Z');
        $this->history = [$this->finished()];
        $continuation = AgentRun::where('conversation_id', $conversation->id)->where('id', '!=', $run->id)->sole();

        $result = $broker->execute($continuation, 'horus_action', ['action' => 'load_status', 'execution_id' => $execution->id]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame($execution->id, RefreshSchedule::sole()->metadata['test_request']['execution_id']);
        $this->assertTrue(RefreshSchedule::sole()->metadata['test_verified']);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(RunAgentTurn::class, fn ($job) => $job->runId === $continuation->id);
    }

    public function test_a_reconnected_account_cannot_revalidate_a_schedule_it_cannot_see(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $this->send($user);
        $load = BiLoadExecution::sole();
        $load->update(['status' => 'needs_revalidation']);
        $this->denySchedules = true;

        $this->postJson('/api/bi-loads/'.$load->id.'/observe')->assertNotFound();
        $this->assertSame('needs_revalidation', $load->fresh()->status);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_catalog_button_prepares_a_fresh_review_without_a_redundant_plan_request(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->postJson('/api/catalog/321/schedules/20/loads', ['request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('approval.status', 'pending')->assertJsonPath('approval.payload.revision.load_plan.bindings.0.name', 'Receitas');

        $this->assertCount(2, array_filter($this->commands, fn (array $command): bool => $command[0] === 'pull'));
        $this->assertDatabaseCount('bi_load_executions', 0);
        $this->assertSame(0, $this->sends());
    }

    public function test_unresolved_source_blocks_a_second_user_without_exposing_the_first_users_request(): void
    {
        Queue::fake([ExecuteApprovedAction::class, ObserveBiLoadExecution::class]);
        [$first] = $this->context();
        $this->send($first);
        [$second] = $this->context();

        $review = $this->send($second);

        $this->assertSame('failed', $review->status);
        $this->assertStringNotContainsString($first->email, $review->payload['execution']['message']);
        $this->actingAs($second)->getJson('/api/catalog/321/schedules/20/loads')->assertOk()->assertJsonPath('executions', []);
        $this->assertDatabaseCount('bi_load_executions', 1);
        $this->assertSame(1, $this->sends());
        Queue::assertPushed(ObserveBiLoadExecution::class);
    }

    public function test_duplicate_observation_requests_share_a_single_pending_job(): void
    {
        Queue::fake([ObserveBiLoadExecution::class]);
        [$user] = $this->context();
        $load = BiLoadExecution::factory()->create(['user_id' => $user->id]);

        ObserveBiLoadExecution::dispatch($load->id)->delay(now()->addSeconds(15));
        ObserveBiLoadExecution::dispatch($load->id)->delay(now()->addSeconds(15));

        Queue::assertPushed(ObserveBiLoadExecution::class, 1);
        $this->assertSame(0, $this->sends());
    }

    public function test_manual_observation_keeps_pending_followups_outside_the_ai_queue(): void
    {
        Queue::fake([ObserveBiLoadExecution::class]);
        [$user, $connection] = $this->context();
        $load = BiLoadExecution::factory()->create(['user_id' => $user->id, 'connection_id' => $connection->id]);
        $load->update(['metadata' => [...$load->metadata, 'connection_generation' => $connection->metadata['session_generation'], 'access_version' => $user->access_version]]);

        $this->actingAs($user)->postJson('/api/bi-loads/'.$load->id.'/observe')->assertOk()->assertJsonPath('status', 'accepted');

        Queue::assertPushedOn('actions', ObserveBiLoadExecution::class, fn ($job) => $job->executionId === $load->id);
        $this->assertSame(0, $this->sends());
        $this->assertSame('accepted', $load->fresh()->status);
    }

    public function test_maintenance_queues_stale_load_observations_outside_the_ai_queue(): void
    {
        Queue::fake([ObserveBiLoadExecution::class]);
        [$user, $connection] = $this->context();
        $load = BiLoadExecution::factory()->create(['user_id' => $user->id, 'connection_id' => $connection->id, 'updated_at' => now()->subMinutes(6)]);
        $this->app->make(ConsoleKernel::class)->bootstrap();
        $maintenance = collect($this->app->make(Schedule::class)->events())->first(fn ($event) => $event->description === 'workspace-maintenance');
        $this->assertNotNull($maintenance);

        $maintenance->run($this->app);

        Queue::assertPushedOn('actions', ObserveBiLoadExecution::class, fn ($job) => $job->executionId === $load->id);
        $this->assertSame(0, $this->sends());
        $this->assertSame('accepted', $load->fresh()->status);
    }
}
