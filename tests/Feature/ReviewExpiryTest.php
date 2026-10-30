<?php

namespace Tests\Feature;

use App\Events\WorkspaceChanged;
use App\Jobs\ExecuteApprovedAction;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function review(User $user, Project $project, string $status, ?AgentRun $run = null, bool $expired = true): Approval
    {
        return Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run?->id, 'type' => $run === null ? 'manual' : 'execution', 'status' => $status, 'title' => 'Revisão de teste', 'fingerprint' => str_repeat('a', 64), 'payload' => ['origin' => $run === null ? 'manual' : 'agent'], 'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(10)]);
    }

    public function test_expiry_closes_stale_pending_and_approved_reviews_once_without_rewriting_completed_runs_or_executing_work(): void
    {
        $this->freezeTime();
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Expiração']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $waiting = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'awaiting_approval']);
        $finished = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'completed']);
        $pending = $this->review($user, $project, 'pending', $waiting);
        $legacyApproved = $this->review($user, $project, 'approved', $finished);
        $manualApproved = $this->review($user, $project, 'approved');
        $executing = $this->review($user, $project, 'executing');
        $future = $this->review($user, $project, 'pending', null, false);
        $completed = $this->review($user, $project, 'completed');
        Event::fake([WorkspaceChanged::class]);
        $this->artisan('ganalise:expire-reviews --dry-run')->assertSuccessful();
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('approved', $manualApproved->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
        $this->artisan('ganalise:expire-reviews')->assertSuccessful();
        $this->artisan('ganalise:expire-reviews')->assertSuccessful();
        foreach ([$pending, $legacyApproved, $manualApproved] as $approval) {
            $this->assertSame('expired', $approval->fresh()->status);
        }
        $this->assertSame('failed', $waiting->fresh()->status);
        $this->assertSame('approval_expired', $waiting->fresh()->error_code);
        $this->assertSame('completed', $finished->fresh()->status);
        $this->assertSame('executing', $executing->fresh()->status);
        $this->assertSame('pending', $future->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertDatabaseCount('audit_events', 3);
        Event::assertDispatchedTimes(WorkspaceChanged::class, 3);
        Queue::assertNothingPushed();
    }

    public function test_a_stale_approved_manual_job_is_no_longer_executable_and_does_not_block_backup_after_expiry(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Revisão antiga']);
        $approval = $this->review($user, $project, 'approved');
        $this->artisan('ganalise:backup-state')->assertExitCode(2);
        $this->artisan('ganalise:expire-reviews')->assertSuccessful();
        $broker = $this->mock(ActionBroker::class);
        $broker->shouldNotReceive('performApproved');
        (new ExecuteApprovedAction($approval->id))->handle($broker);
        $this->assertSame('expired', $approval->fresh()->status);
        $this->artisan('ganalise:backup-state')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
