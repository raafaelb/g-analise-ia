<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\OpenAiIdentity;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenSourceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTokens(string $audience): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $enc = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $token = JWT::encode(['iss' => 'https://auth.openai.com', 'sub' => 'test-person', 'aud' => $audience, 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'test-nonce'], $private, 'RS256', 'oss-test');
        Http::preventStrayRequests();
        Http::fake([
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['issuer' => 'https://auth.openai.com', 'authorization_endpoint' => 'https://auth.openai.com/api/accounts/authorize', 'token_endpoint' => 'https://auth.openai.com/api/accounts/oauth/token', 'jwks_uri' => 'https://auth.openai.com/.well-known/jwks.json']),
            'https://auth.openai.com/.well-known/jwks.json' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'oss-test', 'alg' => 'RS256', 'use' => 'sig', 'n' => $enc($details['rsa']['n']), 'e' => $enc($details['rsa']['e'])]]]),
            'https://auth.openai.com/api/accounts/oauth/token' => Http::response(['id_token' => $token, 'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'scope' => 'openid resource.invoke chatgpt.tokens.use.direct', 'expires_in' => 3600]),
        ]);
        config(['horus.openai.mode' => 'oss_local', 'horus.openai.enabled' => true]);
    }

    public function test_first_dynamic_registration_uses_and_persists_issued_client_before_exchange(): void
    {
        $user = User::factory()->create();
        $this->fakeTokens('oaiapp_issued');
        $service = app(OpenAiIdentity::class);
        $result = $service->exchange('test-code', ['mode' => 'oss_local', 'user_id' => $user->id, 'client_id' => 'dynamic_agent_client', 'redirect_uri' => 'http://127.0.0.1:8088/auth/openai/callback', 'verifier' => 'test-verifier', 'nonce' => 'test-nonce'], 'oaiapp_issued');
        $this->assertSame('oaiapp_issued', $result['client_id']);
        $this->assertSame('test-person', $result['identity']['sub']);
        $this->assertTrue($service->planAuthorized($result['tokens']));
        $this->assertSame('oaiapp_issued', Connection::where('user_id', $user->id)->first()->metadata['pending_client_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/oauth/token') && $request['client_id'] === 'oaiapp_issued' && $request['resource'] === 'https://api.openai.com/v1' && $request['code_verifier'] === 'test-verifier' && ! isset($request['client_secret']));
    }

    public function test_returning_registration_rejects_changed_client_without_token_exchange(): void
    {
        $this->fakeTokens('oaiapp_expected');
        $this->expectException(\RuntimeException::class);
        try {
            app(OpenAiIdentity::class)->exchange('code', ['mode' => 'oss_local', 'user_id' => null, 'client_id' => 'oaiapp_expected', 'nonce' => 'test-nonce', 'verifier' => 'test'], 'oaiapp_changed');
        } finally {
            Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/oauth/token'));
        }
    }

    public function test_registration_rejects_validly_signed_token_for_another_client(): void
    {
        $user = User::factory()->create();
        $this->fakeTokens('oaiapp_wrong');
        $this->expectException(\RuntimeException::class);
        app(OpenAiIdentity::class)->exchange('code', ['mode' => 'oss_local', 'user_id' => $user->id, 'client_id' => 'dynamic_agent_client', 'nonce' => 'test-nonce', 'verifier' => 'test'], 'oaiapp_expected');
    }

    public function test_oss_scope_is_required_even_for_open_source_mode(): void
    {
        config(['horus.openai.mode' => 'oss_local']);
        $this->assertFalse(app(OpenAiIdentity::class)->planAuthorized(['access_token' => 'test', 'scope' => 'openid profile email']));
    }
}
