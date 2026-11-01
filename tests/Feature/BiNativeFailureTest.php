<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\BiNativeFailure;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiNativeFailureTest extends TestCase
{
    use RefreshDatabase;

    private const CAPACITY = 'Error: start import for writeback:10: Limite de linhas dos Cadastros atingido (0/0). Esta operação precisa de 2 linhas; margem disponível: 0 linhas.';

    private const NO_EXECUTION = 'Error: status flow:10: Flow 10 ainda não tem execução registrada.';

    private const MODULE_UNAVAILABLE = 'Error: list investigation: A Sentinela não está disponível para este ambiente.';

    public function test_an_unavailable_investigation_module_is_distinct_from_user_permission(): void
    {
        $failure = BiNativeFailure::detect(['list', 'investigation', '--remote'], 6, '', self::MODULE_UNAVAILABLE."\n");

        $this->assertInstanceOf(BiNativeFailure::class, $failure);
        $this->assertSame('module_unavailable', $failure->reason);
        $this->assertSame(['module' => 'sentinela'], $failure->details);
        $this->assertSame(6, $failure->getCode());
        $this->assertStringContainsString('habilitação do módulo Sentinela', $failure->getMessage());
        $this->assertStringNotContainsString('permissões', $failure->getMessage());
    }

    public function test_a_first_execution_status_returns_a_scoped_safe_reason(): void
    {
        $failure = BiNativeFailure::detect(['flow', 'status', 'flow:10'], 9, '', self::NO_EXECUTION."\n");

        $this->assertInstanceOf(BiNativeFailure::class, $failure);
        $this->assertSame('flow_no_execution', $failure->reason);
        $this->assertSame(['resource' => 'flow:10'], $failure->details);
        $this->assertSame('Esta fonte ainda não possui uma execução registrada.', $failure->getMessage());
    }

    public function test_capacity_error_returns_only_safe_counts_for_an_import(): void
    {
        $failure = BiNativeFailure::detect(['writeback', 'import', 'writeback:10', 'file.xlsx'], 9, '', self::CAPACITY."\nhttps://example.invalid/download?token=private-value");

        $this->assertInstanceOf(BiNativeFailure::class, $failure);
        $this->assertSame('writeback_capacity', $failure->reason);
        $this->assertSame(['used' => 0, 'limit' => 0, 'requested' => 2, 'available' => 0], $failure->details);
        $this->assertStringContainsString('administrador do BI', $failure->getMessage());
        $this->assertStringNotContainsString('private-value', $failure->getMessage());
        $this->assertSame(9, $failure->getCode());
    }

    public function test_atomic_creation_from_a_file_classifies_the_same_capacity_failure(): void
    {
        $failure = BiNativeFailure::detect(['new', 'writeback', '--name', 'Teste', '--from-file', 'file.xlsx'], 9, '', self::CAPACITY);

        $this->assertSame('writeback_capacity', $failure?->reason);
    }

    public static function unrelatedFailures(): array
    {
        return [
            'unavailable module with another exit code' => [['list', 'investigation'], 9, self::MODULE_UNAVAILABLE],
            'unavailable module from another command' => [['info', 'investigation:10'], 6, self::MODULE_UNAVAILABLE],
            'unavailable module from another category' => [['list', 'app'], 6, self::MODULE_UNAVAILABLE],
            'investigation permission denial' => [['list', 'investigation'], 6, 'Error: list investigation: Sem permissão para acessar este recurso.'],
            'untrusted extra module output' => [['list', 'investigation'], 6, self::MODULE_UNAVAILABLE."\nTOKEN_EXPIRED"],
            'authentication' => [['writeback', 'import', 'writeback:10'], 6, self::CAPACITY],
            'another command' => [['credential', 'query', 'credential:10'], 9, self::CAPACITY],
            'empty creation' => [['new', 'writeback', '--name', 'Teste'], 9, self::CAPACITY],
            'another export' => [['writeback', 'export', 'writeback:11'], 9, 'Error: download export for writeback:10 failed: 403 forbidden'],
            'server error' => [['writeback', 'export', 'writeback:10'], 9, 'Error: download export for writeback:10 failed: 500 error'],
            'oversized integer' => [['writeback', 'import', 'writeback:10'], 9, str_replace('(0/0)', '(999999999999999999999/0)', self::CAPACITY)],
            'no execution for another flow' => [['flow', 'status', 'flow:11'], 9, self::NO_EXECUTION],
            'mismatched status identity' => [['flow', 'status', 'flow:10'], 9, str_replace('Flow 10', 'Flow 11', self::NO_EXECUTION)],
            'no execution authentication' => [['flow', 'status', 'flow:10'], 6, self::NO_EXECUTION],
            'no execution from another command' => [['flow', 'run', 'flow:10'], 9, self::NO_EXECUTION],
            'unknown status failure' => [['flow', 'status', 'flow:10'], 9, 'Error: status flow:10: permission denied'],
            'untrusted extra status output' => [['flow', 'status', 'flow:10'], 9, self::NO_EXECUTION."\nPrivate diagnostic must stay unclassified"],
        ];
    }

    #[DataProvider('unrelatedFailures')]
    public function test_unrecognized_or_unrelated_errors_keep_the_generic_failure(array $arguments, int $code, string $message): void
    {
        $this->assertNull(BiNativeFailure::detect($arguments, $code, '', $message));
    }

    public function test_native_download_denial_preserves_the_connected_session_and_secret_credentials(): void
    {
        $root = storage_path('framework/testing/native-failure-'.Str::uuid());
        File::ensureDirectoryExists($root, 0700, true);
        $binary = $root.'/native';
        File::put($binary, "#!/bin/sh\nprintf '%s\\n' 'Error: download export for writeback:10 failed: 403 forbidden' >&2\nexit 9\n");
        chmod($binary, 0700);
        config(['horus.lumo.binary' => $binary]);
        $user = User::factory()->create();
        $credentials = ['auth' => ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh']];
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => $credentials, 'metadata' => ['session_generation' => 'session-a']]);
        try {
            app(LumoClient::class)->run($connection, ['writeback', 'export', 'writeback:10']);
            $this->fail('A negativa de download precisa ser informada.');
        } catch (BiNativeFailure $failure) {
            $this->assertSame('writeback_export_forbidden', $failure->reason);
            $this->assertStringContainsString('permissão de exportar', $failure->getMessage());
            $this->assertSame('connected', $connection->fresh()->status);
            $this->assertSame($credentials, $connection->fresh()->credentials);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
