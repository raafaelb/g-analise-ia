<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\BiBusy;
use App\Services\BiNativeFailure;
use App\Services\LumoClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LumoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixtureDirectory = null;

    protected function tearDown(): void
    {
        if ($this->fixtureDirectory) {
            File::deleteDirectory($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    private function fakeCli(array|string $output, int $exitCode = 0): void
    {
        $this->fixtureDirectory = storage_path('framework/testing/lumo-'.Str::uuid());
        File::ensureDirectoryExists($this->fixtureDirectory, 0700, true);
        $binary = $this->fixtureDirectory.'/lumo';
        $encoded = base64_encode(is_array($output) ? json_encode($output, JSON_THROW_ON_ERROR) : $output);
        File::put($binary, '#!'.PHP_BINARY."\n<?php\n".'if (!is_file(getenv("HOME")."/.lumo/auth.json")) {exit(6);} echo base64_decode("'.$encoded.'");exit('.$exitCode.');');
        chmod($binary, 0700);
        config(['horus.lumo.binary' => $binary]);
    }

    private function connection(User $user, array $metadata = []): Connection
    {
        return Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test-session'], 'config' => []], 'metadata' => $metadata]);
    }

    public function test_refresh_endpoint_reads_all_tenants_from_the_real_cli_envelope(): void
    {
        $this->fakeCli(['schemaVersion' => 1, 'command' => 'tenants list', 'data' => ['count' => 2, 'tenants' => [['id' => 123, 'nome' => 'Comercial'], ['id' => 456, 'nome' => 'Operação']]]]);
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $this->actingAs($user)->postJson('/api/connections/horus/refresh')->assertOk()->assertJsonCount(2, 'metadata.tenants')->assertJsonPath('metadata.tenants.0.id', '123')->assertJsonPath('metadata.tenants.1.name', 'Operação')->assertJsonMissingPath('credentials');
        $this->assertCount(2, $connection->fresh()->metadata['tenants']);
        $this->assertNotEmpty($connection->fresh()->metadata['checked_at']);
    }

    public function test_direct_tenant_list_is_still_supported(): void
    {
        $this->fakeCli([['id' => 123, 'name' => 'Comercial']]);
        $connection = $this->connection(User::factory()->create());
        app(LumoClient::class)->refreshTenants($connection);
        $this->assertSame([['id' => '123', 'name' => 'Comercial']], $connection->fresh()->metadata['tenants']);
    }

    public function test_unrecognized_response_does_not_silently_replace_accesses_with_empty_list(): void
    {
        $this->fakeCli(['schemaVersion' => 1, 'command' => 'tenants list', 'data' => ['unexpected' => 'format']]);
        $user = User::factory()->create();
        $connection = $this->connection($user, ['tenants' => [['id' => '123', 'name' => 'Comercial']], 'checked_at' => '2026-10-29T10:00:00Z']);
        $this->actingAs($user)->postJson('/api/connections/horus/refresh')->assertUnprocessable();
        $this->assertSame('123', $connection->fresh()->metadata['tenants'][0]['id']);
        $this->assertSame('2026-10-29T10:00:00Z', $connection->fresh()->metadata['checked_at']);
    }

    public function test_genuinely_empty_tenants_response_is_distinct_from_a_parse_failure(): void
    {
        $this->fakeCli(['schemaVersion' => 1, 'command' => 'tenants list', 'data' => ['count' => 0, 'tenants' => []]]);
        $connection = $this->connection(User::factory()->create());
        app(LumoClient::class)->refreshTenants($connection);
        $this->assertSame([], $connection->fresh()->metadata['tenants']);
    }

    public function test_declared_count_must_match_the_received_tenant_list(): void
    {
        $this->fakeCli(['schemaVersion' => 1, 'command' => 'tenants list', 'data' => ['count' => 2, 'tenants' => [['id' => 123, 'nome' => 'Comercial']]]]);
        $connection = $this->connection(User::factory()->create());
        $this->expectException(RuntimeException::class);
        app(LumoClient::class)->refreshTenants($connection);
    }

    public function test_domain_data_field_is_preserved_when_it_is_not_a_cli_envelope(): void
    {
        $payload = ['id' => 123, 'data' => ['columns' => ['valor']]];
        $this->fakeCli($payload);
        $connection = $this->connection(User::factory()->create());
        $this->assertSame($payload, app(LumoClient::class)->run($connection, ['info', 'table:123'])['data']);
    }

    public function test_permission_denial_does_not_disconnect_a_valid_session(): void
    {
        $this->fakeCli(['error' => ['code' => 'FORBIDDEN']], 6);
        $connection = $this->connection(User::factory()->create());
        try {
            app(LumoClient::class)->run($connection, ['list', 'agent']);
            $this->fail('Permissão negada foi aceita.');
        } catch (RuntimeException $e) {
            $this->assertSame(6, $e->getCode());
            $this->assertSame('connected', $connection->fresh()->status);
        }
    }

    public function test_module_unavailability_preserves_the_connected_session_and_credentials(): void
    {
        $this->fakeCli('Error: list investigation: A Sentinela não está disponível para este ambiente.', 6);
        $connection = $this->connection(User::factory()->create());
        $credentials = $connection->credentials;

        try {
            app(LumoClient::class)->run($connection, ['list', 'investigation', '--remote']);
            $this->fail('A indisponibilidade do módulo precisa ser informada.');
        } catch (BiNativeFailure $failure) {
            $this->assertSame('module_unavailable', $failure->reason);
            $this->assertSame(6, $failure->getCode());
        }

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame($credentials, $connection->fresh()->credentials);
    }

    public function test_expired_authentication_takes_precedence_over_a_module_failure(): void
    {
        $this->fakeCli('Error: list investigation: A Sentinela não está disponível para este ambiente.', 6);
        $connection = $this->connection(User::factory()->create());
        $connection->update(['credentials' => ['auth' => ['token' => 'synthetic-expired', 'expires_at' => '2020-01-01T00:00:00Z']]]);

        try {
            app(LumoClient::class)->run($connection, ['list', 'investigation', '--remote']);
            $this->fail('A autenticação expirada precisa ser informada.');
        } catch (RuntimeException $failure) {
            $this->assertNotInstanceOf(BiNativeFailure::class, $failure);
            $this->assertSame('O acesso ao G-Analise expirou. Conecte novamente.', $failure->getMessage());
        }

        $this->assertSame('reauthentication_required', $connection->fresh()->status);
    }

    public function test_expired_session_requires_reauthentication(): void
    {
        $this->fakeCli(['error' => ['code' => 'TOKEN_EXPIRED']], 6);
        $connection = $this->connection(User::factory()->create());
        try {
            app(LumoClient::class)->run($connection, ['list', 'app']);
            $this->fail('Sessão expirada foi aceita.');
        } catch (RuntimeException $e) {
            $this->assertSame('reauthentication_required', $connection->fresh()->status);
        }
    }

    public function test_native_token_rotation_is_saved_encrypted_for_the_next_command(): void
    {
        $this->fakeCli(['count' => 0, 'tenants' => []]);
        $binary = config('horus.lumo.binary');
        $script = file_get_contents($binary);
        $script = str_replace('echo base64_decode(', 'file_put_contents(getenv("HOME")."/.lumo/auth.json",json_encode(["access_token"=>"rotated-test-access","refresh_token"=>"rotated-test-refresh","expires_at"=>"2030-01-01T00:00:00Z"]));echo base64_decode(', $script);
        File::put($binary, $script);
        $connection = $this->connection(User::factory()->create());
        app(LumoClient::class)->run($connection, ['tenants', 'list']);
        $this->assertSame('rotated-test-refresh', $connection->fresh()->credentials['auth']['refresh_token']);
        $raw = DB::table('connections')->where('id', $connection->id)->value('credentials');
        $this->assertStringNotContainsString('rotated-test-access', $raw);
    }

    public function test_expired_session_failure_requires_reconnection_even_when_cli_reports_forbidden(): void
    {
        $this->fakeCli(['error' => ['code' => 'FORBIDDEN']], 6);
        $connection = $this->connection(User::factory()->create());
        $connection->update(['credentials' => ['auth' => ['access_token' => 'expired-test', 'refresh_token' => 'invalid-test', 'expires_at' => '2020-01-01T00:00:00Z'], 'config' => []]]);
        try {
            app(LumoClient::class)->run($connection, ['tenants', 'list']);
            $this->fail('Sessão expirada foi aceita.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expirou', $e->getMessage());
        }
        $this->assertSame('reauthentication_required', $connection->fresh()->status);
    }

    private function rotateDuringCommand(): void
    {
        $this->fakeCli(['count' => 0, 'tenants' => []]);
        $binary = config('horus.lumo.binary');
        File::put($binary, str_replace('echo base64_decode(', 'file_put_contents(getenv("HOME")."/.lumo/auth.json",json_encode(["access_token"=>"old-session-rotated-access","refresh_token"=>"old-session-rotated-refresh"]));echo base64_decode(', file_get_contents($binary)));
    }

    private function reconnectAtCompletion(Connection $connection): void
    {
        $reconnected = false;
        File::partialMock()->shouldReceive('get')->withArgs(fn ($path) => str_ends_with($path, '/.lumo/auth.json'))->atLeast()->once()->andReturnUsing(function ($path) use ($connection, &$reconnected) {
            if (! $reconnected) {
                $connection->fresh()->update(['status' => 'connected', 'credentials' => ['auth' => ['access_token' => 'new-login-access', 'refresh_token' => 'new-login-refresh']], 'metadata' => ['session_generation' => 'new-login']]);
                $reconnected = true;
            }

            return file_get_contents($path);
        });
    }

    public function test_old_native_rotation_cannot_overwrite_a_new_login(): void
    {
        $this->rotateDuringCommand();
        $connection = $this->connection(User::factory()->create(), ['session_generation' => 'old-login']);
        $this->reconnectAtCompletion($connection);
        $this->assertSessionChanged(fn () => app(LumoClient::class)->run($connection, ['tenants', 'list']));
        $this->assertSame('new-login', $connection->fresh()->metadata['session_generation']);
        $this->assertSame('new-login-refresh', $connection->fresh()->credentials['auth']['refresh_token']);
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_rotation_after_disconnect_cannot_restore_credentials(): void
    {
        $this->rotateDuringCommand();
        $connection = $this->connection(User::factory()->create(), ['session_generation' => 'old-login']);
        File::partialMock()->shouldReceive('get')->withArgs(fn ($path) => str_ends_with($path, '/.lumo/auth.json'))->once()->andReturnUsing(function ($path) use ($connection) {
            $connection->fresh()->update(['status' => 'disconnected', 'credentials' => null, 'metadata' => []]);

            return file_get_contents($path);
        });
        $this->assertSessionChanged(fn () => app(LumoClient::class)->run($connection, ['tenants', 'list']));
        $this->assertSame('disconnected', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->credentials);
    }

    public function test_failure_from_old_session_cannot_expire_a_new_login(): void
    {
        $this->fakeCli(['error' => ['code' => 'TOKEN_EXPIRED']], 6);
        $connection = $this->connection(User::factory()->create(), ['session_generation' => 'old-login']);
        $this->reconnectAtCompletion($connection);
        try {
            app(LumoClient::class)->run($connection, ['tenants', 'list']);
            $this->fail('O comando antigo deveria falhar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expirou', $e->getMessage());
        }
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('new-login-refresh', $connection->fresh()->credentials['auth']['refresh_token']);
    }

    private function assertSessionChanged(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Um resultado da sessão anterior foi aceito.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sessão G-Analise mudou', $e->getMessage());
        }
    }

    public function test_late_tenant_results_cannot_overwrite_accesses_of_a_new_login(): void
    {
        $connection = $this->connection(User::factory()->create(), ['session_generation' => 'old-login']);
        $client = \Mockery::mock(LumoClient::class)->makePartial();
        $client->shouldReceive('run')->once()->andReturnUsing(function () use ($connection) {
            $connection->fresh()->update(['metadata' => ['session_generation' => 'new-login', 'tenants' => [['id' => '456', 'name' => 'Conta nova']]]]);

            return ['data' => ['count' => 1, 'tenants' => [['id' => '123', 'name' => 'Conta antiga']]]];
        });
        $this->assertSessionChanged(fn () => $client->refreshTenants($connection));
        $this->assertSame('new-login', $connection->fresh()->metadata['session_generation']);
        $this->assertSame('456', $connection->fresh()->metadata['tenants'][0]['id']);
    }

    public function test_local_lint_errors_can_be_read_without_being_confused_with_success(): void
    {
        $this->fakeCli(['schemaVersion' => 1, 'command' => 'lint', 'data' => ['errorCount' => 1, 'results' => [['issues' => [['message' => 'yaml line 109']]]]]], 8);
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Validação local']);

        $result = app(LumoClient::class)->run($connection, ['lint', 'flows/test.yaml'], $project, 45, true);

        $this->assertSame(8, $result['exit_code']);
        $this->assertSame(1, $result['data']['errorCount']);
        $this->assertSame('yaml line 109', $result['data']['results'][0]['issues'][0]['message']);
    }

    public function test_diagnostic_mode_cannot_suppress_a_remote_command_failure(): void
    {
        $this->fakeCli(['error' => ['code' => 'INVALID_RESOURCE']], 8);
        $connection = $this->connection(User::factory()->create());

        $this->expectExceptionMessage('Somente a validação local');
        app(LumoClient::class)->run($connection, ['push', 'flow:1'], null, 45, true);
    }

    public function test_lock_contention_before_starting_a_command_has_a_distinct_retryable_failure(): void
    {
        $connection = $this->connection(User::factory()->create());
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $this->expectException(BiBusy::class);
        app(LumoClient::class)->run($connection, ['info', 'flow:1']);
    }

    public function test_a_lock_failure_after_command_entry_does_not_claim_the_command_was_unstarted(): void
    {
        $this->fakeCli(['ok' => true]);
        $connection = $this->connection(User::factory()->create());
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andReturnUsing(function ($seconds, $callback) {
            $callback();
            throw new LockTimeoutException;
        });
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        try {
            app(LumoClient::class)->run($connection, ['info', 'flow:1']);
            $this->fail('Falha foi ignorada.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(BiBusy::class, $e);
        }
    }
}
