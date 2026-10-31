<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\BiBusy;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
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

    public function test_busy_metadata_is_not_reported_as_missing_configuration(): void
    {
        [$user] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a[0] === 'list')->once()->andReturn(['data' => ['matched' => 1, 'results' => [['id' => 7, 'nome' => 'Cadastro']]]]);
        $mock->shouldReceive('run')->withArgs(fn ($c, $a) => $a[0] === 'pull')->once()->andThrow(new BiBusy);

        $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/7')->assertUnprocessable()->assertJsonMissingPath('metadata_complete')->assertJsonPath('errors.catalog.0', 'Outra operação do BI está em andamento nesta conta. Aguarde a conclusão e tente novamente.');
    }

    private function nativeTable(array $columns, mixed $returnedId = 7): array
    {
        [$user, , $context] = $this->context();
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments) use ($context, $columns, $returnedId): array {
            if ($arguments[0] === 'list') {
                $this->assertSame('table', $arguments[1]);

                return ['data' => ['matched' => 1, 'results' => [['id' => 7, 'nome' => 'Tabela']]]];
            }
            if ($arguments === ['fetch', 'table:7']) {
                return ['data' => []];
            }
            if ($arguments === ['info', 'table:7']) {
                return ['data' => ['payload' => ['id' => $returnedId, 'nome' => 'Tabela', 'columns' => $columns]]];
            }
            $this->assertSame(['pull', 'table:7', '--discard-local'], $arguments);
            $folder = app(WorkspaceFiles::class)->path($context).'/tables';
            File::ensureDirectoryExists($folder);
            $body = ['nome' => 'Tabela', 'columns' => [
                'VALOR (Number)' => ['label' => 'Valor do teste', 'description' => 'Explicação do valor', 'mask' => 'MOEDA', 'role' => 'fato'],
                'NOME (String)' => ['label' => 'Nome do registro', 'description' => 'Explicação do nome', 'mask' => null],
            ], 'expressions' => []];
            File::put($folder.'/table--7.yaml', "id: 7\nkind: table\ntenantId: 321\n---\n".Yaml::dump($body, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

            return ['data' => []];
        });

        return app(BiCatalog::class)->detail($user, '321', 'table', '7', false);
    }

    public function test_table_details_combine_physical_identity_with_current_authoring_presentation_by_name_and_type(): void
    {
        $detail = $this->nativeTable([
            ['id' => 18, 'column_name' => 'NOME', 'data_type' => 'String', 'display_order' => 0, 'label' => 'Nome no resumo'],
            ['id' => 17, 'column_name' => 'VALOR', 'data_type' => 'Number', 'display_order' => 1, 'label' => 'Valor no resumo'],
        ]);
        $this->assertTrue($detail['metadata_complete']);
        $this->assertSame(18, $detail['details']['columns'][0]['id']);
        $this->assertSame('Nome do registro', $detail['overview']['columns'][0]['label']);
        $this->assertSame('Explicação do nome', $detail['overview']['columns'][0]['description']);
        $this->assertSame(17, $detail['details']['columns'][1]['id']);
        $this->assertSame('Number', $detail['details']['columns'][1]['data_type']);
        $this->assertSame('Valor do teste', $detail['overview']['columns'][1]['label']);
        $this->assertSame('Explicação do valor', $detail['overview']['columns'][1]['description']);
        $this->assertSame('MOEDA', $detail['overview']['columns'][1]['mask']);
        $this->assertSame('fato', $detail['overview']['columns'][1]['semantic_role']);
    }

    public function test_table_metadata_is_not_attached_to_a_column_with_a_different_physical_type(): void
    {
        $detail = $this->nativeTable([['id' => 19, 'column_name' => 'VALOR', 'data_type' => 'String', 'label' => 'Texto novo']]);
        $this->assertFalse($detail['metadata_complete']);
        $this->assertSame('Texto novo', $detail['overview']['columns'][0]['label']);
        $this->assertNull($detail['overview']['columns'][0]['description']);
        $this->assertNull($detail['overview']['columns'][0]['mask']);
        $this->assertSame(19, $detail['details']['columns'][0]['id']);
    }

    #[DataProvider('invalidTableIdentities')]
    public function test_table_details_refuse_an_explicit_wrong_or_malformed_native_identity(mixed $returnedId): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tabela diferente');
        $this->nativeTable([], $returnedId);
    }

    public static function invalidTableIdentities(): array
    {
        return [[8], [[7]], [null]];
    }
}
