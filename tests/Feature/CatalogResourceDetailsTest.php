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
use Tests\TestCase;

class CatalogResourceDetailsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/details-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(): array
    {
        $u = User::factory()->create();
        $c = Connection::create(['user_id' => $u->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'BI']]]]);

        return [$u, $c, app(BiCatalog::class)->context($c, ['id' => '321', 'name' => 'BI'])];
    }

    public function test_presentation_details_use_authorized_remote_list_and_pull_instead_of_unsupported_info(): void
    {
        [$u,$c,$p] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->once()->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a === ['list', 'presentation', '--remote', '--limit', '500', '--offset', '0'])->once()->andReturn(['data' => ['matched' => 1, 'results' => [['id' => 7, 'nome' => 'Telão']]]]);
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a === ['pull', 'presentation:7', '--discard-local'])->once()->andReturnUsing(function () use ($p) {
            $folder = app(WorkspaceFiles::class)->path($p).'/presentations';
            File::ensureDirectoryExists($folder);
            File::put($folder.'/telao--7.yaml', "id: 7\nkind: presentation\ntenantId: 321\n---\nnome: Telão\nitems: []\n");

            return ['data' => []];
        });
        $this->actingAs($u)->getJson('/api/catalog/321/resources/presentation/7')->assertOk()->assertJsonPath('metadata_complete', true)->assertJsonPath('details.nome', 'Telão');
    }

    public function test_stale_local_metadata_is_not_served_for_a_resource_no_longer_listed(): void
    {
        [$u] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a[0] === 'list')->once()->andReturn(['data' => ['matched' => 0, 'results' => []]]);
        $this->actingAs($u)->getJson('/api/catalog/321/resources/writeback/7')->assertNotFound();
    }

    public function test_a_readable_resource_without_authoring_metadata_has_an_explicit_partial_status(): void
    {
        [$u] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a[0] === 'list')->once()->andReturn(['data' => ['matched' => 1, 'results' => [['id' => 7, 'nome' => 'Cadastro']]]]);
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a[0] === 'pull')->once()->andThrow(new \RuntimeException('Sem permissão de autoria', 6));
        $this->actingAs($u)->getJson('/api/catalog/321/resources/writeback/7')->assertOk()->assertJsonPath('metadata_complete', false)->assertJsonPath('details.nome', 'Cadastro');
    }

    public function test_secret_field_aliases_are_protected_without_masking_resource_identifiers(): void
    {
        $result = app(BiCatalog::class)->redact(['token' => 'hidden', 'privateKey' => 'hidden', 'api-key' => 'hidden', 'nested' => ['auth_token' => 'hidden'], 'tokenId' => 23, 'primaryKeyId' => 8]);
        $this->assertStringNotContainsString('hidden', json_encode($result));
        $this->assertSame(23, $result['tokenId']);
        $this->assertSame(8, $result['primaryKeyId']);
    }
}
