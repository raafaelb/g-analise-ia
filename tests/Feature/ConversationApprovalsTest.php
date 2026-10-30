<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private function context(User $user, ?Project $project = null): array
    {
        $project ??= Project::create(['user_id' => $user->id, 'name' => 'Revisão na conversa']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Confira esta alteração.']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'input_message_id' => $input->id, 'status' => 'awaiting_approval']);
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'title' => 'Atualizar rascunho', 'payload' => ['action' => ['action' => 'push', 'resource' => 'app:1']], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);

        return [$project, $conversation, $input, $run, $approval];
    }

    public function test_conversation_reviews_include_their_context_without_other_conversations_or_manual_operations(): void
    {
        $user = User::factory()->create();
        [$project, $conversation, $input, $run, $approval] = $this->context($user);
        [, , , , $otherReview] = $this->context($user, $project);
        [, $foreignConversation] = $this->context(User::factory()->create());
        $manual = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'type' => 'manual', 'title' => 'Operação fora do chat', 'payload' => [], 'fingerprint' => str_repeat('b', 64), 'expires_at' => now()->addMinutes(10)]);

        $response = $this->actingAs($user)->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonCount(1, 'approvals')->assertJsonPath('approvals.0.id', $approval->id)->assertJsonPath('approvals.0.conversation_id', $conversation->id)->assertJsonPath('approvals.0.input_message_id', $input->id)->assertJsonPath('approvals.0.agent_run_id', $run->id);

        $this->assertStringNotContainsString($otherReview->id, $response->getContent());
        $this->assertStringNotContainsString($manual->id, $response->getContent());
        $this->getJson('/api/conversations/'.$foreignConversation->id)->assertNotFound();
    }

    public function test_older_reviews_are_not_lost_when_only_the_latest_ten_runs_are_returned(): void
    {
        $user = User::factory()->create();
        [$project, $conversation, $input, , $approval] = $this->context($user);
        for ($index = 0; $index < 12; $index++) {
            AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'completed', 'created_at' => now()->addSeconds($index + 1)]);
        }

        $this->actingAs($user)->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonCount(10, 'runs')->assertJsonCount(1, 'approvals')->assertJsonPath('approvals.0.id', $approval->id)->assertJsonPath('approvals.0.input_message_id', $input->id);
    }

    public function test_refusing_a_review_in_place_preserves_messages_and_releases_the_conversation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [, $conversation, $input, $run, $approval] = $this->context($user);

        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'rejected', 'fingerprint' => $approval->fingerprint])->assertOk()->assertJsonPath('status', 'rejected');
        $this->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonPath('approvals.0.status', 'rejected')->assertJsonPath('runs.0.status', 'cancelled')->assertJsonPath('messages.0.id', $input->id);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertDatabaseHas('messages', ['id' => $input->id, 'content' => 'Confira esta alteração.']);
        Queue::assertNothingPushed();
    }

    public function test_authorization_is_visible_in_the_same_conversation_without_claiming_execution_is_complete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [, $conversation, , $run, $approval] = $this->context($user);

        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk()->assertJsonPath('status', 'approved');
        $this->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonPath('approvals.0.status', 'approved')->assertJsonPath('approvals.0.conversation_id', $conversation->id);

        $this->assertSame('awaiting_approval', $run->fresh()->status);
        Queue::assertPushed(ExecuteApprovedAction::class, fn ($job) => $job->approvalId === $approval->id);
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertConflict();
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_messages_with_the_same_timestamp_keep_their_stable_creation_order(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-30T20:00:00Z'));
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Ordem da conversa']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $inputId = '019b0000-0000-7000-8000-000000000001';
        $resultId = '019b0000-0000-7000-8000-000000000002';
        $replyId = '019b0000-0000-7000-8000-000000000003';
        Message::create(['id' => $replyId, 'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Resultado conferido.']);
        Message::create(['id' => $resultId, 'conversation_id' => $conversation->id, 'role' => 'system', 'content' => 'Execução concluída.']);
        Message::create(['id' => $inputId, 'conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Confira esta alteração.']);

        $this->actingAs($user)->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.0.id', $inputId)->assertJsonPath('messages.1.id', $resultId)->assertJsonPath('messages.2.id', $replyId);
    }

    public function test_a_new_pending_run_is_not_hidden_by_completed_runs_from_the_same_second(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-30T20:00:00Z'));
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Tarefa atual']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        for ($index = 1; $index <= 32; $index++) {
            AgentRun::create(['id' => sprintf('019b1111-0000-7000-8000-%012x', $index), 'user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => $index === 32 ? 'queued' : 'completed']);
        }

        $this->actingAs($user)->getJson('/api/conversations/'.$conversation->id)->assertOk()->assertJsonCount(10, 'runs')
            ->assertJsonPath('runs.0.id', '019b1111-0000-7000-8000-000000000020')->assertJsonPath('runs.0.status', 'queued')
            ->assertJsonPath('runs.9.id', '019b1111-0000-7000-8000-000000000017');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(30, 'runs')
            ->assertJsonPath('runs.0.id', '019b1111-0000-7000-8000-000000000020')->assertJsonPath('runs.0.status', 'queued')
            ->assertJsonPath('runs.29.id', '019b1111-0000-7000-8000-000000000003');
    }
}
