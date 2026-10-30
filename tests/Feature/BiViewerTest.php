<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\User;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiViewerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/viewer-'.Str::uuid());
        config(['horus.workspace_root' => $this->root, 'horus.viewer.enabled' => true, 'horus.viewer.allowed_origins' => ['https://bi.example.test']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connect(string $url = 'https://bi.example.test/session/one-use-test-ticket', string $role = 'editor', bool $expectSession = true, array $overrides = []): User
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => ($args[0] ?? '') === 'app' && ($args[1] ?? '') === 'open')->times($expectSession ? 1 : 0)->andReturnUsing(function ($c, $args, $project) use ($url, $connection, $overrides) {
            $this->assertSame($connection->id, $c->id);
            $this->assertSame('321', $project->tenant_id);
            $this->assertSame(['app', 'open', 'app:10', '--session', '--dashboard', '99'], $args);

            return ['data' => [...['session' => true, 'url' => $url, 'expires_in' => 120, 'session_minutes' => 30], ...$overrides]];
        });
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => ! (($args[0] ?? '') === 'app' && ($args[1] ?? '') === 'open'))->andReturnUsing(function ($c, $args) {
            if ($args[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['tenantId' => 321], 'body' => ['nome' => 'Teste', 'dashboards' => [['header' => ['id' => 99], 'body' => ['nome' => 'Mensal', 'widgets' => []]]]]]]];
            }
            if ($args[0] === 'list') {
                return ['data' => $args[1] === 'variables' ? ['count' => 0, 'variables' => []] : ['matched' => $args[1] === 'app' ? 1 : 0, 'results' => $args[1] === 'app' ? [['id' => 10, 'nome' => 'Teste']] : []]];
            }

            return ['data' => []];
        });
        $this->app->instance(LumoClient::class, $mock);

        return $user;
    }

    public function test_authorized_session_is_ephemeral_and_the_ticket_is_not_written_to_audit(): void
    {
        $user = $this->connect();
        $response = $this->actingAs($user)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertOk()->assertJsonPath('origin', 'https://bi.example.test')->assertJsonPath('resource', 'app:10')->assertJsonPath('dashboard_id', 99)->assertJsonPath('url', 'https://bi.example.test/session/one-use-test-ticket');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('https://bi.example.test', $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('one-use-test-ticket', AuditEvent::query()->get()->toJson());
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'bi.viewer.created']);
    }

    public function test_guest_foreign_tenant_and_invalid_dashboard_cannot_create_sessions(): void
    {
        $user = $this->connect(expectSession: false);
        $this->postJson('/api/catalog/321/apps/10/viewer', ['isolated_context' => true])->assertUnauthorized();
        $this->actingAs($user)->postJson('/api/catalog/999/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertForbidden();
        $this->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 100, 'isolated_context' => true])->assertUnprocessable();
        $this->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99])->assertUnprocessable();
    }

    public function test_viewer_profile_and_read_only_tenant_cannot_obtain_native_write_capabilities(): void
    {
        $viewer = $this->connect(role: 'viewer', expectSession: false);
        $this->actingAs($viewer)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertForbidden();
        $editor = $this->connect(expectSession: false);
        config(['horus.read_only_tenants' => ['321']]);
        $this->actingAs($editor)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertForbidden();
    }

    public function test_disabled_feature_does_not_start_the_native_cli(): void
    {
        $user = $this->connect(expectSession: false);
        config(['horus.viewer.enabled' => false]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertStatus(409);
    }

    #[DataProvider('unsafeUrls')]
    public function test_untrusted_session_destinations_are_rejected(string $url): void
    {
        $user = $this->connect($url);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertUnprocessable()->assertJsonMissingPath('url');
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.viewer.created']);
    }

    public static function unsafeUrls(): array
    {
        return [
            'http' => ['http://bi.example.test/session/ticket'],
            'other host' => ['https://other.example.test/session/ticket'],
            'suffix host' => ['https://bi.example.test.attacker.test/session/ticket'],
            'userinfo' => ['https://user:password@bi.example.test/session/ticket'],
            'fragment' => ['https://bi.example.test/session/ticket#credential'],
            'backslash' => ['https://bi.example.test\\@other.example.test/session/ticket'],
        ];
    }

    public function test_same_origin_viewer_and_unconfirmed_expiration_are_rejected(): void
    {
        $user = $this->connect(overrides: ['session_minutes' => 60]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertUnprocessable();
        config(['app.url' => 'https://bi.example.test/ia']);
        $user = $this->connect();
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/viewer', ['dashboard' => 99, 'isolated_context' => true])->assertUnprocessable();
    }
}
