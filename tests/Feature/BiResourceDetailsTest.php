<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiResourceDetailsTest extends TestCase
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

    private function connect(array $bodies, array $denied = [], array $unreadable = [], array $summaries = []): User
    {
        $user = User::factory()->create();
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $folders = ['dw-desk' => 'dw-desks', 'bi-desk' => 'bi-desks', 'app' => 'apps', 'table' => 'tables', 'flow' => 'flows', 'schedule' => 'schedules', 'agent' => 'agents', 'credential' => 'credentials'];
        foreach ($bodies as $reference => $body) {
            [$kind, $id] = explode(':', $reference);
            if (! isset($folders[$kind])) {
                continue;
            }
            $directory = $this->root.'/'.$user->id.'/catalog-321-initial/'.$folders[$kind];
            File::ensureDirectoryExists($directory, 0700, true);
            File::put($directory.'/resource-'.$id.'.yaml', Yaml::dump(['id' => (int) $id, 'tenantId' => 321, 'kind' => $kind])."---\n".Yaml::dump($body, 10));
        }
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, $args) use ($bodies, $denied, $unreadable, $summaries) {
            if ($args[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['tenantId' => 321], 'body' => $summaries[$args[1]] ?? $bodies[$args[1]]]]];
            }
            if ($args[0] !== 'list') {
                return ['data' => []];
            }
            $kind = $args[1];
            if (in_array($kind, $denied, true)) {
                throw new \RuntimeException('Acesso negado', 6);
            }
            if ($kind === 'variables') {
                return ['data' => ['count' => 0, 'variables' => []]];
            }
            $rows = [];
            foreach ($bodies as $reference => $body) {
                [$type, $id] = explode(':', $reference);
                if ($type === $kind && ! in_array($reference, $unreadable, true)) {
                    $rows[] = ['id' => (int) $id, 'nome' => $body['nome'] ?? $reference, ...($kind === 'credential' && ! in_array('--remote', $args, true) ? ['extra' => ['key' => $body['key'] ?? null]] : [])];
                }
            }

            return ['data' => ['matched' => count($rows), 'results' => $rows]];
        });
        $this->app->instance(LumoClient::class, $mock);

        return $user;
    }

    public function test_data_desk_lists_only_its_authorized_tables_and_flows(): void
    {
        $user = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'Pedidos', 'deskId' => 7], 'flow:20' => ['nome' => 'Atualizar pedidos', 'deskId' => 7], 'table:11' => ['nome' => 'Outra mesa', 'deskId' => 8], 'table:12' => ['nome' => 'Sem acesso', 'deskId' => 7]], unreadable: ['table:12']);
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk()->assertJsonPath('resources.dw-desk.0.contents.0.count', 1)->assertJsonPath('resources.dw-desk.0.contents.1.count', 1);
        $this->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.items.0.ref', 'table:10')->assertJsonCount(1, 'related.0.items')->assertJsonPath('related.1.items.0.ref', 'flow:20')->assertJsonMissing(['name' => 'Sem acesso'])->assertJsonMissing(['name' => 'Outra mesa']);
    }

    public function test_application_desk_exposes_its_dashboards_and_unknown_metadata_is_not_a_draft(): void
    {
        $user = $this->connect(['bi-desk:7' => ['nome' => 'Diretoria'], 'app:10' => ['nome' => 'Receita', 'deskId' => 7, 'dashboards' => [['header' => ['id' => 99], 'body' => ['nome' => 'Mensal', 'widgets' => [['id' => 12]]]]]], 'app:11' => ['nome' => 'Sem metadados da mesa']]);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/bi-desk/7')->assertOk()->assertJsonPath('related.0.items.0.dashboards.0.id', 99)->assertJsonPath('related.0.items.0.dashboards.0.name', 'Mensal')->assertJsonPath('related.0.complete', false)->assertJsonCount(1, 'related.0.items');
        $this->getJson('/api/catalog/321')->assertOk()->assertJsonPath('resources.app.1.state', null)->assertJsonPath('resources.app.1.desk_known', false);
    }

    public function test_denied_area_is_not_reported_as_an_empty_desk(): void
    {
        $user = $this->connect(['dw-desk:7' => ['nome' => 'Comercial']], denied: ['table']);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.status', 'access_denied')->assertJsonPath('related.0.count', null)->assertJsonPath('related.0.complete', false)->assertJsonPath('related.0.reason', 'Acesso negado');
    }

    public function test_table_exposes_schema_source_schedule_and_dependent_application(): void
    {
        $user = $this->connect(['table:10' => ['nome' => 'Pedidos', 'originFlowId' => 20, 'columns' => ['VALOR (Decimal(18,2))' => ['label' => 'Valor do pedido', 'semantic_role' => 'measure']]], 'flow:20' => ['nome' => 'Extrair pedidos', 'deskId' => null], 'schedule:30' => ['nome' => 'Madrugada', 'flows' => [['flow_etl_id' => 20]]], 'schedule:31' => ['nome' => 'Outra agenda', 'flows' => [['flow_etl_id' => 21]]], 'app:40' => ['nome' => 'Vendas', 'deskId' => null, 'tables' => [['tableId' => 10]]]]);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertOk()->assertJsonPath('overview.columns.0.name', 'VALOR')->assertJsonPath('overview.columns.0.type', 'Decimal(18,2)')->assertJsonPath('overview.columns.0.label', 'Valor do pedido')->assertJsonPath('related.0.items.0.ref', 'flow:20')->assertJsonPath('related.1.items.0.ref', 'schedule:30')->assertJsonCount(1, 'related.1.items')->assertJsonPath('related.2.items.0.ref', 'app:40');
    }

    public function test_native_etl_steps_link_connections_without_disclosing_their_secrets(): void
    {
        $user = $this->connect(['flow:20' => ['nome' => 'Carga comercial', 'nodes' => ['Processors' => [['kind' => 'ExtractPostgreSQL', 'internalId' => 'extract', 'inputs' => [], 'options' => ['Credential' => 5, 'password' => 'secret-never-expose'], 'metadata' => ['Rows' => 14, 'ExecutedWhen' => '2026-10-29T05:00:00-03:00']], ['kind' => 'InsertDatawarehouse', 'internalId' => 'write', 'inputs' => ['extract']]], 'Connections' => [['from' => 'extract', 'to' => 'write']]]], 'credential:5' => ['nome' => 'Banco comercial', 'type' => 'PostgreSQL', 'password' => 'secret-never-expose']]);
        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('overview.nodes.0.name', 'Ler dados do PostgreSQL')->assertJsonPath('overview.nodes.0.rows', 14)->assertJsonPath('overview.nodes.1.input_count', 1)->assertJsonCount(2, 'overview.nodes')->assertJsonPath('related.2.items.0.ref', 'credential:5');
        $this->assertStringNotContainsString('secret-never-expose', $response->getContent());
    }

    public function test_native_physical_columns_are_displayed_with_their_real_names_and_types(): void
    {
        $user = $this->connect(['table:10' => ['nome' => 'Pedidos', 'columns' => [['column_name' => 'VALOR', 'data_type' => 'Decimal(18,2)', 'label' => 'Valor do pedido']]]]);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertOk()->assertJsonPath('overview.columns.0.name', 'VALOR')->assertJsonPath('overview.columns.0.type', 'Decimal(18,2)')->assertJsonPath('overview.facts.0.value', 1);
    }

    public function test_info_without_tenant_header_cannot_bypass_the_authorized_tenant_catalog(): void
    {
        $user = $this->connect(['table:10' => ['nome' => 'Unlisted resource']], unreadable: ['table:10']);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertNotFound();
    }

    public function test_summary_info_is_enriched_with_authoring_steps_from_the_authorized_workspace(): void
    {
        $user = $this->connect(['flow:20' => ['nome' => 'Carga comercial', 'nodes' => ['Processors' => [['kind' => 'PythonProcessor', 'description' => 'Calcular margem', 'internalId' => 'transform']], 'Connections' => []]]], summaries: ['flow:20' => ['id' => 20, 'nome' => 'Carga comercial', 'load_type' => 'Total']]);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('overview.nodes.0.name', 'Calcular margem')->assertJsonPath('metadata_complete', true)->assertJsonPath('details.load_type', 'Total');
    }

    public function test_native_credential_keys_resolve_to_readable_connections_without_exposing_the_key(): void
    {
        $user = $this->connect(['flow:20' => ['nome' => 'Carga', 'nodes' => ['Processors' => [['kind' => 'ExtractPostgreSQL', 'options' => ['Credential' => 'opaque-connection-reference']]]]], 'credential:5' => ['nome' => 'Banco comercial', 'key' => 'opaque-connection-reference', 'type' => 'PostgreSQL']]);
        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('related.2.items.0.ref', 'credential:5')->assertJsonPath('related.2.complete', true);
        $this->assertStringNotContainsString('opaque-connection-reference', $response->getContent());
        $this->getJson('/api/catalog/321/resources/credential/5')->assertOk()->assertJsonPath('related.0.items.0.ref', 'flow:20');
    }

    public function test_dynamic_connection_references_are_not_reported_as_a_complete_empty_list(): void
    {
        $user = $this->connect(['flow:20' => ['nome' => 'Carga', 'nodes' => ['Processors' => [['options' => ['Credential' => 'var-DATABASES_CS']]]]]]);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('related.2.complete', false)->assertJsonCount(0, 'related.2.items');
    }

    public function test_credential_metadata_without_a_native_type_uses_its_current_authoring_document(): void
    {
        $user = $this->connect(['credential:5' => ['nome' => 'Banco de teste', 'type' => 'postgresql', 'agentId' => 7, 'config' => ['hostname' => 'source.example.invalid', 'password' => 'SYNTHETIC-CATALOG-PRIVATE'], 'key' => 'SYNTHETIC-CATALOG-PRIVATE']], summaries: ['credential:5' => ['id' => 5, 'nome' => 'Banco de teste', 'kindId' => 3, 'tokenId' => 7]]);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/credential/5')->assertOk()->assertJsonPath('details.type', 'postgresql')->assertJsonPath('credential_management.can_edit', true)->assertJsonPath('metadata_complete', true);

        $this->assertStringNotContainsString('SYNTHETIC-CATALOG-PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('source.example.invalid', $response->getContent());
    }
}
