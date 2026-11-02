<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BiWorkspaceSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = storage_path('framework/testing/workspace-sync-'.Str::uuid());
        config(['horus.workspace_root' => $this->workspaceRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaceRoot);
        parent::tearDown();
    }

    private function context(string $kind = 'app', string $folder = 'apps'): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => $kind.':2', 'name' => 'Manutenção de teste']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
        $root = app(WorkspaceFiles::class)->path($project);
        File::ensureDirectoryExists($root.'/'.$folder, 0700, true);
        $original = "lumo: v2\nkind: ".$kind."\nid: 2\ntenantId: 100\n---\nnome: Aplicacao de teste\ndescricao: Versao anterior\n";
        $path = $root.'/'.$folder.'/application--2.yaml';
        File::put($path, $original);
        $state = (object) ['dirty' => false, 'missing' => false, 'current' => false, 'listed' => true, 'pulled' => 0, 'commands' => [], 'valid' => true, 'changed_again' => false, 'switch_session' => false, 'root' => $root, 'path' => $path, 'original' => $original, 'server' => str_replace('Versao anterior', 'Versao atual do BI', $original), 'kind' => $kind];

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
                if (isset($state->during_diff)) {
                    ($state->during_diff)();
                    unset($state->during_diff);
                }
                if ($state->switch_session) {
                    $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
                }
                if (! $state->valid) {
                    return ['data' => ['workingVsState' => ['equal' => true]]];
                }

                $missing = $state->pulled === 0 && $state->missing;

                return ['data' => ['workingMissing' => $missing, 'stateVsServer' => ['equal' => true], 'workingVsState' => $missing ? null : ['equal' => ! $state->dirty], 'workingVsServer' => $missing ? null : ['equal' => ($state->matches_server ?? false) || (! $state->dirty && ($state->current || ($state->pulled > 0 && ! $state->changed_again)))]]];
            }
            if ($args === ['pull', $state->kind.':2', '--discard-local'] || $args === ['pull', $state->kind.':2']) {
                if ($args === ['pull', $state->kind.':2'] && ! $state->missing) {
                    $state->dirty = true;
                    throw new RuntimeException('O Lumo atualizou a base de comparação e preservou a versão local anterior.');
                }
                if ($state->native_conflict ?? false) {
                    $state->dirty = true;
                    throw new RuntimeException('Conflito detectado pelo Lumo. Os arquivos foram preservados.');
                }
                $state->pulled++;
                $state->dirty = false;
                File::put($state->path, $state->server);

                return ['data' => ['pulled' => [$state->kind.':2']]];
            }
            throw new RuntimeException('Comando inesperado: '.json_encode($args));
        });
    }

    public function test_clean_authorship_is_updated_and_confirmed_without_remote_writes_or_an_ai_task(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('updated', true)->assertJsonPath('verified', true);
        $this->assertSame(1, $state->pulled);
        $this->assertSame([['list', 'app', '--remote', '--limit', '500', '--offset', '0'], ['diff', 'app:2', '--include-remote'], ['pull', 'app:2', '--discard-local'], ['diff', 'app:2', '--include-remote']], $state->commands);
        $this->assertSame($state->server, File::get($state->path));
        $checkpoint = AuditEvent::where('action', 'bi.workspace.refresh_checkpoint')->sole();
        $process = new Process(['git', 'show', $checkpoint->metadata['commit'].':apps/application--2.yaml'], $state->root);
        $process->mustRun();
        $this->assertSame($state->original, $process->getOutput());
        $this->assertDatabaseHas('audit_events', ['action' => 'bi.workspace.refresh_checkpoint_closed', 'resource_id' => $project->id]);
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

    #[TestWith(['flow', 'flows'])]
    #[TestWith(['table', 'tables'])]
    #[TestWith(['schedule', 'schedules'])]
    #[TestWith(['agent', 'agents'])]
    #[TestWith(['bi-desk', 'bi-desks'])]
    #[TestWith(['dw-desk', 'dw-desks'])]
    public function test_refresh_preserves_and_receives_only_the_selected_resource_kind(string $kind, string $folder): void
    {
        [$user, $project, , $state] = $this->context($kind, $folder);
        $this->native($state);

        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('verified', true);
        $this->assertSame($state->server, File::get($state->path));
        $this->assertContains(['pull', $kind.':2', '--discard-local'], $state->commands);
        $checkpoint = AuditEvent::where('action', 'bi.workspace.refresh_checkpoint')->sole();
        $snapshot = new Process(['git', 'show', $checkpoint->metadata['commit'].':'.$folder.'/application--2.yaml'], $state->root);
        $snapshot->mustRun();
        $this->assertSame($state->original, $snapshot->getOutput());
    }

    public function test_a_file_already_equal_to_the_server_can_repair_an_outdated_native_base_without_losing_content(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->dirty = true;
        $state->matches_server = true;
        File::put($state->path, $state->server);
        $this->native($state);

        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('verified', true);
        $this->assertSame($state->server, File::get($state->path));
        $this->assertSame(1, $state->pulled);
    }

    public function test_a_missing_working_file_can_be_received_without_discarding_local_edits(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->missing = true;
        File::delete($state->path);
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('verified', true);
        $this->assertSame(1, $state->pulled);
        $this->assertContains(['pull', 'app:2'], $state->commands);
        $this->assertNotContains(['pull', 'app:2', '--discard-local'], $state->commands);
        $this->assertFalse(is_dir($state->root.'/.git'));
    }

    public function test_an_up_to_date_resource_is_not_rewritten(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->current = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'current')->assertJsonPath('updated', false)->assertJsonPath('verified', true);
        $this->assertSame(0, $state->pulled);
        $this->assertSame($state->original, File::get($state->path));
        $this->assertDatabaseCount('audit_events', 0);
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

    public function test_an_interrupted_receive_reuses_its_verified_checkpoint_when_the_native_base_advanced(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $this->assertSame($state->original, File::get($state->path));
        $this->assertDatabaseHas('audit_events', ['action' => 'bi.workspace.refresh_checkpoint', 'resource_id' => $project->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.workspace.refresh_checkpoint_closed']);

        $state->native_conflict = false;
        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('verified', true);
        $this->assertSame($state->server, File::get($state->path));
        $this->assertSame(1, AuditEvent::where('action', 'bi.workspace.refresh_checkpoint')->count());
        $this->assertSame(1, AuditEvent::where('action', 'bi.workspace.refresh_checkpoint_closed')->count());
        $this->assertSame(1, $state->pulled);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_an_edit_after_an_interrupted_receive_is_preserved_even_with_a_checkpoint(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $edited = $state->original."descricao_local: Edicao ainda nao sincronizada\n";
        File::put($state->path, $edited);
        $state->native_conflict = false;

        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes')->assertJsonPath('updated', false);
        $this->assertSame($edited, File::get($state->path));
        $this->assertSame(1, count(array_filter($state->commands, fn (array $command): bool => $command[0] === 'pull')));
    }

    public function test_a_consumed_checkpoint_cannot_overwrite_a_later_local_rollback_to_identical_old_bytes(): void
    {
        [$user, $project, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('verified', true);
        File::put($state->path, $state->original);
        $state->dirty = true;

        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(1, $state->pulled);
    }

    public function test_a_current_noop_closes_an_unused_checkpoint_before_future_edits(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $state->dirty = false;
        $state->current = true;
        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'current');
        $this->assertDatabaseHas('audit_events', ['action' => 'bi.workspace.refresh_checkpoint_closed', 'resource_id' => $project->id]);

        $state->dirty = true;
        $state->current = false;
        $state->native_conflict = false;
        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(0, $state->pulled);
    }

    #[TestWith(['session'])]
    #[TestWith(['access'])]
    #[TestWith(['connection'])]
    public function test_a_checkpoint_from_previous_access_cannot_replace_files_in_a_new_context(string $change): void
    {
        [$user, $project, $connection, $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $state->native_conflict = false;
        if ($change === 'session') {
            $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        } elseif ($change === 'access') {
            $user->forceFill(['access_version' => 1])->save();
            $this->withSession(['user_access_version' => 1]);
        } else {
            $metadata = $connection->metadata;
            $connection->delete();
            Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'another-synthetic-only']], 'metadata' => $metadata]);
        }

        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(0, $state->pulled);
        $this->assertSame(1, count(array_filter($state->commands, fn (array $command): bool => $command[0] === 'pull')));
    }

    public function test_a_checkpoint_does_not_transfer_to_another_project_with_the_same_resource_and_content(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $other = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:2', 'name' => 'Outro projeto']);
        $root = app(WorkspaceFiles::class)->path($other);
        File::ensureDirectoryExists($root.'/apps', 0700, true);
        File::put($root.'/apps/application--2.yaml', $state->original);
        $state->native_conflict = false;

        $this->postJson('/api/projects/'.$other->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes');
        $this->assertSame($state->original, File::get($root.'/apps/application--2.yaml'));
        $this->assertFalse(is_dir($root.'/.git'));
        $this->assertSame(0, $state->pulled);
    }

    public function test_a_modified_checkpoint_receipt_is_not_trusted(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        $checkpoint = AuditEvent::where('action', 'bi.workspace.refresh_checkpoint')->sole();
        $checkpoint->update(['metadata' => [...$checkpoint->metadata, 'commit' => str_repeat('a', 40)]]);
        $state->native_conflict = false;

        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('status', 'local_changes');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(0, $state->pulled);
    }

    public function test_a_missing_git_checkpoint_prevents_a_retry_from_replacing_files(): void
    {
        [$user, $project, , $state] = $this->context();
        $state->native_conflict = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable();
        File::deleteDirectory($state->root.'/.git');
        $state->native_conflict = false;

        $this->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonPath('errors.sync.0', 'O checkpoint preservado não pôde ser conferido. Nenhum arquivo será substituído.');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(0, $state->pulled);
    }

    public function test_an_edit_during_the_native_comparison_is_preserved(): void
    {
        [$user, $project, , $state] = $this->context();
        $edited = $state->original."descricao_local: Edicao concorrente\n";
        $state->during_diff = fn () => File::put($state->path, $edited);
        $this->native($state);

        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonValidationErrors('sync');
        $this->assertSame($edited, File::get($state->path));
        $this->assertSame(0, $state->pulled);
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.workspace.refresh_checkpoint']);
    }

    #[TestWith(['wrong-tenant'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['malformed'])]
    #[TestWith(['symlink'])]
    public function test_unidentified_or_ambiguous_authorship_is_never_discarded(string $variant): void
    {
        [$user, $project, , $state] = $this->context();
        if ($variant === 'wrong-tenant') {
            File::put($state->path, str_replace('tenantId: 100', 'tenantId: 200', $state->original));
        } elseif ($variant === 'duplicate') {
            File::put($state->root.'/apps/duplicate--2.yaml', $state->original);
        } elseif ($variant === 'malformed') {
            File::put($state->path, 'invalid: [');
        } else {
            symlink($state->path, $state->root.'/apps/linked--2.yaml');
        }
        $expected = File::get($state->path);
        $this->native($state);
        try {
            $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonValidationErrors('sync');
            $this->assertSame($expected, File::get($state->path));
            $this->assertSame(0, $state->pulled);
        } finally {
            if ($variant === 'symlink') {
                unlink($state->root.'/apps/linked--2.yaml');
            }
        }
    }

    public function test_an_external_git_directory_pointer_prevents_receiving_a_version(): void
    {
        [$user, $project, , $state] = $this->context();
        File::put($state->root.'/.git', 'gitdir: /unrelated-directory');
        $this->native($state);

        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertUnprocessable()->assertJsonValidationErrors('sync');
        $this->assertSame($state->original, File::get($state->path));
        $this->assertSame(0, $state->pulled);
    }

    public function test_the_checkpoint_preserves_only_the_target_and_does_not_commit_unrelated_staged_files(): void
    {
        [$user, $project, , $state] = $this->context();
        File::ensureDirectoryExists($state->root.'/notes', 0700, true);
        File::put($state->root.'/notes/unrelated.txt', 'Edicao separada');
        (new Process(['git', 'init', '--quiet'], $state->root))->mustRun();
        (new Process(['git', 'add', '--', 'notes/unrelated.txt'], $state->root))->mustRun();
        $this->native($state);

        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/sync-resource')->assertOk()->assertJsonPath('verified', true);
        $checkpoint = AuditEvent::where('action', 'bi.workspace.refresh_checkpoint')->sole();
        $tree = new Process(['git', 'ls-tree', '--name-only', '-r', $checkpoint->metadata['commit']], $state->root);
        $tree->mustRun();
        $this->assertSame("apps/application--2.yaml\n", $tree->getOutput());
        $staged = new Process(['git', 'diff', '--cached', '--name-only'], $state->root);
        $staged->mustRun();
        $this->assertSame("notes/unrelated.txt\n", $staged->getOutput());
        $this->assertSame('Edicao separada', File::get($state->root.'/notes/unrelated.txt'));
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
