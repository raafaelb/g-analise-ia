<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BiWorkspaceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:2', 'name' => 'Manutenção de teste']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
        $state = (object) ['dirty' => false, 'missing' => false, 'current' => false, 'listed' => true, 'pulled' => 0, 'commands' => [], 'valid' => true, 'changed_again' => false, 'switch_session' => false];

        return [$user, $project, $connection, $state];
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->withArgs(fn (Connection $connection, Project $project, bool $readOnly): bool => $readOnly)->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args) use ($state): array {
            $state->commands[] = $args;
            if ($args[0] === 'list') {
                return ['data' => ['results' => $state->listed ? [['id' => 2, 'nome' => 'Aplicação de teste']] : [], 'matched' => $state->listed ? 1 : 0]];
            }
            if ($args[0] === 'diff') {
                if ($state->switch_session) {
                    $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
                }
                if (! $state->valid) {
                    return ['data' => ['workingVsState' => ['equal' => true]]];
                }

                $missing = $state->pulled === 0 && $state->missing;

                return ['data' => ['workingMissing' => $missing, 'stateVsServer' => ['equal' => true], 'workingVsState' => $missing ? null : ['equal' => ! $state->dirty], 'workingVsServer' => $missing ? null : ['equal' => ! $state->dirty && ($state->current || ($state->pulled > 0 && ! $state->changed_again))]]];
            }
            if ($args === ['pull', 'app:2']) {
                if ($state->native_conflict ?? false) {
                    throw new RuntimeException('Conflito detectado pelo Lumo. Os arquivos foram preservados.');
                }
                $state->pulled++;

                return ['data' => ['pulled' => ['app:2']]];
            }
            throw new RuntimeException('Comando inesperado: '.json_encode($args));
        });
    }

    public function test_clean_authorship_is_updated_and_confirmed_without_remote_writes_or_an_ai_task(): void
    {
        Queue::fake();
        [$user, $project, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('updated', true)->assertJsonPath('verified', true);
        $this->assertSame(1, $state->pulled);
        $this->assertSame([['list', 'app', '--remote', '--limit', '500', '--offset', '0'], ['diff', 'app:2', '--include-remote'], ['pull', 'app:2'], ['diff', 'app:2', '--include-remote']], $state->commands);
        $this->assertDatabaseHas('audit_events', ['action' => 'bi.workspace.refreshed', 'resource_id' => $project->id]);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_dirty_authorship_is_preserved_and_never_pulled(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->dirty = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes')->assertJsonPath('updated', false)->assertJsonPath('message', 'Há edições locais ainda não sincronizadas. Elas foram preservadas. Revise as diferenças com a IA antes de atualizar a versão do BI.');
        $this->assertSame(0, $state->pulled);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_missing_working_file_can_be_received_without_discarding_local_edits(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->missing = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('verified', true);
        $this->assertSame(1, $state->pulled);
    }

    public function test_an_up_to_date_resource_is_not_rewritten(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->current = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'current')->assertJsonPath('updated', false)->assertJsonPath('verified', true);
        $this->assertSame(0, $state->pulled);
    }

    public function test_a_server_change_after_pull_is_reported_without_claiming_success_or_retrying(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->changed_again = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'changed_again')->assertJsonPath('updated', true)->assertJsonPath('verified', false);
        $this->assertSame(1, $state->pulled);
    }

    public function test_unknown_diff_data_does_not_replace_files(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->valid = false;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonPath('errors.sync.0', 'Não foi possível confirmar as diferenças locais. Os arquivos não serão substituídos.');
        $this->assertSame(0, $state->pulled);
    }

    public function test_a_native_conflict_is_not_overridden_or_retried(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonPath('errors.sync.0', 'Conflito detectado pelo Lumo. Os arquivos foram preservados.');
        $this->assertSame(0, $state->pulled);
        $this->assertSame(1, count(array_filter($state->commands, fn (array $command): bool => $command[0] === 'pull')));
    }

    public function test_a_session_change_before_pull_preserves_files(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->switch_session = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonPath('errors.sync.0', 'A sessão mudou durante a atualização. Confira seus acessos e as diferenças antes de continuar.');
        $this->assertSame(0, $state->pulled);
    }

    public function test_a_resource_outside_current_tenant_access_is_not_pulled(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->listed = false;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertNotFound();
        $this->assertSame(0, $state->pulled);
    }

    public function test_authentication_is_required(): void
    {
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->postJson('/api/projects/'.Str::uuid().'/sync-resource')->assertUnauthorized();
    }

    public function test_a_foreign_project_is_hidden_before_any_native_call(): void
    {
        [, $project] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs(User::factory()->create())->postJson('/api/projects/'.$project->id.'/sync-resource')->assertNotFound();
    }

    public function test_a_viewer_cannot_change_authoring_files(): void
    {
        [$user, $project] = $this->context();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertForbidden();
    }

    public function test_an_archived_project_is_not_updated(): void
    {
        [$user, $project] = $this->context();
        $project->update(['status' => 'archived']);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertConflict();
    }

    public function test_a_pending_review_prevents_its_authorship_from_being_replaced(): void
    {
        [$user, $project] = $this->context();
        Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'type' => 'manual', 'title' => 'Revisão pendente', 'description' => 'Teste', 'payload' => [], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5)]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertConflict()->assertJsonPath('message', 'Conclua ou recuse a revisão pendente antes de atualizar a versão do BI.');
    }

    public function test_an_active_agent_task_prevents_manual_refresh(): void
    {
        [$user, $project] = $this->context();
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'queued']);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertConflict()->assertJsonPath('message', 'Aguarde a tarefa de IA terminar antes de atualizar a versão do BI.');
    }

    public function test_the_agent_uses_the_same_safe_refresh_without_a_write_approval(): void
    {
        [$user, $project, , $state] = $this->context();
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->native($state);
        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'refresh_resource', 'resource' => 'app:2']);
        $this->assertSame('updated', $result['status']);
        $this->assertSame(1, $state->pulled);
        $this->assertDatabaseCount('approvals', 0);
    }
}
