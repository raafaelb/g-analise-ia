<?php

namespace Tests\Feature;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\LumoClient;
use App\Services\ProjectPreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/project-previews-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
        Storage::fake('local');
        Event::fake([WorkspaceChanged::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(string $role = 'editor', bool $expectExport = true, bool $validImage = true): array
    {
        $user = User::factory()->create(['role' => $role]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Teste de prévias', 'tenant_id' => '321', 'source_ref' => 'app:10']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'Teste']]]]);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => ($args[0] ?? '') === 'app' && ($args[1] ?? '') === 'export')->times($expectExport ? 1 : 0)->andReturnUsing(function ($connection, $args, $context) use ($user, $validImage) {
            $this->assertSame($user->id, $connection->user_id);
            $this->assertSame('321', $context->tenant_id);
            $this->assertSame('app:10', $args[2]);
            $target = $args[array_search('--output', $args, true) + 1];
            File::put($target, $validImage ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=') : 'not-an-image');

            return ['data' => ['ok' => true]];
        });
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => ! (($args[0] ?? '') === 'app' && ($args[1] ?? '') === 'export'))->andReturnUsing(function ($c, $args) {
            if ($args[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['tenantId' => 321], 'body' => ['nome' => 'Estoque de teste', 'dashboards' => [['header' => ['id' => 99], 'body' => ['nome' => 'Mensal', 'widgets' => []]]]]]]];
            }
            if ($args[0] === 'list') {
                return ['data' => $args[1] === 'variables' ? ['count' => 0, 'variables' => []] : ['matched' => $args[1] === 'app' ? 1 : 0, 'results' => $args[1] === 'app' ? [['id' => 10, 'nome' => 'Estoque de teste']] : []]];
            }

            return ['data' => []];
        });
        $this->app->instance(LumoClient::class, $mock);

        return [$user, $project];
    }

    public function test_preview_is_a_private_immutable_image_without_ai_consent_or_inference(): void
    {
        [$user, $project] = $this->context();
        $response = $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/preview', ['dashboard' => 99])->assertCreated()->assertJsonPath('artifact.kind', 'dashboard_export')->assertJsonPath('artifact.project_id', $project->id)->assertJsonPath('artifact.metadata.resource', 'app:10')->assertJsonPath('artifact.metadata.dashboard_id', 99)->assertJsonMissingPath('artifact.metadata.visual_consent');
        $artifact = Artifact::findOrFail($response->json('artifact.id'));
        Storage::disk('local')->assertExists($artifact->path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($artifact->path)), $artifact->metadata['sha256']);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertSame([], glob($this->root.'/'.$user->id.'/catalog-321-initial/exports/project-previews/*.png'));
        Event::assertDispatched(WorkspaceChanged::class, fn ($event) => $event->userId === $user->id && $event->runId === null);
        $this->get('/api/artifacts/'.$artifact->id.'/download')->assertOk();
        $this->actingAs(User::factory()->create())->get('/api/artifacts/'.$artifact->id.'/download')->assertNotFound();
    }

    public function test_invalid_image_leaves_no_artifact_or_temporary_file(): void
    {
        [$user, $project] = $this->context(validImage: false);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/preview')->assertUnprocessable();
        $this->assertDatabaseCount('artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], glob($this->root.'/'.$user->id.'/catalog-321-initial/exports/project-previews/*.png'));
        Event::assertNotDispatched(WorkspaceChanged::class);
    }

    public function test_foreign_project_and_dashboard_are_rejected_before_export(): void
    {
        [$user, $project] = $this->context(expectExport: false);
        $this->actingAs(User::factory()->create())->postJson('/api/projects/'.$project->id.'/preview')->assertNotFound();
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/preview', ['dashboard' => 100])->assertUnprocessable();
        $project->update(['tenant_id' => '999']);
        $this->postJson('/api/projects/'.$project->id.'/preview')->assertForbidden();
        $this->assertDatabaseCount('artifacts', 0);
    }

    public function test_source_resource_cannot_be_overridden_from_the_request_body(): void
    {
        [$user, $project] = $this->context();
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/preview', ['resource' => 'app:999'])->assertCreated()->assertJsonPath('artifact.metadata.resource', 'app:10');
        $this->assertSame('app:10', $project->fresh()->source_ref);
    }

    public function test_preview_of_existing_data_is_allowed_for_a_viewer_and_read_only_tenant(): void
    {
        [$user, $project] = $this->context(role: 'viewer');
        config(['horus.read_only_tenants' => ['321']]);
        $this->actingAs($user)->postJson('/api/projects/'.$project->id.'/preview')->assertCreated();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_dashboard_choices_are_authorized_without_creating_files_or_ai_tasks(): void
    {
        [$user, $project] = $this->context(expectExport: false);
        $this->actingAs($user)->getJson('/api/projects/'.$project->id.'/preview-context')->assertOk()->assertJsonPath('resource', 'app:10')->assertJsonPath('dashboards.0.id', 99)->assertJsonPath('dashboards.0.name', 'Mensal')->assertJsonMissingPath('details');
        $this->assertDatabaseCount('artifacts', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->actingAs(User::factory()->create())->getJson('/api/projects/'.$project->id.'/preview-context')->assertNotFound();
    }

    private function change(Project $project, string $action = 'push', array $result = [], string $status = 'completed', ?string $resource = null): Approval
    {
        return Approval::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'title' => 'Revisão sintética', 'status' => $status, 'payload' => ['action' => ['action' => $action, 'resource' => $resource ?? 'app:10'], 'revision' => ['tenant_id' => '321'], 'execution' => ['status' => 'completed', 'result' => $result]], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
    }

    public static function previewChanges(): array
    {
        return [
            'app push' => ['push', 'app:10', [], true],
            'confirmed publication' => ['publish_app', 'app:9', ['publication' => ['verified' => true], 'published_resource' => 'app:10'], true],
            'unconfirmed publication' => ['publish_app', 'app:10', ['published_resource' => 'app:10'], false],
            'new app clone' => ['clone_app', 'app:9', ['managed_resource' => 'app:10'], true],
            'another app' => ['push', 'app:9', [], false],
            'flow authoring is not an app checkpoint' => ['push', 'flow:10', [], false],
            'queued load is not a completed refresh' => ['run_schedule', 'schedule:10', ['execution_request' => ['verified' => false]], false],
        ];
    }

    #[DataProvider('previewChanges')]
    public function test_only_confirmed_changes_to_the_current_app_update_its_preview(string $action, string $resource, array $result, bool $expected): void
    {
        [$user, $project] = $this->context(expectExport: false);
        $approval = $this->change($project, $action, $result, resource: $resource);

        $changed = app(ProjectPreviews::class)->recordCompletedChange($approval);

        $this->assertSame($expected, $changed);
        $this->assertSame($expected ? $approval->id : null, $project->fresh()->preview_revision);
        $this->assertDatabaseCount('artifacts', 0);
    }

    public function test_pending_in_progress_and_failed_actions_do_not_change_the_preview(): void
    {
        [, $project] = $this->context(expectExport: false);
        foreach (['pending', 'approved', 'executing', 'failed', 'cancelled', 'rejected'] as $status) {
            $approval = $this->change($project, status: $status);
            $this->assertFalse(app(ProjectPreviews::class)->recordCompletedChange($approval));
            $this->assertNull($project->fresh()->preview_revision);
        }
        $approval = $this->change($project);
        $approval->update(['payload' => [...$approval->payload, 'execution' => ['status' => 'failed']]]);
        $this->assertFalse(app(ProjectPreviews::class)->recordCompletedChange($approval));
        $this->assertNull($project->fresh()->preview_revision);
    }

    public function test_a_checkpoint_cannot_cross_user_or_tenant_and_repeating_it_is_idempotent(): void
    {
        [, $project] = $this->context(expectExport: false);
        $approval = $this->change($project);
        $other = User::factory()->create();
        $approval->update(['user_id' => $other->id]);
        $this->assertFalse(app(ProjectPreviews::class)->recordCompletedChange($approval));
        $this->assertNull($project->fresh()->preview_revision);
        $approval->update(['user_id' => $project->user_id, 'payload' => [...$approval->payload, 'revision' => ['tenant_id' => 'other-tenant']]]);
        $this->assertFalse(app(ProjectPreviews::class)->recordCompletedChange($approval));
        $this->assertNull($project->fresh()->preview_revision);
        $approval->update(['payload' => [...$approval->payload, 'revision' => ['tenant_id' => '321']]]);
        $this->assertTrue(app(ProjectPreviews::class)->recordCompletedChange($approval));
        $this->assertFalse(app(ProjectPreviews::class)->recordCompletedChange($approval));
        $this->assertSame($approval->id, $project->fresh()->preview_revision);
    }

    public function test_project_settings_cannot_forge_a_confirmed_preview_revision(): void
    {
        [$user, $project] = $this->context(expectExport: false);
        $revision = '11111111-1111-4111-8111-111111111111';
        $project->update(['preview_revision' => $revision]);

        $this->actingAs($user)->patchJson('/api/projects/'.$project->id, ['name' => 'Nome atualizado', 'preview_revision' => '22222222-2222-4222-8222-222222222222', 'preview_changed_at' => '2099-01-01T00:00:00Z'])
            ->assertOk()->assertJsonPath('name', 'Nome atualizado')->assertJsonPath('preview_revision', $revision)->assertJsonPath('preview_changed_at', null);

        $this->assertSame($revision, $project->fresh()->preview_revision);
        $this->assertNull($project->fresh()->preview_changed_at);
    }
}
