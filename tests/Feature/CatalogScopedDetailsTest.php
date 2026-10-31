<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\BiBusy;
use App\Services\BiCatalog;
use App\Services\BiRelationCatalog;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CatalogScopedDetailsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $bodies = [];

    private array $graphs = [];

    private array $denied = [];

    private array $commands = [];

    private array $pulled = [];

    private bool $failPull = false;

    private bool $changeSession = false;

    private bool $nativeRows = false;

    private bool $busyGraph = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/scoped-catalog-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connect(array $bodies): array
    {
        $this->bodies = $bodies;
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-scoped-session']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $arguments, ?Project $context): array {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'pull') {
                $references = array_values(array_filter(array_slice($arguments, 1), fn (string $value): bool => ! str_starts_with($value, '--')));
                if ($references === []) {
                    throw new RuntimeException('Unexpected full tenant read.');
                }
                if ($this->failPull) {
                    throw new RuntimeException('Synthetic metadata refusal.');
                }
                foreach ($references as $reference) {
                    $this->pulled[] = $reference;
                    if (isset($this->bodies[$reference])) {
                        $this->write($context, $reference, $this->bodies[$reference]);
                    }
                }
                if ($this->changeSession) {
                    $connection->fresh()->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]);
                }

                return ['data' => []];
            }
            if ($arguments[0] === 'info') {
                if (in_array('--dependents', $arguments, true)) {
                    if ($this->busyGraph) {
                        throw new BiBusy;
                    }

                    return ['data' => ['dependents' => true, 'payload' => $this->graphs[$arguments[1]] ?? ['coverage' => 'partial', 'items' => []]]];
                }
                [$kind, $id] = explode(':', $arguments[1]);
                $body = ['id' => (int) $id, ...$this->bodies[$arguments[1]]];

                return ['data' => ['payload' => $kind === 'app' ? ['header' => ['tenantId' => 321, 'id' => (int) $id, 'kind' => 'app'], 'body' => $body] : $body]];
            }
            if ($arguments[0] === 'list') {
                $kind = $arguments[1];
                if (in_array($kind, $this->denied, true)) {
                    throw new RuntimeException('Synthetic area refusal.', 6);
                }
                if (in_array($kind, ['variables', 'investigation', 'deck', 'presentation'], true)) {
                    throw new RuntimeException('Unrelated catalog area must not block this resource.');
                }
                $rows = [];
                foreach ($this->bodies as $reference => $body) {
                    [$type, $id] = explode(':', $reference);
                    if ($type === $kind) {
                        if ($this->nativeRows && in_array('--desk', $arguments, true) && (string) ($body['deskId'] ?? '') !== $arguments[array_search('--desk', $arguments, true) + 1]) {
                            continue;
                        }
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome'] ?? $reference, ...($this->nativeRows && array_key_exists('deskId', $body) ? ['deskId' => $body['deskId']] : []), ...($kind === 'credential' && ! in_array('--remote', $arguments, true) ? ['extra' => ['key' => $body['key'] ?? null]] : [])];
                    }
                }

                return ['data' => ['matched' => count($rows), 'results' => $rows]];
            }
            throw new RuntimeException('Unexpected command in read-only test.');
        });

        return [$user, $connection];
    }

    private function write(Project $context, string $reference, array $body): void
    {
        [$kind, $id] = explode(':', $reference);
        $folder = ['app' => 'apps', 'table' => 'tables', 'flow' => 'flows', 'schedule' => 'schedules', 'credential' => 'credentials', 'dw-desk' => 'dw-desks', 'bi-desk' => 'bi-desks', 'writeback' => 'writebacks', 'agent' => 'agents'][$kind];
        $path = app(WorkspaceFiles::class)->path($context).'/'.$folder;
        File::ensureDirectoryExists($path, 0700, true);
        File::put($path.'/resource-'.$id.'.yaml', Yaml::dump(['kind' => $kind, 'id' => (int) $id, 'tenantId' => 321])."---\n".Yaml::dump($body, 15));
    }

    public function test_cold_application_details_keep_source_names_and_descriptions_without_reading_the_full_tenant(): void
    {
        [$user] = $this->connect(['app:40' => ['nome' => 'Visão financeira', 'deskId' => 7, 'tables' => [['tableId' => 10]], 'dashboards' => []], 'table:10' => ['nome' => 'Receitas', 'descricao' => 'Fonte conferida', 'deskId' => 8], 'bi-desk:7' => ['nome' => 'Diretoria']]);

        $this->actingAs($user)->getJson('/api/catalog/321/resources/app/40')->assertOk()->assertJsonPath('related.0.items.0.name', 'Diretoria')->assertJsonPath('related.1.items.0.name', 'Receitas')->assertJsonPath('related.1.items.0.description', 'Fonte conferida')->assertJsonPath('related.1.complete', true);

        $this->assertEqualsCanonicalizing(['table:10', 'bi-desk:7'], $this->pulled);
        $this->assertNotContains(['pull', '--discard-local'], $this->commands);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_cold_managed_draft_keeps_the_original_application_and_publication_desk_names(): void
    {
        [$user] = $this->connect(['app:40' => ['nome' => 'Versão em revisão', 'cloned_from' => 41, 'deskId' => null, 'dashboards' => []], 'app:41' => ['nome' => 'Visão compartilhada', 'deskId' => 7, 'dashboards' => []], 'bi-desk:7' => ['nome' => 'Diretoria']]);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '321', 'source_ref' => 'app:40', 'name' => 'Manutenção de vendas']);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '321', 'resource' => 'app:40', 'origin' => 'clone_app', 'source_ref' => 'app:41', 'receipt' => []]);

        $this->actingAs($user)->getJson('/api/catalog/321/resources/app/40')->assertOk()->assertJsonPath('management.can_publish', true)->assertJsonPath('management.publication_origin', ['resource' => 'app:41', 'name' => 'Visão compartilhada', 'desk_id' => 7, 'desk_name' => 'Diretoria']);

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_busy_relationship_read_returns_422_instead_of_an_empty_or_partial_desk(): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'Pedidos', 'deskId' => 7]]);
        $this->busyGraph = true;

        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertUnprocessable()->assertJsonPath('errors.catalog.0', 'Outra operação do BI está em andamento nesta conta. Aguarde a conclusão e tente novamente.');

        $this->assertSame([], $this->pulled);
    }

    public function test_table_details_preserve_schema_origin_schedules_and_reverse_application_links(): void
    {
        [$user] = $this->connect(['table:10' => ['nome' => 'Pedidos', 'originFlowId' => 20, 'columns' => ['VALOR (Decimal(18,2))' => ['label' => 'Valor total']]], 'flow:20' => ['nome' => 'Carga de pedidos'], 'schedule:30' => ['nome' => 'Madrugada', 'flows' => [['flow_etl_id' => 20]]], 'schedule:31' => ['nome' => 'Outra carga', 'flows' => [['flow_etl_id' => 21]]], 'app:40' => ['nome' => 'Vendas', 'tables' => [['tableId' => 10]]], 'app:41' => ['nome' => 'Outro assunto', 'tables' => [['tableId' => 11]]]]);

        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertOk()->assertJsonPath('overview.columns.0.label', 'Valor total')->assertJsonPath('related.0.items.0.ref', 'flow:20')->assertJsonPath('related.1.items.0.ref', 'schedule:30')->assertJsonCount(1, 'related.1.items')->assertJsonPath('related.2.items.0.ref', 'app:40')->assertJsonCount(1, 'related.2.items');

        $this->assertNotContains(['list', 'credential', '--remote', '--limit', '500', '--offset', '0'], $this->commands);
        $this->assertNotContains(['fetch', 'table:10'], $this->commands);
    }

    public function test_flow_keeps_connection_keys_private_and_all_current_links_available(): void
    {
        [$user] = $this->connect(['flow:20' => ['nome' => 'Carga de pedidos', 'nodes' => ['Processors' => [['kind' => 'ExtractPostgreSQL', 'options' => ['Credential' => 'synthetic-opaque-key', 'password' => 'synthetic-private-password']]]]], 'table:10' => ['nome' => 'Pedidos', 'originFlowId' => 20], 'schedule:30' => ['nome' => 'Madrugada', 'flows' => [['flow_etl_id' => 20]]], 'credential:5' => ['nome' => 'Banco de origem', 'key' => 'synthetic-opaque-key', 'type' => 'PostgreSQL', 'password' => 'synthetic-private-password']]);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('related.0.items.0.ref', 'table:10')->assertJsonPath('related.1.items.0.ref', 'schedule:30')->assertJsonPath('related.2.items.0.ref', 'credential:5')->assertJsonPath('related.2.complete', true);

        $this->assertStringNotContainsString('synthetic-opaque-key', $response->getContent());
        $this->assertStringNotContainsString('synthetic-private-password', $response->getContent());
        $this->assertNotContains(['list', 'app', '--remote', '--limit', '500', '--offset', '0'], $this->commands);
    }

    public function test_a_complete_native_desk_report_limits_reads_to_its_members_without_losing_their_details(): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'Pedidos', 'descricao' => 'Descrição atual', 'deskId' => 7], 'table:11' => ['nome' => 'Outra mesa', 'deskId' => 8], 'flow:20' => ['nome' => 'Atualizar pedidos', 'deskId' => 7]]);
        $this->graphs['dw-desk:7'] = ['coverage' => 'complete', 'items' => [['kind' => 'table', 'id' => 10, 'reason' => 'published to desk'], ['kind' => 'flow', 'id' => 20, 'reason' => 'published to desk']]];

        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.items.0.ref', 'table:10')->assertJsonPath('related.0.items.0.description', 'Descrição atual')->assertJsonPath('related.0.complete', true)->assertJsonPath('related.1.items.0.ref', 'flow:20');

        $this->assertEqualsCanonicalizing(['table:10', 'flow:20'], $this->pulled);
    }

    public function test_an_application_desk_keeps_dashboard_names_and_counts_in_the_faster_read(): void
    {
        [$user] = $this->connect(['bi-desk:7' => ['nome' => 'Diretoria'], 'app:40' => ['nome' => 'Receitas', 'deskId' => 7, 'dashboards' => [['header' => ['id' => 90], 'body' => ['nome' => 'Mensal', 'widgets' => [['id' => 1]]]], ['header' => ['id' => 91], 'body' => ['nome' => 'Anual', 'widgets' => []]]]]]);
        $this->graphs['bi-desk:7'] = ['coverage' => 'complete', 'items' => [['kind' => 'app', 'id' => 40, 'reason' => 'published to desk']]];

        $this->actingAs($user)->getJson('/api/catalog/321/resources/bi-desk/7')->assertOk()->assertJsonPath('related.0.items.0.dashboard_count', 2)->assertJsonPath('related.0.items.0.dashboards.0.name', 'Mensal')->assertJsonPath('related.0.items.0.dashboards.1.name', 'Anual');
    }

    #[DataProvider('unprovenDeskGraphs')]
    public function test_an_incomplete_or_invalid_desk_report_falls_back_to_current_metadata_instead_of_claiming_an_empty_desk(array $graph): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'Pedidos', 'deskId' => 7]]);
        $this->graphs['dw-desk:7'] = $graph;

        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.items.0.ref', 'table:10')->assertJsonPath('related.0.complete', true);
    }

    public static function unprovenDeskGraphs(): array
    {
        return [
            [['coverage' => 'partial', 'items' => []]],
            [['coverage' => 'complete', 'items' => [['kind' => 'table', 'id' => 99, 'reason' => 'published to desk']]]],
            [['coverage' => 'complete', 'items' => [['kind' => 'app', 'id' => 40, 'reason' => 'published to desk']]]],
            [['coverage' => 'complete', 'items' => [['kind' => 'table', 'id' => 10, 'reason' => 'unverified relation']]]],
        ];
    }

    public function test_a_denied_area_stays_unavailable_and_does_not_expose_old_member_metadata(): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'PRIVATE-UNREADABLE-TABLE', 'deskId' => 7]]);
        $this->denied = ['table'];

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.status', 'access_denied')->assertJsonPath('related.0.count', null)->assertJsonPath('related.0.complete', false);

        $this->assertStringNotContainsString('PRIVATE-UNREADABLE-TABLE', $response->getContent());
    }

    public function test_failed_refresh_does_not_reuse_old_authoring_documents_to_claim_confirmed_reverse_links(): void
    {
        [$user, $connection] = $this->connect(['table:10' => ['nome' => 'Pedidos', 'originFlowId' => 20], 'app:40' => ['nome' => 'Vendas', 'tables' => [['tableId' => 10]]]]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Homologação']);
        $this->write($context, 'app:40', $this->bodies['app:40']);
        $this->failPull = true;

        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertOk()->assertJsonPath('metadata_complete', false)->assertJsonPath('related.0.complete', false)->assertJsonCount(0, 'related.0.items');
    }

    public function test_a_changed_bi_session_discards_the_old_response_instead_of_returning_it(): void
    {
        [$user] = $this->connect(['app:40' => ['nome' => 'OLD-GRANT-CONTENT', 'tables' => [['tableId' => 10]], 'dashboards' => []], 'table:10' => ['nome' => 'Receitas']]);
        $this->changeSession = true;

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/app/40')->assertUnprocessable()->assertJsonValidationErrors('catalog');

        $this->assertStringNotContainsString('OLD-GRANT-CONTENT', $response->getContent());
    }

    public function test_a_warm_application_desk_uses_its_verified_snapshot_without_repulling_member_files(): void
    {
        [$user, $connection] = $this->connect(['bi-desk:7' => ['nome' => 'Diretoria'], 'app:40' => ['nome' => 'Receitas', 'deskId' => 7, 'dashboards' => [['nome' => 'Mensal', 'widgets' => []]]]]);
        $catalog = app(BiCatalog::class);
        $snapshot = ['metadata_fresh' => true, 'resources' => ['app' => [$catalog->summary('app', ['id' => 40], $this->bodies['app:40'])]], 'capabilities' => ['app' => ['status' => 'available', 'reason' => null]]];
        Cache::put('bi-catalog:v3:'.$connection->id.':'.$connection->metadata['session_generation'].':321', $snapshot, 90);

        $this->actingAs($user)->getJson('/api/catalog/321/resources/bi-desk/7')->assertOk()->assertJsonPath('related.0.items.0.dashboards.0.name', 'Mensal');

        $this->assertSame([], $this->pulled);
        $this->assertNotContains(['info', 'bi-desk:7', '--dependents'], $this->commands);
    }

    public function test_an_empty_confirmed_desk_never_turns_an_empty_resource_set_into_a_full_tenant_pull(): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Mesa vazia']]);
        $this->graphs['dw-desk:7'] = ['coverage' => 'complete', 'items' => []];

        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.count', 0)->assertJsonPath('related.0.complete', true)->assertJsonPath('related.1.count', 0);

        $this->assertSame([], $this->pulled);
    }

    public function test_a_conflicting_native_desk_graph_does_not_hide_the_real_members(): void
    {
        [$user] = $this->connect(['dw-desk:7' => ['nome' => 'Comercial'], 'table:10' => ['nome' => 'Pedidos', 'deskId' => 7], 'table:11' => ['nome' => 'Outra mesa', 'deskId' => 8]]);
        $this->graphs['dw-desk:7'] = ['coverage' => 'complete', 'items' => [['kind' => 'table', 'id' => 11, 'reason' => 'published to desk']]];

        $this->actingAs($user)->getJson('/api/catalog/321/resources/dw-desk/7')->assertOk()->assertJsonPath('related.0.items.0.ref', 'table:10')->assertJsonCount(1, 'related.0.items')->assertJsonPath('related.0.complete', true);
    }

    public function test_an_explicit_wrong_source_identity_is_rejected_before_loading_its_relations(): void
    {
        [$user] = $this->connect(['flow:20' => ['id' => 99, 'nome' => 'WRONG-RESOURCE-CONTENT', 'nodes' => []]]);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertUnprocessable()->assertJsonValidationErrors('catalog');

        $this->assertStringNotContainsString('WRONG-RESOURCE-CONTENT', $response->getContent());
        $this->assertSame([], $this->pulled);
    }

    public function test_a_new_credential_permission_refusal_cannot_be_hidden_by_a_warm_snapshot(): void
    {
        [$user, $connection] = $this->connect(['flow:20' => ['nome' => 'Pedidos', 'nodes' => ['Processors' => [['kind' => 'ExtractPostgreSQL', 'options' => ['Credential' => 5]]]]], 'credential:5' => ['nome' => 'CACHED-PRIVATE-CONNECTION']]);
        $this->denied = ['credential'];
        $catalog = app(BiCatalog::class);
        $snapshot = ['metadata_fresh' => true, 'resources' => ['table' => [], 'schedule' => [], 'credential' => [$catalog->summary('credential', ['id' => 5], $this->bodies['credential:5'])]], 'capabilities' => ['table' => ['status' => 'available'], 'schedule' => ['status' => 'available'], 'credential' => ['status' => 'available']]];
        Cache::put('bi-catalog:v3:'.$connection->id.':'.$connection->metadata['session_generation'].':321', $snapshot, 90);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/20')->assertOk()->assertJsonPath('related.2.status', 'access_denied')->assertJsonPath('related.2.count', null);

        $this->assertStringNotContainsString('CACHED-PRIVATE-CONNECTION', $response->getContent());
    }

    public function test_a_project_authorship_directory_cannot_be_used_as_a_disposable_relation_cache(): void
    {
        [$user, $connection] = $this->connect([]);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '321', 'name' => 'Autoria preservada']);
        $path = app(WorkspaceFiles::class)->path($project).'/authorship.yaml';
        File::put($path, 'KEEP-LOCAL-AUTHORSHIP');
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldNotReceive('run');
        $refused = false;
        try {
            app(BiRelationCatalog::class)->read(app(BiCatalog::class), $connection, $project, 'app', '40', [], null);
        } catch (RuntimeException $e) {
            $refused = str_contains($e->getMessage(), 'cache de leitura');
        }

        $this->assertTrue($refused);
        $this->assertSame('KEEP-LOCAL-AUTHORSHIP', File::get($path));
    }

    public function test_related_application_desks_keep_their_counts_without_pulling_every_application(): void
    {
        [$user] = $this->connect(['app:40' => ['nome' => 'Receitas', 'deskId' => 7, 'tables' => [], 'dashboards' => []], 'app:41' => ['nome' => 'Despesas', 'deskId' => 7], 'app:42' => ['nome' => 'Outro ambiente', 'deskId' => 8], 'bi-desk:7' => ['nome' => 'Diretoria']]);
        $this->nativeRows = true;

        $this->actingAs($user)->getJson('/api/catalog/321/resources/app/40')->assertOk()->assertJsonPath('related.0.items.0.contents.0.kind', 'app')->assertJsonPath('related.0.items.0.contents.0.count', 2)->assertJsonPath('related.0.items.0.contents.0.complete', true);

        $this->assertNotContains('app:41', $this->pulled);
        $this->assertNotContains('app:42', $this->pulled);
    }

    public function test_related_data_desks_keep_table_flow_and_writeback_counts(): void
    {
        [$user] = $this->connect(['table:10' => ['nome' => 'Pedidos', 'deskId' => 7, 'originFlowId' => 20], 'table:11' => ['nome' => 'Outra mesa', 'deskId' => 8], 'flow:20' => ['nome' => 'Carga', 'deskId' => 7], 'flow:21' => ['nome' => 'Outra carga', 'deskId' => 8], 'writeback:30' => ['nome' => 'Metas', 'deskId' => 7], 'writeback:31' => ['nome' => 'Outras metas', 'deskId' => 8], 'dw-desk:7' => ['nome' => 'Dados comerciais']]);
        $this->nativeRows = true;

        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10')->assertOk()->assertJsonPath('related.0.items.0.contents.0.count', 1)->assertJsonPath('related.0.items.0.contents.1.count', 1)->assertJsonPath('related.0.items.0.contents.2.count', 1)->assertJsonPath('related.0.items.0.contents.2.complete', true);

        $this->assertNotContains('writeback:31', $this->pulled);
    }
}
