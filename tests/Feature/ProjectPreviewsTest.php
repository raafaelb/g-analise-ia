<?php

namespace Tests\Feature;

use App\Events\WorkspaceChanged;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
}
