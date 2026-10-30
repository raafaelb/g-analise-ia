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
use App\Services\Agent\CodexRuntime;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApprovalExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Revisão']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'fake']]]);

        return [$user, $project, $conversation, $run];
    }

    private function approval(User $user, Project $project, AgentRun $run): Approval
    {
        $arguments = ['action' => 'push', 'resource' => 'app:1'];
        $revision = ['tenant_id' => '100', 'resource' => 'app:1', 'info' => ['data' => ['nome' => 'Antigo']], 'changes' => ['data' => ['hash' => 'a']]];
        $run->update(['status' => 'awaiting_approval']);

        return Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'title' => 'Sincronizar rascunho', 'payload' => ['action' => $arguments, 'revision' => $revision], 'fingerprint' => hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR)), 'expires_at' => now()->addMinutes(10)]);
    }

    public function test_broker_prepares_review_and_returns_without_blocking_or_executing_push(): void
    {
        [,,,$run] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['info', 'app:1'])->andReturn(['data' => ['nome' => 'App']]);
        $mock->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['diff', 'app:1', '--include-remote'])->andReturn(['data' => ['hasDiff' => true]]);
        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'push', 'resource' => 'app:1']);
        $this->assertSame('awaiting_approval', $result['status']);
        $this->assertSame('awaiting_approval', $run->fresh()->status);
        $this->assertDatabaseHas('approvals', ['id' => $result['approval_id'], 'status' => 'pending']);
    }

    public function test_agent_finishes_turn_but_keeps_the_review_pending_and_releases_worker(): void
    {
        [$user,$project,,$run] = $this->context();
        $run->update(['status' => 'queued']);
        $runtime = \Mockery::mock(CodexRuntime::class);
        $runtime->shouldReceive('run')->once()->andReturnUsing(function ($run) use ($user, $project) {
            $this->approval($user, $project, $run);
        });
        (new RunAgentTurn($run->id))->handle($runtime);
        $this->assertSame('awaiting_approval', $run->fresh()->status);
        $this->assertDatabaseHas('approvals', ['agent_run_id' => $run->id, 'status' => 'pending']);
    }

    public function test_human_decision_queues_exactly_one_action_job(): void
    {
        [$user,$project,,$run] = $this->context();
        $approval = $this->approval($user, $project, $run);
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        Queue::assertPushed(ExecuteApprovedAction::class, fn ($job) => $job->approvalId === $approval->id);
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertConflict();
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_successful_action_resumes_ai_with_a_confirmed_system_result_not_a_repeated_user_prompt(): void
    {
        [$user,$project,$conversation,$run] = $this->context();
        $approval = $this->approval($user, $project, $run);
        $approval->update(['status' => 'approved']);
        $broker = \Mockery::mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andReturn(['exit_code' => 0, 'data' => ['applied' => ['app:1']]]);
        $job = new ExecuteApprovedAction($approval->id);
        $job->handle($broker);
        $job->handle($broker);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
        $next = AgentRun::where('conversation_id', $conversation->id)->where('id', '!=', $run->id)->firstOrFail();
        $input = Message::findOrFail($next->input_message_id);
        $this->assertSame('system', $input->role);
        $this->assertTrue($input->metadata['continuation']);
        $this->assertSame(0, $input->metadata['tool_result']['exit_code']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public function test_failure_is_not_replayed_and_does_not_start_a_followup_ai_turn(): void
    {
        [$user,$project,$conversation,$run] = $this->context();
        $approval = $this->approval($user, $project, $run);
        $approval->update(['status' => 'approved']);
        $broker = \Mockery::mock(ActionBroker::class);
        $broker->shouldReceive('performApproved')->once()->andThrow(new \RuntimeException('Revisão mudou.'));
        $job = new ExecuteApprovedAction($approval->id);
        $job->handle($broker);
        $job->handle($broker);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, AgentRun::where('conversation_id', $conversation->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_changed_revision_is_rejected_before_any_write(): void
    {
        [$user,$project,,$run] = $this->context();
        $approval = $this->approval($user, $project, $run);
        $approval->update(['status' => 'executing']);
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->once();
        $mock->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['info', 'app:1'])->andReturn(['data' => ['nome' => 'Mudou']]);
        $mock->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['diff', 'app:1', '--include-remote'])->andReturn(['data' => ['hash' => 'b']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mudou após a revisão');
        app(ActionBroker::class)->performApproved($approval);
    }

    public function test_role_revoked_while_waiting_for_review_blocks_execution(): void
    {
        [$user,$project,,$run] = $this->context();
        $approval = $this->approval($user, $project, $run);
        $approval->update(['status' => 'executing']);
        $user->forceFill(['role' => 'viewer'])->save();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('somente leitura');
        app(ActionBroker::class)->performApproved($approval);
    }
}
