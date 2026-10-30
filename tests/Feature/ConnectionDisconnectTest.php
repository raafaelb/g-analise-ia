<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConnectionDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Reconexão']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'fake']]]);

        return [$user, $project, $conversation, $connection];
    }

    private function review(User $user, Project $project, AgentRun $run, string $status): Approval
    {
        return Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'title' => 'Sincronizar', 'status' => $status, 'payload' => [], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
    }

    public function test_disconnect_cancels_paused_reviews_and_unblocks_a_new_task_without_touching_another_user(): void
    {
        Queue::fake();
        [$user, $project, $conversation, $connection] = $this->context();
        [$other, $otherProject, $otherConversation, $otherConnection] = $this->context();
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'awaiting_approval']);
        $approval = $this->review($user, $project, $run, 'pending');
        $foreignRun = AgentRun::create(['user_id' => $other->id, 'conversation_id' => $otherConversation->id, 'status' => 'awaiting_approval']);
        $foreignApproval = $this->review($other, $otherProject, $foreignRun, 'pending');
        $running = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);

        $this->actingAs($user)->deleteJson('/api/connections/horus')->assertOk();
        $this->assertNull($connection->fresh()->credentials);
        $this->assertSame('disconnected', $connection->fresh()->status);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertSame('cancel_requested', $running->fresh()->status);
        $this->assertSame('connected', $otherConnection->fresh()->status);
        $this->assertSame('awaiting_approval', $foreignRun->fresh()->status);
        $this->assertSame('pending', $foreignApproval->fresh()->status);
        // The in-flight worker acknowledges cancellation independently.
        $running->update(['status' => 'cancelled', 'finished_at' => now()]);
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Continuar após reconectar'])->assertAccepted();
    }

    public function test_a_previously_queued_approval_cannot_execute_after_disconnect(): void
    {
        Queue::fake();
        [$user, $project, $conversation] = $this->context();
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'awaiting_approval']);
        $approval = $this->review($user, $project, $run, 'approved');
        $this->actingAs($user)->deleteJson('/api/connections/horus')->assertOk();
        $broker = \Mockery::mock(ActionBroker::class);
        $broker->shouldNotReceive('performApproved');
        (new ExecuteApprovedAction($approval->id))->handle($broker);
        $this->assertSame('cancelled', $approval->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_cooperative_cancellation_is_not_reported_as_success(): void
    {
        [$user, , $conversation] = $this->context();
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id]);
        $runtime = \Mockery::mock(CodexRuntime::class);
        $runtime->shouldReceive('run')->once()->andReturnUsing(fn ($current) => $current->update(['status' => 'cancel_requested']));
        (new RunAgentTurn($run->id))->handle($runtime);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
