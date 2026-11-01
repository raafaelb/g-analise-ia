<?php

namespace Tests\Feature;

use App\Events\WorkspaceChanged;
use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiManualActionsTest extends TestCase
{
    use RefreshDatabase;

    private function connection(User $user): Connection
    {
        return Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
    }

    private function nativeState(array $ids = [1]): object
    {
        return (object) ['ids' => $ids, 'body' => ['nome' => 'Estoque de teste', '_state' => ['deskId' => null], 'tables' => [], 'dashboards' => [['id' => 21, 'widgets' => [['id' => 31, 'title' => 'Versão original']]]], 'private_reference' => 'synthetic-private-reference'], 'clones' => 0, 'deletions' => 0, 'on_info' => null];
    }

    private function native(object $state): LumoClient
    {
        $baselineTransactionLevel = DB::transactionLevel();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, ?Project $project = null) use ($state, $baselineTransactionLevel): array {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel(), 'Uma transação do portal não deve envolver a renovação nativa de autenticação.');
            $this->assertSame('100', (string) $project?->tenant_id);
            if ($args[0] === 'list' && $args[1] === 'app') {
                return ['data' => ['results' => array_map(fn (int $id): array => ['id' => $id, 'nome' => 'Aplicação '.$id], $state->ids), 'matched' => count($state->ids)]];
            }
            if ($args === ['pull', 'app:2']) {
                $path = app(WorkspaceFiles::class)->path($project).'/apps';
                File::ensureDirectoryExists($path, 0700, true);
                File::put($path.'/copia--2.yaml', Yaml::dump(['kind' => 'app', 'id' => 2, 'tenantId' => 100])."---\n".Yaml::dump($state->copied_body ?? $state->body, 30, 2));

                return ['data' => []];
            }
            if ($args === ['diff', 'app:2', '--include-remote']) {
                return ['data' => ['workingVsServer' => ['equal' => true]]];
            }
            if ($args[0] === 'info') {
                if ($args[1] === 'app:2' && $state->deletions > 0) {
                    throw new RuntimeException('Synthetic missing resource', 5);
                }
                if ($state->on_info !== null) {
                    ($state->on_info)($connection);
                }

                return ['data' => ['payload' => ['header' => ['id' => (int) substr($args[1], 4), 'kind' => 'app', 'tenantId' => 100], 'body' => $args[1] === 'app:2' ? ($state->copied_body ?? $state->body) : $state->body]]];
            }
            if ($args === ['app', 'clone', 'app:1', '--name', 'Cópia de teste']) {
                $state->clones++;
                $state->copied_body = [...$state->body, 'nome' => 'Cópia de teste'];

                return ['exit_code' => 0, 'data' => ['cloned_id' => 2]];
            }
            if ($args === ['rm', 'app:2', '--force']) {
                $state->deletions++;
                $state->ids = array_values(array_filter($state->ids, fn (int $id): bool => $id !== 2));

                return ['exit_code' => 0, 'data' => ['removed' => 'app:2']];
            }
            throw new RuntimeException('Comando inesperado no teste: '.json_encode($args));
        });

        return $mock;
    }

    private function requestData(): array
    {
        return ['action' => 'clone_app', 'name' => 'Cópia de teste', 'request_id' => (string) Str::uuid()];
    }

    private function prepare(User $user, ?array $data = null): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/1/actions', $data ?? $this->requestData())->assertCreated()->assertJsonPath('approval.status', 'pending');

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function approve(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
    }

    public function test_guests_and_viewers_cannot_prepare_manual_changes(): void
    {
        $this->postJson('/api/catalog/100/apps/1/actions', $this->requestData())->assertUnauthorized();
        $user = User::factory()->create(['role' => 'viewer']);
        $this->connection($user);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/apps/1/actions', $this->requestData())->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_preparing_a_clone_is_idempotent_and_never_creates_a_conversation_or_runs_ai(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $data = $this->requestData();
        $approval = $this->prepare($user, $data);
        $this->postJson('/api/catalog/100/apps/1/actions', $data)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);
        $this->assertSame('manual', $approval->type);
        $this->assertNull($approval->agent_run_id);
        $this->assertSame('pending', $approval->status);
        $this->assertSame('Cópia de teste', $approval->payload['action']['name']);
        $this->assertSame(64, strlen($approval->payload['revision']['source_sha256']));
        $this->assertStringNotContainsString('synthetic-private-reference', json_encode($approval->payload));
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        $this->assertSame(0, $state->clones);
        Queue::assertNothingPushed();
    }

    public function test_request_id_cannot_be_reused_for_different_input(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $this->native($this->nativeState());
        $data = $this->requestData();
        $this->prepare($user, $data);
        $this->postJson('/api/catalog/100/apps/1/actions', [...$data, 'name' => 'Outra cópia'])->assertConflict();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
    }

    public function test_tenant_and_resource_membership_are_checked_before_a_project_or_review_is_stored(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/200/apps/1/actions', $this->requestData())->assertForbidden();
        $this->postJson('/api/catalog/100/apps/99/actions', $this->requestData())->assertNotFound();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->clones);
    }

    public function test_clone_executes_once_after_review_and_records_the_new_id_without_starting_ai(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $approval = $this->prepare($user);
        Event::fake([WorkspaceChanged::class]);
        $this->approve($user, $approval);
        $job = new ExecuteApprovedAction($approval->id);
        $job->handle(app(ActionBroker::class));
        $job->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->clones);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->payload['execution']['result']['name_verification']['verified']);
        $this->assertSame('Cópia de teste', $approval->fresh()->payload['execution']['result']['confirmed_name']);
        $this->assertSame('app:2', Project::findOrFail($approval->project_id)->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'source_ref' => 'app:1', 'project_id' => $approval->project_id, 'status' => 'draft']);
        $this->assertDatabaseMissing('managed_bi_resources', ['resource' => 'app:1']);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('conversations', 0);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
        Queue::assertNotPushed(RunAgentTurn::class);
        Event::assertDispatched(WorkspaceChanged::class, fn (WorkspaceChanged $event): bool => $event->userId === $user->id && $event->runId === null);
    }

    public function test_changed_source_layout_is_rejected_even_when_name_and_dashboard_count_are_unchanged(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->approve($user, $approval);
        $state->body['dashboards'][0]['widgets'][0]['title'] = 'Alterada depois da revisão';
        $job = new ExecuteApprovedAction($approval->id);
        $job->handle(app(ActionBroker::class));
        $job->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('mudou após a revisão', $approval->fresh()->payload['execution']['message']);
        $this->assertSame(0, $state->clones);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_new_bi_session_requires_a_new_review_before_cloning(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->approve($user, $approval);
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->clones);
    }

    public function test_role_revocation_and_expired_decisions_cannot_execute_the_operation(): void
    {
        Queue::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $this->connection($user);
        $state = $this->nativeState();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->travel(13)->minutes();
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertConflict();
        Queue::assertNothingPushed();
        $approval->update(['expires_at' => now()->addMinutes(10), 'status' => 'approved']);
        $user->forceFill(['role' => 'viewer'])->save();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->clones);
    }

    public function test_native_session_renewal_is_preserved_when_review_preparation_fails(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $state = $this->nativeState();
        $state->on_info = static function (Connection $current): void {
            $current->update(['credentials' => ['auth' => ['token' => 'synthetic-renewed']]]);
            throw new RuntimeException('Falha sintética após a renovação.');
        };
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/apps/1/actions', $this->requestData())->assertUnprocessable();
        $this->assertSame('synthetic-renewed', $connection->fresh()->credentials['auth']['token']);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_preexisting_and_other_users_drafts_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $other = User::factory()->create();
        $project = Project::create(['user_id' => $other->id, 'tenant_id' => '100', 'name' => 'Projeto de outra conta']);
        ManagedBiResource::create(['user_id' => $other->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2', 'origin' => 'clone_app', 'receipt' => []]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        foreach ([1, 2] as $id) {
            $this->actingAs($user)->postJson('/api/catalog/100/apps/'.$id.'/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'draft']);
    }

    public function test_own_recorded_draft_is_deleted_once_with_a_checkpoint_without_calling_ai(): void
    {
        Queue::fake();
        $root = storage_path('framework/testing/manual-delete-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $user = User::factory()->create();
        $this->connection($user);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:2', 'name' => 'Cópia gerenciada']);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2', 'origin' => 'clone_app', 'receipt' => []]);
        $path = app(WorkspaceFiles::class)->path($project);
        File::ensureDirectoryExists($path.'/apps');
        $state = $this->nativeState([2]);
        File::put($path.'/apps/copia--2.yaml', Yaml::dump(['kind' => 'app', 'id' => 2, 'tenantId' => 100])."---\n".Yaml::dump($state->body, 30, 2));
        $this->native($state);
        try {
            $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertCreated();
            $approval = Approval::findOrFail($response->json('approval.id'));
            $this->approve($user, $approval);
            $job = new ExecuteApprovedAction($approval->id);
            $job->handle(app(ActionBroker::class));
            $job->handle(app(ActionBroker::class));
            $this->assertSame('completed', $approval->fresh()->status);
            $this->assertSame(1, $state->deletions);
            $this->assertNull($project->fresh()->source_ref);
            $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'deleted']);
            $this->assertDirectoryExists($path.'/.git');
            $this->assertFileExists($path.'/apps/copia--2.yaml');
            $this->assertDatabaseCount('agent_runs', 0);
            Queue::assertNotPushed(RunAgentTurn::class);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_manual_status_is_private_and_backup_waits_for_approved_manual_work(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user);
        $this->native($this->nativeState());
        $approval = $this->prepare($user);
        $this->getJson('/api/bi-actions/'.$approval->id)->assertOk()->assertJsonPath('approval.id', $approval->id);
        $this->getJson('/api/bi-actions/not-a-uuid')->assertNotFound();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/bi-actions/'.$approval->id)->assertNotFound();
        $this->approve($user, $approval);
        $this->artisan('ganalise:backup-state')->assertExitCode(2);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->artisan('ganalise:backup-state')->assertSuccessful();
    }

    public function test_openai_disconnect_preserves_manual_bi_reviews_but_bi_disconnect_cancels_them(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user);
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-only']]);
        $this->native($this->nativeState());
        $approval = $this->prepare($user);
        $this->mock(OpenAiIdentity::class)->shouldReceive('revoke')->once()->andReturn(true);
        $this->deleteJson('/api/connections/openai')->assertOk();
        $this->assertSame('pending', $approval->fresh()->status);
        $this->deleteJson('/api/connections/horus')->assertOk();
        $this->assertSame('cancelled', $approval->fresh()->status);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        Queue::assertNothingPushed();
    }
}
