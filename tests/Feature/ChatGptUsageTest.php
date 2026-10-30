<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\Agent\CodexAccountReader;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatGptUsageTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $user, string $token): Connection
    {
        return Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => $token], 'metadata' => ['plan_usage' => true]]);
    }

    private function identity(): void
    {
        $identity = Mockery::mock(OpenAiIdentity::class);
        $identity->shouldReceive('accessToken')->andReturnUsing(fn (Connection $connection) => $connection->credentials['access_token']);
        $this->app->instance(OpenAiIdentity::class, $identity);
    }

    public function test_plan_and_remaining_quota_use_only_official_account_results_and_are_cached(): void
    {
        $user = User::factory()->create();
        $this->connect($user, 'first-test-token');
        $this->identity();
        $reader = Mockery::mock(CodexAccountReader::class);
        $reader->shouldReceive('read')->once()->with('first-test-token')->andReturn(['account' => ['planType' => 'plus', 'email' => 'private@example.test'], 'limits' => ['rateLimitsByLimitId' => ['codex' => ['limitName' => 'Codex', 'primary' => ['usedPercent' => 25, 'windowDurationMins' => 300, 'resetsAt' => 1793318400], 'secondary' => ['usedPercent' => 70, 'windowDurationMins' => 10080, 'resetsAt' => 1793923200]]], 'secret' => 'never-expose']]);
        $this->app->instance(CodexAccountReader::class, $reader);
        $response = $this->actingAs($user)->getJson('/api/connections/openai/usage')->assertOk()->assertJsonPath('plan', 'plus')->assertJsonPath('status', 'available')->assertJsonPath('buckets.0.windows.0.remaining_percent', 75)->assertJsonPath('buckets.0.windows.1.remaining_percent', 30)->assertJsonCount(2, 'buckets.0.windows');
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertStringNotContainsString('never-expose', $response->getContent());
        $this->getJson('/api/connections/openai/usage')->assertOk()->assertJsonPath('buckets.0.windows.0.remaining_percent', 75);
    }

    public function test_sign_in_mode_without_quota_exposes_unknown_instead_of_zero_or_unlimited(): void
    {
        $user = User::factory()->create();
        $this->connect($user, 'test-token');
        $this->identity();
        $reader = Mockery::mock(CodexAccountReader::class);
        $reader->shouldReceive('read')->once()->andReturn(['account' => null, 'limits' => null]);
        $this->app->instance(CodexAccountReader::class, $reader);
        $this->actingAs($user)->getJson('/api/connections/openai/usage')->assertOk()->assertJsonPath('plan', null)->assertJsonPath('status', 'not_exposed')->assertJsonCount(0, 'buckets')->assertJsonPath('manage_url', 'https://chatgpt.com/settings/usage');
    }

    public function test_each_user_consults_their_own_account_and_cache(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->connect($first, 'first-test-token');
        $this->connect($second, 'second-test-token');
        $this->identity();
        $reader = Mockery::mock(CodexAccountReader::class);
        $reader->shouldReceive('read')->once()->with('first-test-token')->andReturn(['account' => ['planType' => 'plus'], 'limits' => null]);
        $reader->shouldReceive('read')->once()->with('second-test-token')->andReturn(['account' => ['planType' => 'pro'], 'limits' => null]);
        $this->app->instance(CodexAccountReader::class, $reader);
        $this->actingAs($first)->getJson('/api/connections/openai/usage')->assertJsonPath('plan', 'plus');
        $this->actingAs($second)->getJson('/api/connections/openai/usage')->assertJsonPath('plan', 'pro');
    }

    public function test_provider_failure_is_safe_and_invalid_percentages_are_not_displayed(): void
    {
        $user = User::factory()->create();
        $connection = $this->connect($user, 'first-test-token');
        $this->identity();
        $reader = Mockery::mock(CodexAccountReader::class);
        $reader->shouldReceive('read')->once()->with('first-test-token')->andThrow(new \RuntimeException('never-expose-provider-response'));
        $reader->shouldReceive('read')->once()->with('new-test-token')->andReturn(['account' => null, 'limits' => ['rateLimits' => ['primary' => ['usedPercent' => -5], 'secondary' => ['usedPercent' => 120]]]]);
        $this->app->instance(CodexAccountReader::class, $reader);
        $response = $this->actingAs($user)->getJson('/api/connections/openai/usage')->assertOk()->assertJsonPath('status', 'unavailable');
        $this->assertStringNotContainsString('never-expose', $response->getContent());
        $connection->update(['credentials' => ['access_token' => 'new-test-token']]);
        $this->getJson('/api/connections/openai/usage')->assertOk()->assertJsonCount(0, 'buckets');
    }

    public function test_guests_and_users_without_their_own_connection_cannot_query_other_accounts(): void
    {
        $owner = User::factory()->create();
        $this->connect($owner, 'owner-test-token');
        $reader = Mockery::mock(CodexAccountReader::class);
        $reader->shouldNotReceive('read');
        $this->app->instance(CodexAccountReader::class, $reader);
        $this->getJson('/api/connections/openai/usage')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/connections/openai/usage')->assertUnprocessable();
    }
}
