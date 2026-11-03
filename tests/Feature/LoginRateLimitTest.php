<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_colleagues_on_the_same_network_can_log_in_to_distinct_accounts(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $users = User::factory()->count(12)->create();

        foreach ($users as $user) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertDatabaseCount('audit_events', 12);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_repeated_attempts_for_the_same_account_remain_rate_limited(): void
    {
        User::factory()->create(['email' => 'account@example.test']);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/login', ['email' => $attempt % 2 ? 'ACCOUNT@example.test' : 'account@example.test', 'password' => 'invalid'])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'account@example.test', 'password' => 'password'])->assertTooManyRequests();

        $this->assertGuest();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_network_budget_limits_attempts_across_many_different_accounts(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/login', ['email' => 'missing-'.$attempt.'@example.test', 'password' => 'invalid'])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'another-missing@example.test', 'password' => 'invalid'])->assertTooManyRequests();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
