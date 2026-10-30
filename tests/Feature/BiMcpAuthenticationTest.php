<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\User;
use App\Services\BiMcpIdentity;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BiMcpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus.mcp.enabled' => true, 'horus.mcp.resource' => 'https://bi.example.test/mcp', 'horus.mcp.redirect_uri' => 'https://portal.example.test/auth/bi-mcp/callback']);
    }

    private function actor(string $role = 'editor'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $lumo = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'lumo-secret']], 'metadata' => ['session_generation' => 'lumo-'.$user->id, 'tenants' => [['id' => '321', 'name' => 'Teste'], ['id' => '654', 'name' => 'Outro']]]]);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(fn ($c, $args, $project) => ['data' => ['payload' => ['header' => ['id' => (int) substr($args[1], 4), 'kind' => 'app', 'tenantId' => (int) $project->tenant_id], 'body' => ['nome' => 'Teste', 'dashboards' => []]]]]);
        $this->app->instance(LumoClient::class, $mock);

        return [$user, $lumo];
    }

    private function provider(?\Closure $onApp = null, ?array $tokenOverrides = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://bi.example.test/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://bi.example.test/mcp', 'authorization_servers' => ['https://bi.example.test'], 'scopes_supported' => ['mcp:read']]),
            'https://bi.example.test/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://bi.example.test', 'authorization_endpoint' => 'https://bi.example.test/oauth/authorize', 'token_endpoint' => 'https://bi.example.test/oauth/token', 'registration_endpoint' => 'https://bi.example.test/oauth/register', 'revocation_endpoint' => 'https://bi.example.test/oauth/revoke', 'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none'], 'scopes_supported' => ['mcp:read', 'offline_access']]),
            'https://bi.example.test/oauth/register' => Http::response(['client_id' => 'portal-client', 'token_endpoint_auth_method' => 'none'], 201),
            'https://bi.example.test/oauth/token' => fn () => Http::response([...['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'token_type' => 'Bearer', 'scope' => 'mcp:read offline_access', 'expires_in' => 3600], ...($tokenOverrides ?? [])]),
            'https://bi.example.test/oauth/revoke' => fn () => Http::response([], 200),
            'https://bi.example.test/mcp' => function ($request) use ($onApp) {
                if ($request->method() === 'DELETE') {
                    return Http::response('', 204);
                }
                $data = $request->data();
                $result = match ($data['method']) {
                    'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []]],
                    'tools/list' => ['tools' => array_map(fn ($name) => ['name' => $name], ['get_app', 'query', 'search_catalog', 'search_values', 'unsafe_write'])],
                    'tools/call' => $onApp ? $onApp($request) : ['structuredContent' => ['app' => ['id' => $data['params']['arguments']['appId']]]],
                    default => null,
                };

                return $result === null ? Http::response('', 202) : Http::response(['jsonrpc' => '2.0', 'id' => $data['id'], 'result' => $result], 200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'test-session']);
            },
        ]);
    }

    private function start(User $user, string $tenant = '321'): string
    {
        $response = $this->actingAs($user)->postJson('/api/connections/bi-mcp/'.$tenant.'/authorize', ['app_id' => 10])->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_oauth_confirms_the_environment_and_keeps_tokens_out_of_bootstrap_and_audit(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $state = $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=code-test&iss=https%3A%2F%2Fbi.example.test')->assertRedirect('/app/connections')->assertSessionHas('success');

        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus_mcp:321')->sole();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('321', $connection->metadata['tenant_id']);
        $this->assertSame(10, $connection->metadata['verified_app_id']);
        $this->assertSame(['search_catalog', 'get_app', 'search_values', 'query'], $connection->metadata['available_tools']);
        $this->assertStringNotContainsString('access-secret', $connection->getRawOriginal('credentials'));
        $this->getJson('/api/bootstrap')->assertOk()->assertDontSee('access-secret')->assertDontSee('refresh-secret')->assertDontSee('lumo-secret');
        $this->assertStringNotContainsString('secret', AuditEvent::all()->toJson());
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token' && $r['code'] === 'code-test' && $r['resource'] === 'https://bi.example.test/mcp' && strlen($r['code_verifier']) === 96 && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/mcp' && $r->method() === 'DELETE' && $r->hasHeader('Mcp-Session-Id', 'test-session'));
    }

    public function test_pkce_is_bound_to_the_browser_and_code_is_exchanged_only_once(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $response = $this->actingAs($user)->postJson('/api/connections/bi-mcp/321/authorize', ['app_id' => 10])->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('mcp:read offline_access', $query['scope']);
        $this->get('/auth/bi-mcp/callback?state='.$query['state'].'&code=once')->assertSessionHas('success');
        $this->get('/auth/bi-mcp/callback?state='.$query['state'].'&code=once')->assertSessionHas('error');

        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token' && rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token'));
    }

    public function test_wrong_browser_state_never_exchanges_the_authorization_code(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.str_repeat('x', 64).'&code=foreign')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_expired_state_requires_a_new_authorization(): void
    {
        $this->freezeTime();
        [$user] = $this->actor();
        $this->provider();
        $state = $this->start($user);
        $this->travel(11)->minutes();
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=expired')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_other_user_cannot_finish_an_authorization_started_by_the_first_user(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $state = $this->start($user);
        $other = User::factory()->create();
        $this->actingAs($other)->get('/auth/bi-mcp/callback?state='.$state.'&code=other')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_reconnected_lumo_invalidates_an_in_flight_oauth_attempt(): void
    {
        [$user, $lumo] = $this->actor();
        $this->provider();
        $state = $this->start($user);
        $lumo->update(['metadata' => [...$lumo->metadata, 'session_generation' => 'replacement']]);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=old')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_wrong_mcp_environment_is_rejected_and_the_new_grant_is_revoked(): void
    {
        [$user] = $this->actor();
        $this->provider(fn () => ['structuredContent' => ['app' => ['id' => 999]]]);
        $state = $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=wrong-tenant')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke' && $r['token'] === 'refresh-secret');
    }

    public function test_access_change_during_provider_verification_discards_and_revokes_the_grant(): void
    {
        [$user, $lumo] = $this->actor();
        $this->provider(function () use ($lumo) {
            $lumo->update(['status' => 'disconnected']);

            return ['structuredContent' => ['app' => ['id' => 10]]];
        });
        $state = $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=late')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke');
    }

    public function test_guest_viewer_and_foreign_tenant_cannot_start_oauth(): void
    {
        [$viewer] = $this->actor('viewer');
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        $this->postJson('/api/connections/bi-mcp/321/authorize', ['app_id' => 10])->assertUnauthorized();
        $this->actingAs($viewer)->postJson('/api/connections/bi-mcp/321/authorize', ['app_id' => 10])->assertForbidden();
        $editor = User::factory()->create();
        $this->actingAs($editor)->postJson('/api/connections/bi-mcp/999/authorize', ['app_id' => 10])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_a_newer_attempt_prevents_an_old_callback_from_replacing_it(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $identity = app(BiMcpIdentity::class);
        $first = $identity->begin($user, '321', 10);
        $old = $identity->consume($first['state']);
        $identity->begin($user, '321', 10);

        try {
            $identity->complete($user, $old, 'old-code', null);
            $this->fail('Uma autorização antiga não pode substituir a nova.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mudou', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_each_environment_has_its_own_grant_and_disconnecting_one_preserves_the_other(): void
    {
        [$user] = $this->actor();
        $this->provider();
        foreach (['321', '654'] as $tenant) {
            $state = $this->start($user, $tenant);
            $response = $this->get('/auth/bi-mcp/callback?state='.$state.'&code=code-'.$tenant);
            $this->assertNull(session('error'));
            $response->assertSessionHas('success');
        }
        $this->deleteJson('/api/connections/bi-mcp/321')->assertOk()->assertJsonPath('remote_revocation_acknowledged', true);
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'provider' => 'horus_mcp:321', 'status' => 'disconnected', 'credentials' => null]);
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'provider' => 'horus_mcp:654', 'status' => 'connected']);
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke');
    }

    public function test_an_unexpected_write_scope_cannot_activate_the_read_only_connection(): void
    {
        [$user] = $this->actor();
        $this->provider(tokenOverrides: ['scope' => 'mcp:read mcp:write']);
        $state = $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=wrong-scope')->assertSessionHas('error');
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://bi.example.test/mcp');
    }

    public function test_discovery_cannot_redirect_registration_or_authorization_to_another_origin(): void
    {
        [$user] = $this->actor();
        Http::preventStrayRequests();
        Http::fake([
            'https://bi.example.test/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://bi.example.test/mcp', 'authorization_servers' => ['https://bi.example.test'], 'scopes_supported' => ['mcp:read']]),
            'https://bi.example.test/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://bi.example.test', 'authorization_endpoint' => 'https://attacker.example.test/authorize', 'token_endpoint' => 'https://bi.example.test/oauth/token', 'registration_endpoint' => 'https://attacker.example.test/register', 'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none']]),
        ]);
        $this->actingAs($user)->postJson('/api/connections/bi-mcp/321/authorize', ['app_id' => 10])->assertUnprocessable();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'attacker'));
        $this->assertDatabaseMissing('connections', ['provider' => 'horus_mcp:321']);
    }

    public function test_disconnect_requests_revocation_of_access_and_refresh_tokens_without_exposing_either(): void
    {
        [$user] = $this->actor();
        $this->provider();
        $state = $this->start($user);
        $this->get('/auth/bi-mcp/callback?state='.$state.'&code=disconnect')->assertSessionHas('success');
        $this->deleteJson('/api/connections/bi-mcp/321')->assertOk()->assertJsonPath('remote_revocation_acknowledged', true)->assertDontSee('access-secret')->assertDontSee('refresh-secret');
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke' && $r['token_type_hint'] === 'refresh_token' && $r['token'] === 'refresh-secret');
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke' && $r['token_type_hint'] === 'access_token' && $r['token'] === 'access-secret');
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'provider' => 'horus_mcp:321', 'credentials' => null, 'status' => 'disconnected']);
        $this->deleteJson('/api/connections/bi-mcp/321')->assertOk()->assertJsonPath('remote_revocation_acknowledged', null);
        $this->deleteJson('/api/connections/bi-mcp/654')->assertOk()->assertJsonPath('remote_revocation_acknowledged', null);
        $this->assertCount(2, Http::recorded(fn ($r) => $r->url() === 'https://bi.example.test/oauth/revoke'));
    }
}
