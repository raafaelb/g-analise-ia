<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatGptConnectionReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('authorizationEnvironments')]
    public function test_bootstrap_reports_environment_reauthorization_without_using_or_exposing_tokens(string $environment, string $mode, string $status, bool $expected): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->app->instance('env', $environment);
        config(['horus.openai.enabled' => false, 'horus.openai.mode' => $environment === 'local' ? 'oss_local' : 'hosted']);
        $user = User::factory()->create();
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => $status, 'credentials' => ['access_token' => 'synthetic-private-token'], 'metadata' => ['auth_mode' => $mode, 'plan_usage' => true]]);

        $response = $this->actingAs($user)->getJson('/api/bootstrap');

        $response->assertOk()->assertJsonPath('connections.0.requires_reauthorization', $expected)->assertJsonMissingPath('connections.0.credentials')->assertJsonPath('integration.openai_configured', false);
        $this->assertStringNotContainsString('synthetic-private-token', $response->getContent());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function authorizationEnvironments(): array
    {
        return [
            'local grant in local app' => ['local', 'oss_local', 'connected', false],
            'local grant restored in production' => ['production', 'oss_local', 'connected', true],
            'hosted grant with sign-in disabled' => ['production', 'hosted', 'connected', false],
            'expired hosted account' => ['production', 'hosted', 'reauthentication_required', true],
        ];
    }
}
