<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiBusy;
use App\Services\BiValidationFailure;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiResourceValidationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function context(int $attempt = 0, bool $connected = true): array
    {
        $this->root = storage_path('framework/testing/resource-validation-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Correção automática', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Quero um mapa.', 'metadata' => ['validation_repair_attempt' => $attempt]]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'input_message_id' => $input->id, 'status' => 'running']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']]]);
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => $connected ? 'connected' : 'disconnected']);
        $path = app(WorkspaceFiles::class)->path($project).'/flows/flow--30.yaml';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "id: 30\nkind: flow\ntenantId: 100\n---\nnome: invalid: syntax\n");
        File::put(dirname($path).'/unrelated--31.yaml', 'invalid unrelated resource');

        return [$user, $project, $run, $path];
    }

    private function invalidCli(string $path): void
    {
        $this->mock(LumoClient::class)->shouldReceive('run')->once()->withArgs(fn ($c, $args, $p, $timeout, $capture) => $args === ['lint', $path] && $timeout === 90 && $capture === true)->andReturn(['exit_code' => 8, 'data' => ['errorCount' => 1, 'warningCount' => 0, 'results' => [['path' => $path, 'issues' => [['path' => '/', 'message' => 'yaml: line 109: mapping values are not allowed in this context', 'severity' => 'error']]]]]]);
    }

    public function test_lint_returns_the_selected_file_error_to_ai_without_exposing_host_paths(): void
    {
        [,, $run, $path] = $this->context();
        $this->invalidCli($path);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'lint', 'resource' => 'flow:30']);

        $this->assertFalse($result['passed']);
        $this->assertSame('flows/flow--30.yaml', $result['file']);
        $this->assertStringContainsString('line 109', $result['issues'][0]['message']);
        $this->assertStringNotContainsString($this->root, json_encode($result));
    }

    public function test_invalid_push_returns_diagnostics_before_requesting_permission_or_sending_changes(): void
    {
        [,, $run, $path] = $this->context();
        $this->invalidCli($path);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'push', 'resource' => 'flow:30']);

        $this->assertSame('validation_failed', $result['status']);
        $this->assertTrue($result['validation']['no_remote_write']);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_validation_cannot_resolve_a_file_from_another_tenant(): void
    {
        [,, $run, $path] = $this->context();
        File::put($path, "id: 30\nkind: flow\ntenantId: 999\n---\nnome: Outro tenant\n");
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->expectExceptionMessage('recurso e tenant');
        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'lint', 'resource' => 'flow:30']);
    }

    public function test_approved_action_with_offline_validation_failure_resumes_ai_once_with_diagnostics(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $run] = $this->context();
        $review = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'status' => 'approved', 'title' => 'Sincronizar fluxo', 'fingerprint' => str_repeat('a', 64), 'payload' => ['action' => ['action' => 'push', 'resource' => 'flow:30']], 'expires_at' => now()->addMinutes(10)]);
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andThrow(new BiValidationFailure(['no_remote_write' => true, 'resource' => 'flow:30', 'issues' => [['message' => 'yaml line 109']]]));

        $job = new ExecuteApprovedAction($review->id);
        $job->handle($broker);
        $job->handle($broker);

        $this->assertSame('failed', $review->fresh()->status);
        $next = AgentRun::where('conversation_id', $run->conversation_id)->where('id', '!=', $run->id)->firstOrFail();
        $input = Message::findOrFail($next->input_message_id);
        $this->assertTrue($input->metadata['validation_repair']);
        $this->assertSame(1, $input->metadata['validation_repair_attempt']);
        $this->assertSame('validation_failed', $input->metadata['tool_result']['status']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    #[DataProvider('unsafeRepairs')]
    public function test_repair_does_not_restart_after_limit_disconnect_or_uncertain_writes(int $attempt, bool $connected, bool $noWrite): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $run] = $this->context($attempt, $connected);
        $review = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'status' => 'approved', 'title' => 'Sincronizar fluxo', 'fingerprint' => str_repeat('a', 64), 'payload' => ['action' => ['action' => 'push', 'resource' => 'flow:30']], 'expires_at' => now()->addMinutes(10)]);
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andThrow(new BiValidationFailure(['no_remote_write' => $noWrite]));

        (new ExecuteApprovedAction($review->id))->handle($broker);

        $this->assertSame('failed', $review->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertNothingPushed();
    }

    public static function unsafeRepairs(): array
    {
        return ['limit' => [2, true, true], 'disconnected' => [0, false, true], 'uncertain write' => [0, true, false]];
    }

    public function test_a_busy_single_write_action_keeps_the_original_authorization_and_waits_before_retrying(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $run] = $this->context();
        $review = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'status' => 'approved', 'title' => 'Executar etapa', 'fingerprint' => str_repeat('a', 64), 'payload' => ['action' => ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']], 'expires_at' => now()->addMinutes(10)]);
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->ordered()->andThrow(new BiBusy);
        $broker->shouldReceive('performApproved')->once()->ordered()->andReturn(['exit_code' => 0, 'data' => ['state' => 'finished']]);
        $job = (new ExecuteApprovedAction($review->id))->withFakeQueueInteractions();

        $job->handle($broker);

        $job->assertReleased(5);
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertNothingPushed();

        $job->handle($broker);

        $this->assertSame('completed', $review->fresh()->status);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public function test_complex_actions_are_not_replayed_when_a_later_bi_step_is_busy(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $run] = $this->context();
        $review = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'status' => 'approved', 'title' => 'Publicar', 'fingerprint' => str_repeat('a', 64), 'payload' => ['action' => ['action' => 'publish_app', 'resource' => 'app:10']], 'expires_at' => now()->addMinutes(10)]);
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andThrow(new BiBusy);
        $job = (new ExecuteApprovedAction($review->id))->withFakeQueueInteractions();

        $job->handle($broker);

        $job->assertNotReleased();
        $this->assertSame('failed', $review->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_an_execution_cancelled_while_waiting_for_bi_is_not_requeued(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $run] = $this->context();
        $review = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'status' => 'approved', 'title' => 'Executar etapa', 'fingerprint' => str_repeat('a', 64), 'payload' => ['action' => ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']], 'expires_at' => now()->addMinutes(10)]);
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andReturnUsing(function () use ($run): never {
            $run->update(['status' => 'cancel_requested']);
            throw new BiBusy;
        });
        $job = (new ExecuteApprovedAction($review->id))->withFakeQueueInteractions();

        $job->handle($broker);

        $job->assertNotReleased();
        $this->assertSame('failed', $review->fresh()->status);
        Queue::assertNothingPushed();
    }
}
