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
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AgentInferenceGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Gateway sintético', 'tenant_id' => '100']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'model' => 'gpt-synthetic']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running', 'started_at' => now(), 'heartbeat_at' => now()]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'subject' => (string) Str::uuid(), 'status' => 'connected', 'credentials' => ['access_token' => 'SYNTHETIC-SERVER-OAUTH'], 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid(), 'auth_mode' => 'hosted']]);
        $grant = app(AgentInferenceGrants::class)->issue($run, 'gpt-synthetic');

        return [$user, $run, $connection, $grant];
    }

    private function payload(): array
    {
        return ['model' => 'gpt-synthetic', 'store' => false, 'stream' => true, 'instructions' => "  Preserve espaços e quebras.\n", 'input' => [['role' => 'user', 'content' => 'Pergunta inteiramente sintética.']]];
    }

    private function identity(Connection $connection): void
    {
        $this->mock(OpenAiIdentity::class)->shouldReceive('accessToken')->once()->with(Mockery::on(fn (Connection $item): bool => $item->id === $connection->id))->andReturn('SYNTHETIC-SERVER-OAUTH');
    }

    private function events(): string
    {
        return "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\"}}\n\n";
    }

    public function test_only_the_backend_oauth_authorizes_the_fixed_upstream_and_the_stream_is_preserved(): void
    {
        Http::preventStrayRequests();
        [, , $connection, $grant] = $this->context();
        $this->identity($connection);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->events(), 200, ['Content-Type' => 'text/event-stream', 'Set-Cookie' => 'synthetic-private-cookie'])]);
        $payload = $this->payload();

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $payload);

        TestResponse::fromBaseResponse($response)->assertOk()->assertHeader('Content-Type', 'text/event-stream')->assertHeaderContains('Cache-Control', 'no-store')->assertHeader('X-Accel-Buffering', 'no')->assertHeaderMissing('Set-Cookie')->assertStreamedContent($this->events());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses' && $request->hasHeader('Authorization', 'Bearer SYNTHETIC-SERVER-OAUTH') && ! $request->hasHeader('Authorization', 'Bearer '.$grant->authorization()) && $request->data() === $payload);
        Http::assertSentCount(1);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing model' => [['model' => null]], 'different model' => [['model' => 'another-model']],
            'not streamed' => [['stream' => false]], 'stored response' => [['store' => true]],
            'missing store choice' => [['store' => null]], 'string input' => [['input' => 'invalid']],
            'empty input' => [['input' => []]], 'caller user override' => [['user_id' => 999]],
            'caller tenant override' => [['tenant_id' => '999']], 'caller endpoint override' => [['base_url' => 'https://invalid.example']],
            'unsupported previous response' => [['previous_response_id' => 'synthetic-previous']],
            'associative input' => [['input' => ['role' => 'user']]],
            'unsupported background mode' => [['background' => true]], 'unsupported metadata' => [['metadata' => ['private' => 'synthetic']]],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_scope_overriding_payloads_are_refused_before_credentials_or_network(array $overrides): void
    {
        Http::preventStrayRequests();
        [, , , $grant] = $this->context();
        $this->mock(OpenAiIdentity::class)->shouldNotReceive('accessToken');

        try {
            app(AgentInferenceGateway::class)->respond($grant->authorization(), array_replace($this->payload(), $overrides));
            $this->fail('Invalid payloads must not reach OpenAI.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        Http::assertNothingSent();
    }

    public function test_a_revoked_grant_cannot_read_the_real_credential_or_reach_the_upstream(): void
    {
        Http::preventStrayRequests();
        [, , , $grant] = $this->context();
        app(AgentInferenceGrants::class)->revoke($grant);
        $this->mock(OpenAiIdentity::class)->shouldNotReceive('accessToken');

        try {
            app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());
            $this->fail('Revoked grants must not reach the provider.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        Http::assertNothingSent();
    }

    public static function upstreamErrors(): array
    {
        return ['authentication' => [401, 401], 'permissions' => [403, 403], 'plan limit' => [429, 429], 'provider failure' => [500, 502], 'provider timeout' => [504, 502], 'redirect' => [302, 502], 'temporary redirect' => [307, 502]];
    }

    #[DataProvider('upstreamErrors')]
    public function test_provider_failures_do_not_expose_private_bodies_cookies_or_redirects(int $providerStatus, int $expected): void
    {
        Http::preventStrayRequests();
        [, , $connection, $grant] = $this->context();
        $this->identity($connection);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response('SYNTHETIC-PRIVATE-PROVIDER-BODY', $providerStatus, ['Location' => 'https://invalid.example/redirect', 'Set-Cookie' => 'synthetic-secret', 'Retry-After' => '12'])]);

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());

        TestResponse::fromBaseResponse($response)->assertStatus($expected)->assertHeaderMissing('Location')->assertHeaderMissing('Set-Cookie')->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PROVIDER-BODY', $response->getContent());
        $this->assertStringNotContainsString($grant->authorization(), $response->getContent());
        if ($providerStatus === 429) {
            $this->assertSame('12', $response->headers->get('Retry-After'));
        }
        Http::assertSentCount(1);
    }

    public function test_an_oauth_failure_is_sanitized_and_does_not_start_an_upstream_request(): void
    {
        Http::preventStrayRequests();
        [, , , $grant] = $this->context();
        $this->mock(OpenAiIdentity::class)->shouldReceive('accessToken')->once()->andThrow(new RuntimeException('SYNTHETIC-PRIVATE-AUTH-DETAIL'));

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-AUTH-DETAIL', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_a_network_failure_is_not_retried_and_does_not_expose_transport_details(): void
    {
        Http::preventStrayRequests();
        [, , $connection, $grant] = $this->context();
        $this->identity($connection);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('SYNTHETIC-PRIVATE-TRANSPORT-DETAIL');
        });

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-TRANSPORT-DETAIL', $response->getContent());
    }

    public function test_successful_non_stream_content_is_rejected_without_forwarding_its_body(): void
    {
        Http::preventStrayRequests();
        [, , $connection, $grant] = $this->context();
        $this->identity($connection);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response('SYNTHETIC-UNEXPECTED-HTML', 200, ['Content-Type' => 'text/html'])]);

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringNotContainsString('SYNTHETIC-UNEXPECTED-HTML', $response->getContent());
    }

    public function test_access_revoked_after_the_provider_responds_prevents_opening_the_stream(): void
    {
        Http::preventStrayRequests();
        [$user, , $connection, $grant] = $this->context();
        $this->identity($connection);
        Http::fake(['https://api.openai.com/v1/responses' => function () use ($user) {
            $user->forceFill(['active' => false])->save();

            return Http::response($this->events(), 200, ['Content-Type' => 'text/event-stream']);
        }]);

        try {
            app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());
            $this->fail('Changed access must not receive the provider stream.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Http::assertSentCount(1);
    }

    public function test_revocation_during_a_provider_read_stops_the_stream_without_replaying_inference(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        [$user, , $connection, $grant] = $this->context();
        $this->identity($connection);
        $firstChunk = str_pad(": synthetic stream heartbeat\n", 32766, ' ')."\n\n";
        $reads = 0;
        $body = new PumpStream(function () use (&$reads, $user, $firstChunk): string|false {
            $reads++;
            if ($reads === 1) {
                return $firstChunk;
            }
            if ($reads === 2) {
                $user->forceFill(['active' => false])->save();
                $this->travel(1)->seconds();

                return $this->events();
            }

            return false;
        });
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body, 200, ['Content-Type' => 'text/event-stream; charset=utf-8'])]);

        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());

        TestResponse::fromBaseResponse($response)->assertStreamedContent($firstChunk);
        Http::assertSentCount(1);
    }

    public function test_revocation_before_the_first_stream_chunk_does_not_forward_any_content(): void
    {
        Http::preventStrayRequests();
        [, , $connection, $grant] = $this->context();
        $this->identity($connection);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->events(), 200, ['Content-Type' => 'text/event-stream'])]);
        $response = app(AgentInferenceGateway::class)->respond($grant->authorization(), $this->payload());
        app(AgentInferenceGrants::class)->revoke($grant);

        TestResponse::fromBaseResponse($response)->assertStreamedContent('');
    }
}
