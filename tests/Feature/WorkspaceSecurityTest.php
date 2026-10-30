<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use App\Services\Agent\CodexRuntime;
use App\Services\RefreshCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkspaceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return Project::create(['user_id' => $user->id, 'name' => 'Projeto privado']);
    }

    public function test_guests_cannot_access_workspace_or_api(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_bootstrap_returns_only_current_users_projects_and_never_tokens(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->project($user);
        $foreign = $this->project($other);
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'sensitive-access-token']]);
        $response = $this->actingAs($user)->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', $own->id);
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString('sensitive-access-token', $response->getContent());
        $this->assertStringNotContainsString('sensitive-access-token', Connection::first()->getRawOriginal('credentials'));
    }

    public function test_a_user_cannot_edit_or_read_another_users_project_and_conversation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->project($other);
        $conversation = Conversation::create(['user_id' => $other->id, 'project_id' => $project->id]);
        $this->actingAs($user)->patchJson('/api/projects/'.$project->id, ['name' => 'Alterado'])->assertNotFound();
        $this->getJson('/api/conversations/'.$conversation->id)->assertNotFound();
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Teste'])->assertNotFound();
    }

    public function test_viewer_cannot_create_projects(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->postJson('/api/projects', ['name' => 'Projeto'])->assertForbidden();
    }

    public function test_project_creation_also_creates_a_conversation(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/projects', ['name' => 'Vendas', 'color' => 'violet'])->assertCreated();
        $this->assertDatabaseHas('conversations', ['id' => $response->json('conversation.id'), 'project_id' => $response->json('project.id'), 'user_id' => $user->id]);
    }

    public function test_user_cannot_assign_an_unauthorized_tenant(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/projects', ['name' => 'Vendas', 'tenant_id' => '999'])->assertUnprocessable();
    }

    public function test_message_is_saved_and_queued_once_while_another_run_is_active(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->project($user);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Crie um dashboard'])->assertAccepted();
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Outra tarefa'])->assertConflict();
        Queue::assertPushed(RunAgentTurn::class, 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_approval_requires_current_fingerprint_and_owner(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->project($user);
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Publicar aplicação', 'payload' => ['resource' => 'app:1'], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
        $this->actingAs($other)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => str_repeat('a', 64)])->assertNotFound();
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => str_repeat('b', 64)])->assertConflict();
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => str_repeat('a', 64)])->assertOk();
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => str_repeat('a', 64)])->assertConflict();
    }

    public function test_expired_approval_cannot_be_used(): void
    {
        $user = User::factory()->create();
        $p = $this->project($user);
        $a = Approval::create(['user_id' => $user->id, 'project_id' => $p->id, 'title' => 'Publicar', 'payload' => [], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->subMinute()]);
        $this->actingAs($user)->postJson('/api/approvals/'.$a->id.'/decide', ['decision' => 'approved', 'fingerprint' => str_repeat('a', 64)])->assertConflict();
    }

    public function test_planning_a_schedule_never_claims_it_is_active_and_deduplicates_sources(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->update(['tenant_id' => '1']);
        $payload = ['project_id' => $project->id, 'name' => 'Carga diária', 'source_id' => 'flow:123', 'time' => '03:00'];
        $this->actingAs($user)->postJson('/api/schedules', $payload)->assertCreated()->assertJsonPath('active', false)->assertJsonPath('status', 'pending_validation');
        $this->postJson('/api/schedules', $payload)->assertCreated();
        $this->assertDatabaseCount('refresh_schedules', 1);
        $this->assertNull(RefreshSchedule::first()->last_success_at);
    }

    public function test_missing_chatgpt_connection_finishes_with_an_honest_actionable_status(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id]);
        (new RunAgentTurn($run->id))->handle(app(CodexRuntime::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'system']);
        $this->assertStringContainsString('Conecte sua conta ChatGPT', $run->fresh()->error_message);
    }

    public function test_production_does_not_allow_preview_login(): void
    {
        config(['horus.preview' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_two_conversations_cannot_mutate_the_same_project_workspace_concurrently(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Concorrência']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id]);
        $lock = Cache::lock('project:'.$project->id, 960);
        $lock->get();
        $runtime = \Mockery::mock(CodexRuntime::class);
        $runtime->shouldNotReceive('run');
        try {
            (new RunAgentTurn($run->id))->handle($runtime);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('concurrent_project_execution', $run->fresh()->error_code);
        } finally {
            $lock->release();
        }
    }

    public function test_refresh_coverage_cannot_inspect_another_users_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $p = $this->project($owner);
        $p->update(['tenant_id' => '321', 'source_ref' => 'app:100']);
        $this->mock(RefreshCoverage::class)->shouldNotReceive('inspect');
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/refresh-coverage')->assertNotFound();
    }

    public function test_read_polling_does_not_consume_the_message_rate_limit(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $p = $this->project($u);
        $this->actingAs($u);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/bootstrap')->assertOk();
        }
        for ($i = 0; $i < 15; $i++) {
            $c = Conversation::create(['user_id' => $u->id, 'project_id' => $p->id]);
            $this->postJson('/api/conversations/'.$c->id.'/messages', ['content' => 'Mensagem '.$i])->assertAccepted();
        }
        $c = Conversation::create(['user_id' => $u->id, 'project_id' => $p->id]);
        $this->postJson('/api/conversations/'.$c->id.'/messages', ['content' => 'Limite real'])->assertStatus(429);
        $this->getJson('/api/bootstrap')->assertOk();
    }

    public function test_realtime_authentication_cannot_join_another_users_private_channel(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $this->actingAs($u)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.'.$other->id])->assertForbidden();
        $this->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.'.$u->id])->assertOk()->assertJsonStructure(['auth']);
    }
}
