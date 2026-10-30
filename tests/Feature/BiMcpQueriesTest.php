<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexRuntime;
use App\Services\BiMcpIdentity;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiMcpQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus.mcp.enabled' => true, 'horus.mcp.resource' => 'https://bi.example.test/mcp', 'horus.mcp.redirect_uri' => 'https://portal.example.test/auth/bi-mcp/callback']);
    }

    private function actor(string $tenant = '321'): array
    {
        $user = User::factory()->create(['role' => 'editor']);
        $lumo = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'lumo-'.$user->id]], 'metadata' => ['session_generation' => 'lumo-generation', 'tenants' => [['id' => $tenant, 'name' => 'Teste']]]]);
        $mcp = Connection::create(['user_id' => $user->id, 'provider' => 'horus_mcp:'.$tenant, 'status' => 'connected', 'credentials' => ['access_token' => 'access-'.$user->id, 'refresh_token' => 'refresh-'.$user->id], 'scopes' => ['mcp:read', 'offline_access'], 'metadata' => ['resource' => 'https://bi.example.test/mcp', 'tenant_id' => $tenant, 'lumo_generation' => 'lumo-generation', 'session_generation' => 'mcp-'.$user->id, 'access_version' => $user->access_version, 'client_id' => 'portal-client', 'verified_app_id' => 10, 'available_tools' => ['get_app', 'search_catalog', 'search_values', 'query']], 'expires_at' => now()->addHour()]);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => $args[0] === 'info')->andReturnUsing(fn ($c, $args, $project) => ['data' => ['payload' => ['header' => ['tenantId' => (int) $project->tenant_id, 'id' => (int) substr($args[1], 4), 'kind' => 'app'], 'body' => ['nome' => 'Teste', 'dashboards' => []]]]]);
        $this->app->instance(LumoClient::class, $mock);

        return [$user, $lumo, $mcp];
    }

    private function queryResult(array $overrides = []): array
    {
        return [...['appId' => 10, 'columns' => [['key' => 'total', 'label' => 'Valor', 'type' => 'number', 'unit' => 'BRL']], 'rows' => [[125.5]], 'rowCount' => 1, 'totalRows' => 1, 'truncated' => false, 'dataAsOf' => '2026-10-30T05:00:00-03:00', 'filtersApplied' => [], 'permalink' => 'https://bi.example.test/reports?view=123'], ...$overrides];
    }

    private function provider(\Closure $query, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/mcp' => function ($request) use ($query, $status) {
            $data = $request->data();
            if ($request->method() === 'DELETE' || ($data['method'] ?? '') === 'notifications/initialized') {
                return Http::response('', 204);
            }
            $result = ($data['method'] ?? '') === 'initialize'
                ? ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []]]
                : $query($request);

            return Http::response(['jsonrpc' => '2.0', 'id' => $data['id'], 'result' => $result], ($data['method'] ?? '') === 'initialize' ? 200 : $status, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'query-session']);
        }]);
    }

    private function payload(array $arguments = []): array
    {
        return ['tool' => 'query', 'arguments' => [...['fields' => [['ref' => 'Vendas.Valor', 'agg' => 'sum']], 'limit' => 20], ...$arguments]];
    }

    public function test_query_uses_only_the_personal_grant_and_returns_units_totals_and_permalink_without_credentials(): void
    {
        [$user, , $mcp] = $this->actor();
        [, , $foreignMcp] = $this->actor();
        $this->provider(fn () => ['structuredContent' => $this->queryResult()]);
        $filters = [['ref' => 'Vendas.Região', 'op' => 'in', 'value' => ['Sul', 'Norte']]];
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload(['filters' => $filters, 'orderBy' => [['ref' => 'Vendas.Valor', 'dir' => 'desc']]]))
            ->assertOk()->assertJsonPath('read_only', true)->assertJsonPath('data.rows.0.0', 125.5)->assertJsonPath('data.columns.0.unit', 'BRL')->assertJsonPath('data.permalink', 'https://bi.example.test/reports?view=123')->assertDontSee('access-')->assertDontSee('refresh-');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call' && $r->hasHeader('Authorization', 'Bearer access-'.$user->id) && $r['params']['arguments']['appId'] === 10 && $r['params']['arguments']['filters'] === $filters);
        Http::assertNotSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.$foreignMcp->credentials['access_token']));
        $this->assertSame('connected', $mcp->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'bi.mcp.query']);
        $this->assertStringNotContainsString('125.5', AuditEvent::all()->toJson());
    }

    #[DataProvider('invalidArguments')]
    public function test_unsafe_or_invalid_queries_are_rejected_before_contacting_mcp(array $arguments): void
    {
        [$user] = $this->actor();
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload($arguments))->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.mcp.query']);
    }

    public static function invalidArguments(): array
    {
        return [
            'over limit' => [['limit' => 501]],
            'raw sql' => [['sql' => 'DROP TABLE sales']],
            'override app' => [['appId' => 999]],
            'unknown field keys' => [['fields' => [['ref' => 'Vendas.Valor', 'sql' => 'sum(secret)']]]],
            'invalid aggregate' => [['fields' => [['ref' => 'Vendas.Valor', 'agg' => 'raw']]]],
            'both date and aggregate' => [['fields' => [['ref' => 'Vendas.Data', 'agg' => 'sum', 'bucket' => 'month']]]],
            'bad interval' => [['filters' => [['ref' => 'Vendas.Data', 'op' => 'between', 'value' => ['2026-01-01']]]]],
            'nested filter value' => [['filters' => [['ref' => 'Vendas.Região', 'op' => 'in', 'value' => [['sql' => 'secret']]]]]],
            'unknown sort' => [['orderBy' => [['ref' => 'Outra.Coluna', 'dir' => 'asc']]]],
        ];
    }

    public function test_mcp_from_a_different_tenant_is_not_reused(): void
    {
        [$user, $lumo] = $this->actor('654');
        $lumo->update(['metadata' => [...$lumo->metadata, 'tenants' => [['id' => '654', 'name' => 'Outro'], ['id' => '321', 'name' => 'Teste']]]]);
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('mcp');
        Http::assertNothingSent();
    }

    public function test_lumo_reconnect_invalidates_an_existing_mcp_binding(): void
    {
        [$user, $lumo] = $this->actor();
        $lumo->update(['metadata' => [...$lumo->metadata, 'session_generation' => 'new-session']]);
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_disconnection_during_the_query_discards_the_response(): void
    {
        [$user, , $mcp] = $this->actor();
        $this->provider(function () use ($mcp) {
            $mcp->update(['status' => 'disconnected', 'credentials' => null]);

            return ['structuredContent' => $this->queryResult()];
        });
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable()->assertDontSee('125.5');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.mcp.query']);
    }

    public function test_user_access_change_during_the_query_discards_the_response(): void
    {
        [$user] = $this->actor();
        $this->provider(function () use ($user) {
            $user->forceFill(['access_version' => $user->access_version + 1])->save();

            return ['structuredContent' => $this->queryResult()];
        });
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable()->assertDontSee('125.5');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.mcp.query']);
    }

    #[DataProvider('invalidResults')]
    public function test_malformed_or_foreign_results_are_not_returned(array $overrides): void
    {
        [$user] = $this->actor();
        $this->provider(fn () => ['structuredContent' => $this->queryResult($overrides)]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable()->assertJsonMissingPath('data');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.mcp.query']);
    }

    public static function invalidResults(): array
    {
        return [
            'foreign app' => [['appId' => 999]],
            'row count mismatch' => [['rowCount' => 2]],
            'total less than returned' => [['totalRows' => 0]],
            'not a row list' => [['rows' => ['secret' => 1]]],
            'invalid truncated' => [['truncated' => 'false']],
            'more than requested' => [['rows' => array_fill(0, 21, [1]), 'rowCount' => 21, 'totalRows' => 21]],
        ];
    }

    public function test_untrusted_permalink_is_removed_without_losing_valid_results(): void
    {
        [$user] = $this->actor();
        $this->provider(fn () => ['structuredContent' => $this->queryResult(['permalink' => 'https://attacker.example.test/?token=secret'])]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertOk()->assertJsonMissingPath('data.permalink')->assertJsonPath('data.rows.0.0', 125.5);
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
    }

    public function test_provider_denial_exposes_only_a_safe_message_and_does_not_start_a_write(): void
    {
        [$user] = $this->actor();
        $this->provider(fn () => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'db-password-secret']]], 403);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnprocessable()->assertDontSee('db-password-secret');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_rotated_refresh_token_is_persisted_and_a_second_request_uses_the_fresh_access_token(): void
    {
        $this->freezeTime();
        [$user, , $mcp] = $this->actor();
        $mcp->update(['expires_at' => now()->subSecond()]);
        Http::preventStrayRequests();
        Http::fake([
            'https://bi.example.test/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://bi.example.test/mcp', 'authorization_servers' => ['https://bi.example.test'], 'scopes_supported' => ['mcp:read']]),
            'https://bi.example.test/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://bi.example.test', 'authorization_endpoint' => 'https://bi.example.test/oauth/authorize', 'token_endpoint' => 'https://bi.example.test/oauth/token', 'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none']]),
            'https://bi.example.test/oauth/token' => Http::response(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ]);
        $identity = app(BiMcpIdentity::class);
        $this->assertSame('rotated-access', $identity->accessToken($mcp, 'mcp-'.$user->id));
        $this->assertSame('rotated-access', $identity->accessToken($mcp, 'mcp-'.$user->id));
        $this->assertSame('rotated-refresh', $mcp->fresh()->credentials['refresh_token']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token'));
    }

    public function test_guest_viewer_and_foreign_tenant_are_refused_without_contacting_mcp(): void
    {
        [$user] = $this->actor();
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        $this->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertUnauthorized();
        $this->actingAs($user)->postJson('/api/catalog/999/apps/10/mcp', $this->payload())->assertForbidden();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->postJson('/api/catalog/321/apps/10/mcp', $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_broker_binds_semantic_queries_to_the_current_application_without_requesting_write_approval(): void
    {
        [$user] = $this->actor();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Consulta', 'tenant_id' => '321', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->provider(fn () => ['structuredContent' => $this->queryResult()]);
        $broker = app(ActionBroker::class);
        $result = $broker->execute($run, 'bi_data', ['operation' => 'query', 'arguments' => $this->payload()['arguments']]);
        $this->assertSame(10, $result['app_id']);
        $this->assertSame([[125.5]], $result['data']['rows']);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'resource_id' => $project->id, 'action' => 'bi.mcp.query']);
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call' && $r['params']['arguments']['appId'] === 10);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_broker_does_not_deliver_old_results_after_the_project_application_changes(): void
    {
        [$user] = $this->actor();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Consulta', 'tenant_id' => '321', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->provider(function () use ($project) {
            $project->update(['source_ref' => 'app:11']);

            return ['structuredContent' => $this->queryResult()];
        });
        try {
            app(ActionBroker::class)->execute($run, 'bi_data', ['operation' => 'query', 'arguments' => $this->payload()['arguments']]);
            $this->fail('O resultado da aplicação anterior precisa ser descartado.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('projeto mudou', $e->getMessage());
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.mcp.query']);
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call');
    }

    public function test_delayed_refresh_does_not_overwrite_a_replaced_grant(): void
    {
        [$user, , $mcp] = $this->actor();
        $mcp->update(['expires_at' => now()->subSecond()]);
        Http::preventStrayRequests();
        Http::fake([
            'https://bi.example.test/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://bi.example.test/mcp', 'authorization_servers' => ['https://bi.example.test'], 'scopes_supported' => ['mcp:read']]),
            'https://bi.example.test/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://bi.example.test', 'authorization_endpoint' => 'https://bi.example.test/oauth/authorize', 'token_endpoint' => 'https://bi.example.test/oauth/token', 'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none']]),
            'https://bi.example.test/oauth/token' => function () use ($mcp) {
                $mcp->update(['metadata' => [...$mcp->metadata, 'session_generation' => 'replacement'], 'credentials' => ['access_token' => 'replacement-token']]);

                return Http::response(['access_token' => 'late-old-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
            },
        ]);
        try {
            app(BiMcpIdentity::class)->accessToken($mcp, 'mcp-'.$user->id);
            $this->fail('A renovação antiga precisa ser descartada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mudou durante a renovação', $e->getMessage());
        }
        $this->assertSame('replacement-token', $mcp->fresh()->credentials['access_token']);
        Http::assertSent(fn ($r) => $r->url() === 'https://bi.example.test/oauth/token');
    }

    public function test_ai_corrects_validation_arguments_in_the_same_turn_instead_of_failing_the_conversation(): void
    {
        [$user] = $this->actor();
        $root = sys_get_temp_dir().'/ganalise-validation-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($root, 0700, true);
        $binary = $root.'/codex-test';
        File::copy(base_path('tests/Fixtures/codex-validation-server.php'), $binary);
        chmod($binary, 0700);
        config(['horus.codex.binary' => $binary, 'horus.codex.timeout' => 15, 'horus.workspace_root' => $root.'/workspaces']);
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-openai-no-account']]);
        $this->mock(OpenAiIdentity::class)->shouldReceive('accessToken')->once()->andReturn('synthetic-openai-no-account');
        $project = Project::create(['user_id' => $user->id, 'name' => 'Validação de consulta', 'tenant_id' => '321', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'model' => 'synthetic-model']);
        $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Consulte meus dados.', 'status' => 'completed']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'input_message_id' => $input->id, 'status' => 'queued']);
        $this->provider(fn () => ['structuredContent' => ['app' => ['id' => 10], 'tables' => []]]);
        try {
            (new RunAgentTurn($run->id))->handle(app(CodexRuntime::class));

            $this->assertSame('completed', $run->fresh()->status);
            $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant', 'status' => 'completed', 'content' => 'Consulta corrigida e concluída.']);
            $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'bi.mcp.get_app']);
            $this->assertDatabaseCount('approvals', 0);
            Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call' && $r['params']['name'] === 'get_app');
            Http::assertNotSent(fn ($r) => ($r['method'] ?? '') === 'tools/call' && $r['params']['name'] === 'query');
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory(storage_path('app/private/codex/'.$user->id.'/'.$project->id));
        }
    }

    public function test_empty_text_filters_and_optional_null_arguments_keep_their_mcp_meaning(): void
    {
        [$user] = $this->actor();
        $this->provider(fn () => ['structuredContent' => $this->queryResult()]);
        $this->actingAs($user)->postJson('/api/catalog/321/apps/10/mcp', $this->payload([
            'fields' => [['ref' => 'Vendas.Valor', 'agg' => 'sum', 'bucket' => null]],
            'filters' => [['ref' => 'Vendas.Região', 'op' => '=', 'value' => '']],
            'orderBy' => null, 'limit' => null,
        ]))->assertOk();
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/call' && $r['params']['arguments'] === [
            'appId' => 10, 'fields' => [['ref' => 'Vendas.Valor', 'agg' => 'sum']],
            'filters' => [['ref' => 'Vendas.Região', 'op' => '=', 'value' => '']], 'limit' => 20,
        ]);
    }
}
