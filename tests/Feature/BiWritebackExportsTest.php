<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\User;
use App\Services\BiNativeFailure;
use App\Services\BiWritebackExports;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BiWritebackExportsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/writeback-exports-'.Str::uuid());
        config(['horus.workspace_root' => $this->root.'/workspaces', 'horus.downloads.root' => $this->root.'/downloads']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function setupExport(string $mode = 'valid'): array
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'a', 'tenants' => [['id' => '321', 'name' => 'Teste']]]]);
        $state = (object) ['calls' => [], 'bytes' => null, 'listed' => true, 'tenant' => 321];
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($actor, array $args, $context) use ($mode, $user, $connection, $state): array {
            $state->calls[] = $args;
            if ($args[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['tenantId' => $state->tenant], 'body' => ['name' => 'Metas']]]];
            }
            if ($args[0] === 'list') {
                return ['data' => ['matched' => $state->listed && $args[1] === 'writeback' ? 1 : 0, 'results' => $state->listed && $args[1] === 'writeback' ? [['id' => 10, 'name' => 'Metas']] : []]];
            }
            if (in_array($args[0], ['pull', 'fetch'], true)) {
                return ['data' => []];
            }
            $this->assertSame(['writeback', 'export', 'writeback:10', '--output'], array_slice($args, 0, 4));
            $path = $args[4];
            $this->assertStringStartsWith($this->root.'/downloads/writeback-', $path);
            if ($mode === 'denied') {
                throw BiNativeFailure::detect($args, 9, '', 'Error: download export for writeback:10 failed: 403 forbidden');
            }
            if ($mode === 'partial') {
                File::put($path, 'partial');
                throw new RuntimeException('Exportação interrompida.');
            }
            if ($mode === 'symlink') {
                File::put($this->root.'/preserve.txt', 'private');
                symlink($this->root.'/preserve.txt', $path);

                return ['data' => []];
            }
            if ($mode === 'invalid') {
                File::put($path, '<html>authentication error</html>');

                return ['data' => []];
            }
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
            $zip->close();
            $state->bytes = File::get($path);
            if ($mode === 'reconnect') {
                $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'b']]);
            }
            if ($mode === 'revoked') {
                $user->newQuery()->whereKey($user->id)->update(['active' => false, 'access_version' => $user->access_version + 1]);
            }

            if ($mode === 'access_changed') {
                $user->newQuery()->whereKey($user->id)->update(['access_version' => $user->access_version + 1]);
            }

            return ['data' => ['download_url' => 'https://example.invalid/private?token=private-value']];
        });

        return [$user, $connection, $state];
    }

    public function test_export_streams_the_exact_xlsx_privately_and_removes_the_named_file(): void
    {
        [$user, , $state] = $this->setupExport();
        $response = $this->actingAs($user)->get('/api/catalog/321/writebacks/10/export');

        $response->assertOk()->assertDownload('cadastro-10.xlsx')->assertStreamed()->assertHeader('Content-Type', BiWritebackExports::MIME)->assertHeaderContains('Cache-Control', 'no-store')->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent($state->bytes);
        $this->assertSame([], File::files($this->root.'/downloads'));
        $this->assertSame(0, Approval::count());
        $this->assertSame(0, AgentRun::count());
    }

    public function test_missing_authentication_returns_401_without_starting_native_export(): void
    {
        $this->getJson('/api/catalog/321/writebacks/10/export')->assertUnauthorized();
    }

    public function test_a_resource_outside_the_tenant_scope_returns_404_without_exporting(): void
    {
        [$user, , $state] = $this->setupExport();
        $state->listed = false;

        $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export')->assertNotFound();
        $this->assertFalse(collect($state->calls)->contains(fn ($args) => $args[0] === 'writeback'));
    }

    public function test_export_rejects_sample_filters_instead_of_silently_exporting_a_larger_set(): void
    {
        [$user, , $state] = $this->setupExport();

        $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export?limit=20')->assertUnprocessable()->assertJsonPath('errors.export.0', 'A exportação inclui todos os registros e colunas permitidos. Filtros e limites da amostra não se aplicam a esta planilha.');
        $this->assertSame([], $state->calls);
    }

    public static function unavailableFiles(): array
    {
        return ['partial' => ['partial'], 'invalid' => ['invalid'], 'reconnected' => ['reconnect'], 'revoked' => ['revoked'], 'access changed' => ['access_changed']];
    }

    #[DataProvider('unavailableFiles')]
    public function test_a_failed_or_outdated_export_returns_422_and_discards_the_temporary_file(string $mode): void
    {
        [$user, $connection] = $this->setupExport($mode);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export')->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->assertSame([], File::files($this->root.'/downloads'));
    }

    public function test_a_native_denial_is_actionable_without_disconnecting_the_user(): void
    {
        [$user, $connection] = $this->setupExport('denied');

        $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export')->assertUnprocessable()->assertJsonPath('errors.export.0', 'O BI recusou o download desta planilha. Confirme com o administrador do BI a permissão de exportar arquivos deste cadastro.');
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame([], File::files($this->root.'/downloads'));
    }

    public function test_a_symbolic_link_is_rejected_without_reading_or_deleting_its_target(): void
    {
        [$user] = $this->setupExport('symlink');

        $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export')->assertUnprocessable()->assertJsonPath('errors.export.0', 'O BI não retornou um arquivo de exportação válido.');
        $this->assertSame('private', File::get($this->root.'/preserve.txt'));
        $this->assertSame([], File::files($this->root.'/downloads'));
    }

    public function test_an_export_over_the_configured_limit_is_rejected_without_truncation(): void
    {
        [$user] = $this->setupExport();
        config(['horus.downloads.max_bytes' => 1]);

        $this->actingAs($user)->getJson('/api/catalog/321/writebacks/10/export')->assertUnprocessable()->assertJsonPath('errors.export.0', 'A planilha excede o limite de exportação desta instalação ou foi retornada vazia.');
        $this->assertSame([], File::files($this->root.'/downloads'));
    }

    public function test_cleanup_removes_only_its_own_expired_regular_download_files(): void
    {
        $this->freezeTime();
        $root = $this->root.'/downloads';
        File::ensureDirectoryExists($root, 0700, true);
        $expired = $root.'/writeback-'.Str::uuid().'.xlsx';
        $fresh = $root.'/writeback-'.Str::uuid().'.xlsx';
        $other = $root.'/preserve.xlsx';
        foreach ([$expired, $fresh, $other] as $path) {
            File::put($path, 'bytes');
        }
        touch($expired, now()->subHours(2)->getTimestamp());
        touch($other, now()->subHours(2)->getTimestamp());
        $link = $root.'/writeback-'.Str::uuid().'.xlsx';
        symlink($other, $link);

        $this->assertSame(1, app(BiWritebackExports::class)->purgeStale());
        $this->assertFileDoesNotExist($expired);
        $this->assertFileExists($fresh);
        $this->assertFileExists($other);
        $this->assertTrue(is_link($link));
    }
}
