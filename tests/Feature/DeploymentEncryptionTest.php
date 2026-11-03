<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\DeploymentReadiness;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeploymentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_verifies_saved_connections_without_exposing_or_rewriting_credentials(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-private-token']]);
        $ciphertext = DB::table('connections')->where('id', $connection->id)->value('credentials');
        Artisan::shouldReceive('call')->once()->with('ganalise:health')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('{"ok":true}');

        $report = app(DeploymentReadiness::class)->inspect(true);

        $this->assertTrue($report['checks']['encrypted_connections']['ok']);
        $this->assertSame($ciphertext, DB::table('connections')->where('id', $connection->id)->value('credentials'));
        $this->assertStringNotContainsString('synthetic-private-token', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($ciphertext, json_encode($report, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_runtime_fails_for_a_connection_encrypted_with_a_different_key_and_preserves_the_record(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected']);
        $other = new Encrypter(str_repeat('Z', 32), 'AES-256-CBC');
        $ciphertext = $other->encryptString('{"access_token":"synthetic-other-private-token"}');
        DB::table('connections')->where('id', $connection->id)->update(['credentials' => $ciphertext]);
        Artisan::shouldReceive('call')->once()->with('ganalise:health')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('{"ok":true}');

        $report = app(DeploymentReadiness::class)->inspect(true);

        $this->assertFalse($report['ok']);
        $this->assertFalse($report['checks']['encrypted_connections']['ok']);
        $this->assertStringContainsString('APP_KEY', $report['checks']['encrypted_connections']['message']);
        $this->assertSame($ciphertext, DB::table('connections')->where('id', $connection->id)->value('credentials'));
        $this->assertDatabaseHas('connections', ['id' => $connection->id, 'status' => 'connected']);
        $this->assertStringNotContainsString('synthetic-other-private-token', json_encode($report, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }
}
