<?php

namespace Tests\Feature;

use App\Services\OpenAiIdentity;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $discovery = ['issuer' => 'https://auth.openai.com', 'authorization_endpoint' => 'https://auth.openai.com/api/accounts/authorize', 'token_endpoint' => 'https://auth.openai.com/api/accounts/oauth/token', 'jwks_uri' => 'https://auth.openai.com/.well-known/jwks.json'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['horus.openai.enabled' => true, 'horus.openai.client_id' => 'oaiapp_test', 'horus.openai.mode' => 'hosted', 'horus.openai.plan_enabled' => true, 'horus.openai.inference_scope' => 'chatgpt.tokens.use.direct']);
        Http::fake(['https://auth.openai.com/.well-known/openid-configuration' => Http::response($this->discovery)]);
    }

    public function test_sign_in_uses_fresh_pkce_state_and_one_time_transactions(): void
    {
        $service = app(OpenAiIdentity::class);
        $a = $service->begin(null);
        $b = $service->begin(null);
        $this->assertNotSame($a['state'], $b['state']);
        parse_str(parse_url($a['url'], PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('oaiapp_test', $query['client_id']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $transaction = $service->consume($a['state']);
        $this->assertNotEmpty($transaction['verifier']);
        $this->assertNull($service->consume($a['state']));
    }

    public function test_invalid_callback_never_exchanges_a_code(): void
    {
        $this->get('/auth/openai/callback?state=forged&code=forged')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_identity_only_authorization_does_not_enable_inference(): void
    {
        $service = app(OpenAiIdentity::class);
        $this->assertFalse($service->planAuthorized(['access_token' => 'test', 'scope' => 'openid email']));
        $this->assertTrue($service->planAuthorized(['access_token' => 'test', 'scope' => 'openid resource.invoke chatgpt.tokens.use.direct']));
    }

    public function test_discovery_cannot_redirect_secrets_to_another_host(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://auth.openai.com/.well-known/openid-configuration' => Http::response([...$this->discovery, 'token_endpoint' => 'http://attacker.invalid/token'])]);
        $this->expectException(\RuntimeException::class);
        app(OpenAiIdentity::class)->discovery();
    }

    public function test_oss_local_auth_is_rejected_on_a_hosted_installation(): void
    {
        config(['horus.openai.mode' => 'oss_local']);
        $this->expectException(\RuntimeException::class);
        app(OpenAiIdentity::class)->begin(null);
    }

    public function test_id_token_signature_audience_nonce_and_expiry_are_verified(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $enc = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        Http::fake(['https://auth.openai.com/.well-known/openid-configuration' => Http::response($this->discovery), 'https://auth.openai.com/.well-known/jwks.json' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'test', 'alg' => 'RS256', 'use' => 'sig', 'n' => $enc($details['rsa']['n']), 'e' => $enc($details['rsa']['e'])]]])]);
        $claims = ['iss' => 'https://auth.openai.com', 'sub' => 'user-1', 'aud' => 'oaiapp_test', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'expected'];
        $token = JWT::encode($claims, $private, 'RS256', 'test');
        $service = app(OpenAiIdentity::class);
        $this->assertSame('user-1', $service->verifyIdToken($token, 'expected')['sub']);
        foreach ([['nonce' => 'wrong'], ['aud' => 'other-client'], ['iss' => 'https://evil.invalid'], ['exp' => time() - 30]] as $change) {
            $invalid = JWT::encode([...$claims, ...$change], $private, 'RS256', 'test');
            try {
                $service->verifyIdToken($invalid, 'expected');
                $this->fail('Token inválido foi aceito.');
            } catch (\RuntimeException|\UnexpectedValueException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
