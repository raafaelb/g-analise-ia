<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentInferenceGateway;
use App\Services\Agent\AgentInferenceGrants;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentInferenceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/internal/inference/v1/responses';

    private const SHARED_SECRET = 'SYNTHETIC-GATEWAY-SECRET-FOR-TESTS-ONLY-0000000000000000';

    private function gateway(): array
    {
        config(['horus.codex.gateway_enabled' => true, 'horus.codex.gateway_secret' => self::SHARED_SECRET]);

        return ['X-G-Analise-Inference' => self::SHARED_SECRET, 'Authorization' => 'Bearer gaigr_'.str_repeat('a', 64)];
    }

    public function test_the_endpoint_is_unavailable_in_the_public_application_even_with_both_credentials(): void
    {
        $headers = $this->gateway();
        config(['horus.codex.gateway_enabled' => false]);
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, [], $headers)->assertNotFound();
    }

    public function test_a_private_gateway_does_not_accept_a_missing_internal_secret(): void
    {
        $headers = $this->gateway();
        unset($headers['X-G-Analise-Inference']);
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, [], $headers)->assertNotFound();
    }

    public function test_a_private_gateway_does_not_accept_an_incorrect_internal_secret(): void
    {
        $headers = $this->gateway();
        $headers['X-G-Analise-Inference'] = 'SYNTHETIC-INCORRECT-GATEWAY-SECRET';
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, [], $headers)->assertNotFound();
    }

    public function test_a_gateway_without_its_server_secret_cannot_be_enabled(): void
    {
        $headers = $this->gateway();
        config(['horus.codex.gateway_secret' => null]);
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, [], $headers)->assertServiceUnavailable();
    }

    public function test_the_task_authorization_is_required_in_addition_to_the_internal_secret(): void
    {
        $headers = $this->gateway();
        unset($headers['Authorization']);
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, [], $headers)->assertUnauthorized();
    }

    public function test_non_json_input_is_rejected_before_inference(): void
    {
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->withHeaders([...$this->gateway(), 'Content-Type' => 'text/plain'])->post(self::ENDPOINT)->assertUnsupportedMediaType();
    }

    public function test_malformed_json_is_rejected_before_inference(): void
    {
        $headers = $this->gateway();
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_G_ANALISE_INFERENCE' => $headers['X-G-Analise-Inference'], 'HTTP_AUTHORIZATION' => $headers['Authorization'],
        ], '{incomplete')->assertBadRequest();
    }

    public function test_the_private_body_limit_is_enforced_before_inference(): void
    {
        $headers = $this->gateway();
        config(['horus.codex.gateway_max_bytes' => 1024]);
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->postJson(self::ENDPOINT, ['input' => str_repeat('a', 2048)], $headers)->assertStatus(413);
    }

    public function test_only_posting_responses_is_available_and_no_http_grant_issuer_exists(): void
    {
        $headers = $this->gateway();
        $this->mock(AgentInferenceGateway::class)->shouldNotReceive('respond');

        $this->getJson(self::ENDPOINT, $headers)->assertMethodNotAllowed();
        $this->postJson('/internal/inference/v1/grants', [], $headers)->assertNotFound();
    }

    public function test_a_private_authenticated_request_preserves_its_payload_and_stream_without_browser_session(): void
    {
        Http::preventStrayRequests();
        $headers = $this->gateway();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Endpoint sintético', 'tenant_id' => '100']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'model' => 'gpt-synthetic']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running', 'started_at' => now()]);
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'subject' => (string) Str::uuid(), 'status' => 'connected', 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid()]]);
        $grant = app(AgentInferenceGrants::class)->issue($run, 'gpt-synthetic');
        $headers['Authorization'] = 'Bearer '.$grant->authorization();
        $payload = ['model' => 'gpt-synthetic', 'stream' => true, 'store' => false, 'instructions' => "  Preserve espaços.\n", 'input' => [['role' => 'user', 'content' => ''], ['role' => 'developer', 'content' => "  Contexto sintético.\n"]]];
        $events = "event: response.completed\ndata: {\"type\":\"response.completed\"}\n\n";
        $this->mock(OpenAiIdentity::class)->shouldReceive('accessToken')->once()->andReturn('SYNTHETIC-BACKEND-OAUTH');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($events, 200, ['Content-Type' => 'text/event-stream'])]);

        $response = $this->postJson(self::ENDPOINT, $payload, $headers);

        $response->assertOk()->assertStreamedContent($events)->assertHeaderMissing('Set-Cookie')->assertHeaderContains('Cache-Control', 'no-store');
        Http::assertSent(fn (Request $request): bool => $request->data() === $payload && $request->hasHeader('Authorization', 'Bearer SYNTHETIC-BACKEND-OAUTH') && ! $request->hasHeader('X-G-Analise-Inference'));
        Http::assertSentCount(1);
    }
}
