<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/catalog-performance-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connect(): array
    {
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Homologação']]]]);

        return [$user, $connection];
    }

    private function remote(array $header, bool $listed = true): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments) use ($header, $listed): array {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'status') {
                return ['data' => ['tenantId' => 321, 'clean' => []]];
            }
            if ($arguments[0] === 'info') {
                return ['data' => ['payload' => ['header' => $header, 'body' => ['nome' => 'Aplicação conferida', 'deskId' => 7, 'tables' => [], 'dashboards' => [], 'password' => 'SYNTHETIC-PRIVATE-VALUE']]]];
            }
            if ($arguments[0] === 'list') {
                if ($arguments[1] === 'variables') {
                    return ['data' => ['count' => 0, 'variables' => []]];
                }
                $rows = $arguments[1] === 'app' && $listed ? [['id' => 10, 'nome' => 'Aplicação conferida']] : [];

                return ['data' => ['matched' => count($rows), 'results' => $rows]];
            }
            if ($arguments === ['pull', '--discard-local']) {
                return ['data' => []];
            }
            throw new RuntimeException('Unexpected BI operation in the read-only test.');
        });
    }

    private function cachedImage(Connection $connection): void
    {
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Homologação']);
        $directory = app(WorkspaceFiles::class)->path($context).'/exports/previews';
        File::ensureDirectoryExists($directory, 0700, true);
        File::put($directory.'/app-10-main.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZK98AAAAASUVORK5CYII='));
    }

    public function test_opening_an_application_uses_fresh_specific_details_without_relisting_a_warm_catalog(): void
    {
        [$user] = $this->connect();
        $this->remote(['tenantId' => 321, 'id' => 10, 'kind' => 'app']);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk();
        $this->commands = [];

        foreach (range(1, 2) as $attempt) {
            $response = $this->getJson('/api/catalog/321/resources/app/10')->assertOk()->assertJsonPath('resource.name', 'Aplicação conferida')->assertJsonPath('details.password', '[protegido]');
            $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-VALUE', $response->getContent());
        }

        $this->assertSame([['info', 'app:10', '--full'], ['info', 'app:10', '--full']], $this->commands);
    }

    #[DataProvider('mismatchedHeaders')]
    public function test_mismatched_application_identity_returns_422_without_exposing_the_payload(array $header): void
    {
        [$user] = $this->connect();
        $this->remote($header);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/app/10')->assertUnprocessable()->assertJsonValidationErrors('catalog');

        $this->assertStringNotContainsString('Aplicação conferida', $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-VALUE', $response->getContent());
        $this->assertSame([['info', 'app:10', '--full']], $this->commands);
    }

    public static function mismatchedHeaders(): array
    {
        return [
            'another tenant' => [['tenantId' => 999, 'id' => 10, 'kind' => 'app']],
            'another resource' => [['tenantId' => 321, 'id' => 11, 'kind' => 'app']],
            'another kind' => [['tenantId' => 321, 'id' => 10, 'kind' => 'table']],
            'invalid identifier' => [['tenantId' => 321, 'id' => [10], 'kind' => 'app']],
        ];
    }

    public function test_incomplete_identity_still_requires_membership_and_returns_404_for_an_unlisted_application(): void
    {
        [$user] = $this->connect();
        $this->remote(['tenantId' => 321], listed: false);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/app/10')->assertNotFound();

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-VALUE', $response->getContent());
        $this->assertSame([['info', 'app:10', '--full'], ['list', 'app', '--remote', '--limit', '500', '--offset', '0']], $this->commands);
    }

    public function test_an_authorized_legacy_application_without_a_complete_header_keeps_working(): void
    {
        [$user] = $this->connect();
        $this->remote(['tenantId' => 321]);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk();
        $this->commands = [];

        $this->getJson('/api/catalog/321/resources/app/10')->assertOk()->assertJsonPath('resource.name', 'Aplicação conferida');

        $this->assertSame([['info', 'app:10', '--full'], ['list', 'app', '--remote', '--limit', '500', '--offset', '0']], $this->commands);
    }

    public function test_a_cached_image_checks_current_access_without_rebuilding_unrelated_catalog_areas(): void
    {
        [$user, $connection] = $this->connect();
        $this->remote(['tenantId' => 321, 'id' => 10, 'kind' => 'app']);
        $this->cachedImage($connection);

        $this->actingAs($user)->get('/api/catalog/321/apps/10/image')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame([['info', 'app:10', '--full']], $this->commands);
    }

    public function test_a_new_permission_refusal_is_not_hidden_by_a_warm_catalog_or_existing_image(): void
    {
        [$user, $connection] = $this->connect();
        $this->cachedImage($connection);
        $this->remote(['tenantId' => 321, 'id' => 10, 'kind' => 'app']);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk();
        $this->getJson('/api/catalog/321/resources/app/10')->assertOk();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->once()->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($connection, array $arguments): bool => $arguments === ['info', 'app:10', '--full'])->once()->andThrow(new RuntimeException('Acesso recusado pelo BI.', 6));

        $this->getJson('/api/catalog/321/apps/10/image')->assertUnprocessable()->assertJsonValidationErrors('image');
    }
}
