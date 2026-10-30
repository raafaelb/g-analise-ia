<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalAuthenticationOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        config(['horus.openai.mode' => 'oss_local', 'horus.openai.local_redirect_uri' => 'http://127.0.0.1:8088/auth/openai/callback', 'horus.preview' => false]);
        Http::preventStrayRequests();
    }

    public function test_loopback_login_alias_uses_the_configured_callback_origin(): void
    {
        $this->get('http://localhost:8088/login')->assertRedirect('http://127.0.0.1:8088/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_workspace_alias_preserves_the_requested_page_and_query(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('http://localhost:8088/app/catalog?tenant=923')->assertRedirect('http://127.0.0.1:8088/app/catalog?tenant=923');

        Http::assertNothingSent();
    }

    public function test_authentication_from_an_alias_opens_connections_without_starting_oauth_in_another_session(): void
    {
        $this->get('http://localhost:8088/auth/openai')->assertRedirect('http://127.0.0.1:8088/app/connections');

        Http::assertNothingSent();
        $this->assertNull(session('openai_oauth_state'));
    }

    public function test_the_matching_origin_renders_the_login_page_without_a_redirect_loop(): void
    {
        $this->get('http://127.0.0.1:8088/login')->assertOk();

        Http::assertNothingSent();
    }

    public static function otherInstallations(): array
    {
        return [
            'hosted authentication' => ['local', 'hosted', 'http://127.0.0.1:8088/auth/openai/callback', 'http://localhost:8088/login'],
            'production' => ['production', 'oss_local', 'http://127.0.0.1:8088/auth/openai/callback', 'http://localhost:8088/login'],
            'external callback' => ['local', 'oss_local', 'https://external.example.invalid/auth/openai/callback', 'http://localhost:8088/login'],
            'external request' => ['local', 'oss_local', 'http://127.0.0.1:8088/auth/openai/callback', 'https://portal.example.invalid/login'],
        ];
    }

    #[DataProvider('otherInstallations')]
    public function test_other_installations_and_external_origins_are_not_redirected(string $environment, string $mode, string $callback, string $url): void
    {
        $this->app->instance('env', $environment);
        config(['horus.openai.mode' => $mode, 'horus.openai.local_redirect_uri' => $callback]);

        $this->get($url)->assertOk();

        Http::assertNothingSent();
    }

    public function test_api_requests_are_never_redirected_to_another_cookie_origin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('http://localhost:8088/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id);

        Http::assertNothingSent();
    }

    public function test_invalid_oauth_callbacks_still_require_the_original_session_state(): void
    {
        $this->get('http://localhost:8088/auth/openai/callback?state=forged&code=forged')->assertRedirect('http://localhost:8088/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }
}
