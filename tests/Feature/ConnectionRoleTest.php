<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_connect_their_own_bi_account_without_gaining_write_access(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $client = $this->mock(LumoClient::class);
        $client->shouldReceive('login')->once()->with($user->id, 'viewer@example.test', 'test-password', null)->andReturnUsing(fn () => Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'private-token']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'Consulta']]]]));
        $this->actingAs($user)->postJson('/api/connections/horus/login', ['email' => 'viewer@example.test', 'password' => 'test-password'])->assertOk()->assertJsonPath('status', 'connected')->assertJsonMissingPath('credentials');
        $this->postJson('/api/projects', ['name' => 'Teste'])->assertForbidden();
        $this->postJson('/api/catalog/321/projects', ['resource' => 'app:1'])->assertForbidden();
    }
}
