<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\ChatGptVerifier;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ChatGptVerifierTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): Connection
    {
        $u = User::factory()->create();

        return Connection::create(['user_id' => $u->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'never-expose-token'], 'metadata' => ['plan_usage' => true]]);
    }

    private function fake(string $events): void
    {
        $identity = Mockery::mock(OpenAiIdentity::class);
        $identity->shouldReceive('accessToken')->andReturn('test-oauth-token');
        $this->app->instance(OpenAiIdentity::class, $identity);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'authorized-model', 'visibility' => 'list', 'display_name' => 'Autorizado']]]), 'https://api.openai.com/v1/responses' => Http::response($events, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    public function test_real_inference_contract_uses_array_input_and_no_unsupported_fields(): void
    {
        $this->fake("data: {\"type\":\"response.output_text.delta\",\"delta\":\"OK\"}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\"}}\n\n");
        $c = $this->connection();
        $result = app(ChatGptVerifier::class)->verify($c);
        $this->assertTrue($result['completed']);
        $this->assertSame('OK', $result['response']);
        $this->assertTrue($c->fresh()->metadata['verification']['completed']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && is_array($request['input']) && $request['store'] === false && $request['stream'] === true && ! isset($request['max_output_tokens']) && ! isset($request['temperature']));
    }

    public function test_partial_response_is_not_reported_as_success(): void
    {
        $this->fake("data: {\"type\":\"response.output_text.delta\",\"delta\":\"OK\"}\n\n");
        $c = $this->connection();
        $this->expectException(\RuntimeException::class);
        try {
            app(ChatGptVerifier::class)->verify($c);
        } finally {
            $this->assertArrayNotHasKey('verification', $c->fresh()->metadata);
        }
    }

    public function test_usage_limit_error_after_text_is_still_a_failure(): void
    {
        $this->fake("data: {\"type\":\"response.output_text.delta\",\"delta\":\"Texto\"}\n\ndata: {\"type\":\"response.failed\",\"response\":{\"error\":{\"code\":\"subscription_sharing_usage_limit_exceeded\"}}}\n\n");
        $c = $this->connection();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('subscription_sharing_usage_limit_exceeded');
        app(ChatGptVerifier::class)->verify($c);
    }

    public function test_an_unlisted_model_is_rejected_before_inference(): void
    {
        $this->fake('');
        $this->expectException(\RuntimeException::class);
        try {
            app(ChatGptVerifier::class)->verify($this->connection(), 'unlisted');
        } finally {
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/responses'));
        }
    }

    public function test_oss_authorization_cannot_be_used_on_a_production_server(): void
    {
        $this->app->instance('env', 'production');
        $c = $this->connection();
        $c->update(['metadata' => ['plan_usage' => true, 'auth_mode' => 'oss_local']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('homologação local');
        app(OpenAiIdentity::class)->accessToken($c);
    }
}
