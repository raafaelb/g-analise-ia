<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/catalog-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connect(User $user, string $tenant = '321'): Connection
    {
        return Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => $tenant, 'name' => 'Homologação']]]]);
    }

    private function fakeLumo(array $apps = [], array $bodies = []): void
    {
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($c, $args, $p = null, $timeout = 45) use ($apps, $bodies) {
            if ($args[0] === 'info') {
                return ['exit_code' => 0, 'data' => ['payload' => ['header' => ['tenantId' => (int) $p->tenant_id], 'body' => $bodies[$args[1]] ?? ['nome' => 'App autorizado', 'dashboards' => []]]]];
            }
            if (($args[1] ?? '') === 'variables') {
                return ['data' => ['count' => 0, 'variables' => []]];
            }
            $rows = ($args[0] === 'list' && $args[1] === 'app') ? $apps : [];
            if ($args[0] === 'list') {
                foreach ($bodies as $reference => $body) {
                    [$type, $id] = explode(':', $reference);
                    if ($args[1] === $type && ! collect($rows)->contains(fn ($row) => (string) $row['id'] === $id)) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome'] ?? $reference];
                    }
                }
            }

            return ['exit_code' => 0, 'data' => ['count' => count($rows), 'matched' => count($rows), 'results' => $rows]];
        });
        $this->app->instance(LumoClient::class, $mock);
    }

    public function test_guest_cannot_read_catalog(): void
    {
        $this->getJson('/api/catalog/321')->assertUnauthorized();
    }

    public function test_unknown_tenant_is_rejected_before_any_cli_request(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldNotReceive('initialize');
        $mock->shouldNotReceive('run');
        $this->app->instance(LumoClient::class, $mock);
        $this->actingAs($user)->getJson('/api/catalog/999')->assertForbidden();
    }

    public function test_catalog_does_not_use_another_users_connection(): void
    {
        $owner = User::factory()->create();
        $this->connect($owner);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/catalog/321')->assertUnprocessable();
    }

    public function test_apps_and_my_desk_are_derived_from_remote_resources(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $this->fakeLumo([['id' => 1, 'nome' => 'Rascunho', 'deskId' => null], ['id' => 2, 'nome' => 'Publicado', 'deskId' => 7]]);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk()->assertJsonPath('resources.app.0.state', 'draft')->assertJsonPath('resources.app.1.state', 'published')->assertJsonPath('resources.app.1.desk_id', '7')->assertJsonPath('capabilities.app.count', 2)->assertJsonMissingPath('credentials');
    }

    public function test_working_yaml_enriches_dashboards_and_tags_without_reading_internal_state(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $this->fakeLumo([['id' => 1, 'nome' => 'Comercial', 'deskId' => 7]]);
        $path = $this->root.'/'.$user->id.'/catalog-321-initial/apps';
        File::ensureDirectoryExists($path, 0700, true);
        File::put($path.'/comercial--1.yaml', "id: 1\nkind: app\nlumo: v2\ntenantId: 321\n---\nnome: Comercial\ndeskId: 7\ntags: [vendas, metas]\ndashboards:\n  - id: 10\n    nome: Receita\n    widgets: []\n");
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk()->assertJsonPath('resources.app.0.dashboard_count', 1)->assertJsonPath('resources.app.0.dashboards.0.name', 'Receita')->assertJsonPath('tags.0', 'metas');
    }

    public function test_permission_refusal_is_shown_as_unavailable_instead_of_zero(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($c, $a) {
            if (($a[1] ?? '') === 'agent') {
                throw new \RuntimeException('Sem permissão', 6);
            }

            return ['data' => ['matched' => 0, 'results' => []]];
        });
        $this->app->instance(LumoClient::class, $mock);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk()->assertJsonPath('capabilities.agent.status', 'access_denied')->assertJsonPath('capabilities.agent.count', null);
    }

    public function test_snapshot_cache_is_separate_for_each_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->connect($a);
        $this->connect($b);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(fn ($c, $args) => ['data' => ['matched' => ($args[1] ?? '') === 'app' ? 1 : 0, 'results' => ($args[1] ?? '') === 'app' ? [['id' => $c->user_id, 'nome' => 'Privado '.$c->user_id]] : []]]);
        $this->app->instance(LumoClient::class, $mock);
        $this->actingAs($a)->getJson('/api/catalog/321')->assertJsonPath('resources.app.0.id', (string) $a->id);
        $this->actingAs($b)->getJson('/api/catalog/321')->assertJsonPath('resources.app.0.id', (string) $b->id);
    }

    public function test_a_project_can_be_bound_to_an_authorized_resource_without_remote_mutation(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $this->fakeLumo([['id' => 1, 'nome' => 'App autorizado']]);
        $response = $this->actingAs($user)->postJson('/api/catalog/321/projects', ['resource' => 'app:1'])->assertCreated();
        $this->assertDatabaseHas('projects', ['id' => $response->json('project.id'), 'user_id' => $user->id, 'tenant_id' => '321', 'source_ref' => 'app:1']);
    }

    public function test_viewer_cannot_create_project_from_catalog(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->connect($user);
        $this->actingAs($user)->postJson('/api/catalog/321/projects', ['resource' => 'app:1'])->assertForbidden();
    }

    public function test_resource_details_redact_secrets(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $this->fakeLumo([], ['flow:1' => ['nome' => 'Fonte', 'connection' => ['password' => 'never-expose', 'api_key' => 'never-expose']]]);
        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/1')->assertOk();
        $this->assertStringNotContainsString('never-expose', $response->getContent());
    }

    public function test_foreign_tenant_header_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(fn ($c, $args) => ['data' => $args[0] === 'list' ? ['matched' => 1, 'results' => [['id' => 1]]] : ['payload' => ['header' => ['tenantId' => 999], 'body' => ['nome' => 'Outro tenant']]]]);
        $this->app->instance(LumoClient::class, $mock);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/app/1')->assertUnprocessable();
    }

    public function test_incomplete_remote_list_is_rejected(): void
    {
        $user = User::factory()->create();
        $c = $this->connect($user);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('run')->andReturn(['data' => ['matched' => 2, 'results' => [['id' => 1]]]]);
        $this->app->instance(LumoClient::class, $mock);
        $p = new Project(['user_id' => $user->id]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incompleta');
        app(BiCatalog::class)->rows($c, $p, 'app');
    }

    public function test_dashboard_documents_preserve_native_header_ids_and_body_names(): void
    {
        $summary = app(BiCatalog::class)->summary('app', ['id' => 100], ['nome' => 'Aplicação', 'dashboards' => [['header' => ['id' => 200], 'body' => ['nome' => 'Mensal', 'widgets' => [['id' => 300]]]]]]);
        $this->assertSame(200, $summary['dashboards'][0]['id']);
        $this->assertSame('Mensal', $summary['dashboards'][0]['name']);
        $this->assertSame(1, $summary['dashboards'][0]['widget_count']);
    }

    public function test_reconnecting_uses_a_new_catalog_cache_and_workspace(): void
    {
        $u = User::factory()->create();
        $c = $this->connect($u);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(fn ($connection, $args) => ['data' => ['matched' => ($args[1] ?? '') === 'app' ? 1 : 0, 'results' => ($args[1] ?? '') === 'app' ? [['id' => 1, 'nome' => $connection->metadata['session_generation'] ?? 'first']] : []]]);
        $this->app->instance(LumoClient::class, $mock);
        $this->actingAs($u)->getJson('/api/catalog/321')->assertJsonPath('resources.app.0.name', 'first');
        $c->update(['metadata' => [...$c->metadata, 'session_generation' => 'new-login']]);
        $this->getJson('/api/catalog/321')->assertJsonPath('resources.app.0.name', 'new-login');
        $this->assertStringEndsWith('new-login', app(BiCatalog::class)->context($c, ['id' => '321', 'name' => 'BI'])->id);
    }
}
