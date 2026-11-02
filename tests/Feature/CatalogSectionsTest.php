<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CatalogSectionsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $bodies = [];

    private array $nativeRows = [];

    private array $commands = [];

    private array $denied = [];

    private array $variables = [];

    private array $agentInfo = [];

    private bool $failPull = false;

    private bool $skipPull = false;

    private bool $changeSession = false;

    private bool $allowFullPull = false;

    private mixed $nativeStatus = false;

    private bool $failInitialize = false;

    private string $headerTenant = '321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/catalog-sections-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connectSection(array $bodies = [], string $role = 'admin'): array
    {
        $this->bodies = $bodies;
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-section-session']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Ambiente sintético']]]]);
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnUsing(function (): void {
            if ($this->failInitialize) {
                throw new RuntimeException('Sessão indisponível neste ensaio.');
            }
        });
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $arguments, ?Project $context): array {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'list') {
                $kind = $arguments[1];
                if (isset($this->denied[$kind])) {
                    throw new RuntimeException('Consulta recusada nesta categoria.', $this->denied[$kind]);
                }
                if ($kind === 'variables') {
                    return ['data' => ['count' => count($this->variables), 'variables' => $this->variables]];
                }
                $rows = $this->nativeRows[$kind] ?? [];
                if (! array_key_exists($kind, $this->nativeRows)) {
                    foreach ($this->bodies as $reference => $body) {
                        [$type, $id] = explode(':', $reference);
                        if ($type === $kind) {
                            $rows[] = ['id' => (int) $id, 'nome' => $body['nome'] ?? $reference];
                        }
                    }
                }

                return ['data' => ['matched' => count($rows), 'results' => array_slice($rows, (int) $arguments[array_search('--offset', $arguments, true) + 1], (int) $arguments[array_search('--limit', $arguments, true) + 1])]];
            }
            if ($arguments[0] === 'status') {
                return ['data' => $this->nativeStatus !== false ? $this->nativeStatus : ['tenantId' => 321, 'clean' => $this->skipPull ? [] : array_map(function (string $reference): string {
                    [$kind, $id] = explode(':', $reference);
                    $folders = ['app' => 'apps', 'bi-desk' => 'bi-desks', 'dw-desk' => 'dw-desks', 'table' => 'tables', 'flow' => 'flows', 'writeback' => 'writebacks', 'credential' => 'credentials'];

                    return $reference.'  ('.($folders[$kind] ?? $kind.'s').'/recurso--'.$id.'.yaml)';
                }, array_keys($this->bodies))]];
            }
            if ($arguments[0] === 'info') {
                if (! array_key_exists($arguments[1], $this->agentInfo)) {
                    throw new RuntimeException('Detalhes do agente não disponíveis para esta sessão.', 6);
                }

                return ['data' => $this->agentInfo[$arguments[1]]];
            }
            if ($arguments[0] !== 'pull') {
                throw new RuntimeException('Comando inesperado na consulta de catálogo.');
            }
            $references = array_values(array_filter(array_slice($arguments, 1), fn (string $value): bool => ! str_starts_with($value, '--')));
            if ($references === []) {
                if (! $this->allowFullPull) {
                    throw new RuntimeException('A categoria não deve reconstruir todos os metadados.');
                }
                $references = array_keys($this->bodies);
            }
            if ($this->failPull) {
                throw new RuntimeException('Metadados temporariamente indisponíveis.', 7);
            }
            if (! $this->skipPull) {
                foreach ($references as $reference) {
                    if (isset($this->bodies[$reference])) {
                        $this->write($context, $reference, $this->bodies[$reference], $this->headerTenant);
                    }
                }
            }
            if ($this->changeSession) {
                $connection->fresh()->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]);
            }

            return ['data' => 'Resumo textual nativo da sincronização.'];
        });

        return [$user, $connection];
    }

    private function write(Project $context, string $reference, array $body, string $tenant = '321', string $filename = 'recurso'): string
    {
        [$kind, $id] = explode(':', $reference);
        $folders = ['app' => 'apps', 'bi-desk' => 'bi-desks', 'dw-desk' => 'dw-desks', 'table' => 'tables', 'flow' => 'flows', 'writeback' => 'writebacks', 'credential' => 'credentials'];
        $folder = app(WorkspaceFiles::class)->path($context).'/'.($folders[$kind] ?? $kind.'s');
        File::ensureDirectoryExists($folder, 0700, true);
        $path = $folder.'/'.$filename.'--'.$id.'.yaml';
        File::put($path, Yaml::dump(['lumo' => 'v2', 'kind' => $kind, 'id' => (int) $id, 'tenantId' => (int) $tenant])."---\n".Yaml::dump($body, 14));

        return $path;
    }

    private function kindsRead(): array
    {
        return array_values(array_map(fn (array $arguments): string => $arguments[1], array_filter($this->commands, fn (array $arguments): bool => $arguments[0] === 'list')));
    }

    private function appBody(string $name = 'Painel financeiro'): array
    {
        return ['nome' => $name, 'deskId' => 7, 'tags' => ['financeiro'], 'dashboards' => [['header' => ['id' => 400], 'body' => ['nome' => 'Receita', 'widgets' => []]]]];
    }

    public function test_guest_cannot_read_a_catalog_section_and_receives_401(): void
    {
        $this->getJson('/api/catalog/321/sections/app')->assertUnauthorized();
    }

    public function test_unknown_tenant_is_rejected_with_403_before_cli_access(): void
    {
        [$user] = $this->connectSection();

        $this->actingAs($user)->getJson('/api/catalog/999/sections/app')->assertForbidden();

        $this->assertSame([], $this->commands);
    }

    public function test_another_users_connection_is_not_used_and_returns_422(): void
    {
        $this->connectSection();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/api/catalog/321/sections/app')->assertUnprocessable();

        $this->assertSame([], $this->commands);
    }

    public function test_unknown_kind_returns_404_without_cli_access(): void
    {
        [$user] = $this->connectSection();

        $this->actingAs($user)->getJson('/api/catalog/321/sections/unsupported')->assertNotFound();

        $this->assertSame([], $this->commands);
    }

    public static function sections(): array
    {
        return [
            'apps' => ['app', ['app', 'bi-desk']],
            'application desks' => ['bi-desk', ['bi-desk', 'app']],
            'data desks' => ['dw-desk', ['dw-desk', 'table', 'flow', 'writeback']],
            'tables' => ['table', ['table', 'dw-desk']],
            'flows' => ['flow', ['flow', 'dw-desk']],
            'writebacks' => ['writeback', ['writeback', 'dw-desk']],
            'schedules' => ['schedule', ['schedule']],
            'agents' => ['agent', ['agent']],
            'decks' => ['deck', ['deck']],
            'presentations' => ['presentation', ['presentation']],
            'investigations' => ['investigation', ['investigation']],
            'credentials' => ['credential', ['credential']],
            'variables' => ['variables', ['variables']],
        ];
    }

    #[DataProvider('sections')]
    public function test_a_section_queries_only_its_inventory_and_required_desk_members(string $kind, array $expected): void
    {
        [$user] = $this->connectSection();

        $response = $this->actingAs($user)->getJson('/api/catalog/321/sections/'.$kind);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('section', $kind)->assertJsonPath('capabilities.'.$kind.'.status', 'available')->assertJsonPath('capabilities.'.$kind.'.count', 0);
        $this->assertSame($expected, $this->kindsRead());
        $this->assertSame($expected, array_keys($response->json('resources')));
    }

    public static function readerRoles(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor'], 'viewer' => ['viewer']];
    }

    #[DataProvider('readerRoles')]
    public function test_portal_readers_can_view_the_authorized_section_without_writes(string $role): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody(), 'bi-desk:7' => ['nome' => 'Financeiro']], $role);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/sections/app');

        $response->assertOk()->assertJsonPath('resources.app.0.name', 'Painel financeiro')->assertJsonPath('resources.app.0.state', 'published')->assertJsonPath('resources.app.0.dashboards.0.name', 'Receita')->assertJsonPath('resources.bi-desk.0.contents.0.count', 1)->assertJsonPath('resources.bi-desk.0.contents.0.complete', true)->assertJsonPath('metadata_fresh', true)->assertJsonMissingPath('resources.flow');
        $this->assertSame(['app', 'bi-desk'], $this->kindsRead());
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_data_desk_counters_preserve_all_three_member_types(): void
    {
        [$user] = $this->connectSection(['dw-desk:10' => ['nome' => 'Dados'], 'table:20' => ['nome' => 'Receitas', 'deskId' => 10], 'flow:30' => ['nome' => 'Carga', 'deskId' => 10], 'writeback:40' => ['nome' => 'Metas', 'deskId' => 11]]);

        $this->actingAs($user)->getJson('/api/catalog/321/sections/dw-desk')->assertOk()->assertJsonPath('resources.dw-desk.0.contents', [['kind' => 'table', 'count' => 1, 'complete' => true], ['kind' => 'flow', 'count' => 1, 'complete' => true], ['kind' => 'writeback', 'count' => 0, 'complete' => true]]);
    }

    public function test_an_unavailable_member_list_keeps_its_counter_unknown(): void
    {
        [$user] = $this->connectSection(['dw-desk:10' => ['nome' => 'Dados']]);
        $this->denied['table'] = 6;

        $this->actingAs($user)->getJson('/api/catalog/321/sections/dw-desk')->assertOk()->assertJsonPath('capabilities.table.status', 'access_denied')->assertJsonPath('capabilities.table.count', null)->assertJsonPath('resources.dw-desk.0.contents.0.count', null)->assertJsonPath('resources.dw-desk.0.contents.0.complete', false);
    }

    public function test_metadata_failure_does_not_reuse_stale_yaml_or_touch_project_authorship(): void
    {
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Nome atual')]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $stale = $this->write($context, 'app:40', $this->appBody('Nome obsoleto'), filename: 'obsoleto');
        $authorship = new Project;
        $authorship->forceFill(['id' => 'autoria-preservada', 'user_id' => $user->id, 'tenant_id' => '321']);
        $authorshipPath = $this->write($authorship, 'app:40', $this->appBody('Autoria local'));
        $authorshipHash = hash_file('sha256', $authorshipPath);
        $this->failPull = true;

        $response = $this->actingAs($user)->getJson('/api/catalog/321/sections/app');

        $response->assertOk()->assertJsonPath('resources.app.0.name', 'Nome atual')->assertJsonPath('resources.app.0.dashboard_count', null)->assertJsonPath('metadata_fresh', false)->assertDontSee('Nome obsoleto');
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertFileDoesNotExist($stale);
        $this->assertSame($authorshipHash, hash_file('sha256', $authorshipPath));
    }

    public function test_a_successful_pull_that_skips_an_out_of_scope_document_cannot_reuse_its_old_metadata(): void
    {
        [$user, $connection] = $this->connectSection(['app:40' => ['nome' => 'Aplicação legível']]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $this->write($context, 'app:40', $this->appBody('Metadados anteriores'));
        $this->skipPull = true;

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Aplicação legível')->assertJsonPath('resources.app.0.dashboard_count', null)->assertJsonPath('metadata_fresh', false)->assertDontSee('Metadados anteriores');
    }

    public function test_foreign_tenant_metadata_is_not_used_for_an_authorized_row(): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody('Outro tenant')]);
        $this->nativeRows['app'] = [['id' => 40, 'nome' => 'Nome autorizado']];
        $this->headerTenant = '999';

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Nome autorizado')->assertJsonPath('resources.app.0.dashboard_count', null)->assertJsonPath('metadata_fresh', false)->assertDontSee('Outro tenant');
    }

    public function test_malformed_native_identifiers_mark_the_category_unavailable_without_a_pull(): void
    {
        [$user] = $this->connectSection();
        $this->nativeRows['app'] = [['id' => '--invalid', 'nome' => 'Não confirmado']];

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('capabilities.app.status', 'unavailable')->assertJsonPath('capabilities.app.count', null)->assertJsonPath('resources.app', []);

        $this->assertSame(['list', 'list'], array_column($this->commands, 0));
    }

    public function test_variable_values_never_enter_the_response_or_a_pull(): void
    {
        [$user] = $this->connectSection();
        $this->variables = ['FONTE' => 'synthetic-private-variable'];

        $this->actingAs($user)->getJson('/api/catalog/321/sections/variables')->assertOk()->assertJsonPath('resources.variables.0.name', 'FONTE')->assertJsonPath('resources.variables.0.description', 'Valor protegido. Referência: {{FONTE}}')->assertDontSee('synthetic-private-variable')->assertJsonMissingPath('resources.variables.0.value');

        $this->assertSame(['list'], array_column($this->commands, 0));
    }

    public function test_cached_sections_are_reused_for_90_seconds_and_then_refreshed(): void
    {
        $this->freezeTime();
        [$user] = $this->connectSection(['app:40' => $this->appBody('Primeiro nome')]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Primeiro nome');
        $this->bodies['app:40']['nome'] = 'Nome atualizado';
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Primeiro nome');
        $this->assertSame(['app', 'bi-desk'], $this->kindsRead());

        $this->travel(91)->seconds();
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Nome atualizado');

        $this->assertSame(['app', 'bi-desk', 'app', 'bi-desk'], $this->kindsRead());
    }

    public function test_sections_do_not_share_cached_metadata_between_users(): void
    {
        [$first] = $this->connectSection(['app:40' => $this->appBody('Conta A')]);
        $this->actingAs($first)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Conta A');
        [$second] = $this->connectSection(['app:40' => $this->appBody('Conta B')]);

        $this->actingAs($second)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Conta B');
        $this->actingAs($first)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Conta A');
    }

    public function test_reconnection_uses_a_new_section_cache_and_workspace(): void
    {
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Antes da reconexão')]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]);
        $this->bodies['app:40']['nome'] = 'Após a reconexão';

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Após a reconexão');

        $this->assertSame(['app', 'bi-desk', 'app', 'bi-desk'], $this->kindsRead());
    }

    public function test_changed_session_during_the_read_returns_422_instead_of_publishing_the_result(): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody()]);
        $this->changeSession = true;

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertUnprocessable()->assertJsonValidationErrors('catalog')->assertJsonPath('errors.catalog.0', 'A sessão ou o acesso ao G-Analise mudou durante a consulta. Atualize seus acessos antes de continuar.');
    }

    public function test_refreshing_one_section_invalidates_cached_sections_of_the_same_tenant(): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody('Antes'), 'credential:8' => ['nome' => 'Origem']]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk();
        $this->actingAs($user)->getJson('/api/catalog/321/sections/credential')->assertOk();
        $this->bodies['app:40']['nome'] = 'Depois';
        $this->actingAs($user)->getJson('/api/catalog/321/sections/credential?refresh=1')->assertOk();

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Depois');
    }

    public function test_a_failed_full_refresh_invalidates_older_sections_before_the_next_read(): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody('Antes')]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk();
        $this->bodies['app:40']['nome'] = 'Depois';
        $this->failInitialize = true;
        $this->actingAs($user)->getJson('/api/catalog/321?refresh=1')->assertUnprocessable();
        $this->failInitialize = false;

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Depois');
    }

    public function test_a_disconnected_account_cannot_read_its_cached_section_and_returns_422(): void
    {
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody()]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk();
        $connection->update(['status' => 'disconnected']);
        $before = count($this->commands);

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertUnprocessable();

        $this->assertSame($before, count($this->commands));
    }

    public function test_application_desks_reuse_the_queried_app_group_without_extending_its_cache_lease(): void
    {
        $this->freezeTime();
        [$user] = $this->connectSection(['app:40' => $this->appBody('Primeira leitura'), 'bi-desk:7' => ['nome' => 'Financeiro']]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk();
        $before = count($this->commands);
        $this->travel(80)->seconds();

        $this->actingAs($user)->getJson('/api/catalog/321/sections/bi-desk')->assertOk()->assertJsonPath('section', 'bi-desk')->assertJsonPath('resources.bi-desk.0.contents.0.count', 1);

        $this->assertSame($before, count($this->commands));
        $this->bodies['app:40']['nome'] = 'Nova leitura';
        $this->travel(11)->seconds();
        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Nova leitura');
        $this->assertSame(['app', 'bi-desk', 'app', 'bi-desk'], $this->kindsRead());
    }

    public function test_a_table_group_cannot_stand_in_for_data_desks_but_a_complete_desk_group_can_serve_flows(): void
    {
        [$user] = $this->connectSection(['dw-desk:10' => ['nome' => 'Dados'], 'table:20' => ['nome' => 'Receitas', 'deskId' => 10], 'flow:30' => ['nome' => 'Carga', 'deskId' => 10], 'writeback:40' => ['nome' => 'Metas', 'deskId' => 10]]);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/table')->assertOk();
        $this->assertSame(['table', 'dw-desk'], $this->kindsRead());

        $this->actingAs($user)->getJson('/api/catalog/321/sections/dw-desk')->assertOk()->assertJsonPath('resources.dw-desk.0.contents.1.count', 1)->assertJsonPath('resources.dw-desk.0.contents.2.count', 1);

        $this->assertSame(['table', 'dw-desk', 'dw-desk', 'table', 'flow', 'writeback'], $this->kindsRead());
        $before = count($this->commands);
        $this->actingAs($user)->getJson('/api/catalog/321/sections/flow')->assertOk()->assertJsonPath('resources.flow.0.name', 'Carga');
        $this->assertSame($before, count($this->commands));
    }

    public static function agentPresentations(): array
    {
        $body = ['id' => 9, 'nome' => 'Agente consultado', 'ativo' => true, 'online' => true, 'token' => 'synthetic-private-agent-token'];

        return [
            'listed identity without header' => [['payload' => $body], true],
            'matching complete header' => [['payload' => ['header' => ['id' => 9, 'kind' => 'agent', 'tenantId' => 321], 'body' => $body]], true],
            'foreign header' => [['payload' => ['header' => ['id' => 9, 'kind' => 'agent', 'tenantId' => 999], 'body' => $body]], false],
            'wrong header kind' => [['payload' => ['header' => ['id' => 9, 'kind' => 'app', 'tenantId' => 321], 'body' => $body]], false],
            'wrong header id' => [['payload' => ['header' => ['id' => 10, 'kind' => 'agent', 'tenantId' => 321], 'body' => $body]], false],
            'wrong body id' => [['payload' => [...$body, 'id' => 10]], false],
            'missing body identity' => [['payload' => ['nome' => 'Agente consultado', 'ativo' => true]], false],
            'unexpected textual payload' => ['Resumo sem identidade.', false],
            'malformed header' => [['payload' => ['header' => 'inválido', 'body' => $body]], false],
            'unexpected body' => [['payload' => ['body' => 'inválido']], false],
        ];
    }

    #[DataProvider('agentPresentations')]
    public function test_the_full_catalog_completes_only_the_authorized_presentation_of_a_listed_agent(mixed $data, bool $accepted): void
    {
        [$user] = $this->connectSection(['agent:9' => ['nome' => 'Nome da listagem', 'ativo' => false]]);
        $this->headerTenant = '999';
        $this->allowFullPull = true;
        $this->agentInfo['agent:9'] = $data;

        $response = $this->actingAs($user)->getJson('/api/catalog/321');

        $response->assertOk()->assertJsonPath('resources.agent.0.name', $accepted ? 'Agente consultado' : 'Nome da listagem')->assertJsonPath('resources.agent.0.active', $accepted ? true : null)->assertJsonPath('resources.agent.0.metadata_available', $accepted)->assertJsonPath('resources.agent.0.online', null)->assertDontSee('synthetic-private-agent-token');
    }

    public function test_an_agent_section_can_use_the_same_verified_presentation_when_its_working_header_is_foreign(): void
    {
        [$user] = $this->connectSection(['agent:9' => ['nome' => 'Nome da listagem', 'ativo' => false]]);
        $this->headerTenant = '999';
        $this->agentInfo['agent:9'] = ['payload' => ['id' => 9, 'nome' => 'Agente consultado', 'ativo' => true]];

        $this->actingAs($user)->getJson('/api/catalog/321/sections/agent')->assertOk()->assertJsonPath('resources.agent.0.name', 'Agente consultado')->assertJsonPath('resources.agent.0.active', true)->assertJsonPath('metadata_fresh', true);
    }

    public function test_an_inactive_agent_remains_false_after_its_presentation_is_completed(): void
    {
        [$user] = $this->connectSection(['agent:9' => ['nome' => 'Agente legível']]);
        $this->headerTenant = '999';
        $this->agentInfo['agent:9'] = ['payload' => ['id' => 9, 'ativo' => false]];

        $this->actingAs($user)->getJson('/api/catalog/321/sections/agent')->assertOk()->assertJsonPath('resources.agent.0.name', 'Agente legível')->assertJsonPath('resources.agent.0.active', false)->assertJsonPath('metadata_fresh', true);
    }

    public function test_refused_agent_details_keep_its_authorized_row_without_inventing_configuration(): void
    {
        [$user] = $this->connectSection(['agent:9' => ['nome' => 'Agente legível']]);
        $this->skipPull = true;

        $this->actingAs($user)->getJson('/api/catalog/321/sections/agent')->assertOk()->assertJsonPath('resources.agent.0.name', 'Agente legível')->assertJsonPath('resources.agent.0.active', null)->assertJsonPath('resources.agent.0.metadata_available', false)->assertJsonPath('metadata_fresh', false);
    }

    public function test_a_warm_full_catalog_can_serve_a_section_without_more_cli_requests(): void
    {
        [$user] = $this->connectSection(['app:40' => $this->appBody()]);
        $this->allowFullPull = true;
        $this->actingAs($user)->getJson('/api/catalog/321')->assertOk();
        $before = count($this->commands);

        $this->actingAs($user)->getJson('/api/catalog/321/sections/app')->assertOk()->assertJsonPath('resources.app.0.name', 'Painel financeiro');

        $this->assertSame($before, count($this->commands));
    }

    public function test_full_refresh_reads_each_category_and_rebuilds_metadata_once_without_exposing_unlisted_resources(): void
    {
        $this->allowFullPull = true;
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody(), 'bi-desk:7' => ['nome' => 'Financeiro'], 'dw-desk:10' => ['nome' => 'Dados'], 'table:20' => ['nome' => 'Receitas', 'deskId' => 10], 'flow:30' => ['nome' => 'Carga', 'deskId' => 10], 'credential:8' => ['nome' => 'Conexão sem acesso']]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $this->write($context, 'app:99', $this->appBody('Aplicação removida da lista'));
        $this->write($context, 'credential:8', ['nome' => 'Metadados da conexão antiga']);
        $this->denied['credential'] = 6;
        $this->variables = ['FONTE' => 'synthetic-private-catalog-variable'];

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('metadata_fresh', true)->assertJsonPath('resources.app.0.dashboards.0.name', 'Receita')->assertJsonCount(1, 'resources.app')->assertJsonPath('resources.dw-desk.0.contents.0.count', 1)->assertJsonPath('resources.dw-desk.0.contents.1.count', 1)->assertJsonPath('capabilities.credential.status', 'access_denied')->assertJsonPath('capabilities.credential.count', null)->assertJsonPath('resources.credential', [])->assertDontSee('Aplicação removida da lista')->assertDontSee('Metadados da conexão antiga')->assertDontSee('synthetic-private-catalog-variable');
        $this->assertSame(['app', 'bi-desk', 'dw-desk', 'table', 'flow', 'schedule', 'agent', 'writeback', 'deck', 'presentation', 'investigation', 'credential', 'variables'], $this->kindsRead());
        $pulls = array_values(array_filter($this->commands, fn (array $arguments): bool => $arguments[0] === 'pull'));
        $this->assertSame([['pull', '--discard-local']], $pulls);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_full_refresh_keeps_remote_rows_but_marks_missing_metadata_instead_of_reusing_old_details(): void
    {
        $this->allowFullPull = true;
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Nome remoto atual')]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $this->write($context, 'app:40', $this->appBody('Detalhes desatualizados'));
        $this->failPull = true;

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('resources.app.0.name', 'Nome remoto atual')->assertJsonPath('resources.app.0.dashboard_count', null)->assertJsonPath('metadata_fresh', false)->assertJsonPath('capabilities.app.count', 1)->assertDontSee('Detalhes desatualizados');
        $this->assertNotEmpty($response->json('warnings'));
    }

    public static function invalidFullCatalogIdentifiers(): array
    {
        return ['option-like text' => ['--invalid'], 'negative number' => [-1], 'boolean' => [true], 'array' => [[40]]];
    }

    #[DataProvider('invalidFullCatalogIdentifiers')]
    public function test_full_refresh_marks_malformed_native_identifiers_unavailable_without_passing_them_to_commands(mixed $identifier): void
    {
        $this->allowFullPull = true;
        [$user] = $this->connectSection();
        $this->nativeRows['app'] = [['id' => $identifier, 'nome' => 'Recurso não confirmado']];

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('resources.app', [])->assertJsonPath('capabilities.app.status', 'unavailable')->assertJsonPath('capabilities.app.count', null)->assertDontSee('Recurso não confirmado');
        $this->assertSame([], array_values(array_filter($this->commands, fn (array $arguments): bool => in_array($identifier, $arguments, true))));
    }

    public function test_full_refresh_distinguishes_skipped_details_from_a_failed_synchronization(): void
    {
        $this->allowFullPull = true;
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Nome autorizado')]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $retained = $this->write($context, 'app:40', $this->appBody('Detalhes anteriores'));
        $retainedHash = hash_file('sha256', $retained);
        $this->skipPull = true;

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('metadata_fresh', true)->assertJsonPath('resources.app.0.name', 'Nome autorizado')->assertJsonPath('resources.app.0.dashboard_count', null)->assertJsonPath('resources.app.0.metadata_available', false)->assertDontSee('Detalhes anteriores');
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertSame($retainedHash, hash_file('sha256', $retained));
    }

    public function test_large_full_catalogs_keep_all_pages_after_the_metadata_refresh(): void
    {
        $this->allowFullPull = true;
        $bodies = [];
        foreach (range(1, 501) as $id) {
            $bodies['app:'.$id] = ['nome' => 'Painel '.$id];
        }
        [$user] = $this->connectSection($bodies);

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonCount(501, 'resources.app')->assertJsonPath('capabilities.app.count', 501)->assertJsonPath('resources.app.0.id', '1')->assertJsonPath('resources.app.500.id', '501')->assertJsonPath('metadata_fresh', true);
        $pulls = array_values(array_filter($this->commands, fn (array $arguments): bool => $arguments[0] === 'pull'));
        $this->assertSame([['pull', '--discard-local']], $pulls);
        $this->assertSame(['app', 'app', 'bi-desk', 'dw-desk', 'table', 'flow', 'schedule', 'agent', 'writeback', 'deck', 'presentation', 'investigation', 'credential', 'variables'], $this->kindsRead());
    }

    public static function invalidSnapshotStatuses(): array
    {
        return ['text' => ['invalid'], 'missing clean list' => [['tenantId' => 321]], 'foreign tenant' => [['tenantId' => 999, 'clean' => ['app:40']]], 'unexpected item' => [['tenantId' => 321, 'clean' => [['ref' => 'app:40']]]], 'keyed list' => [['tenantId' => 321, 'clean' => ['item' => 'app:40']]], 'traversal' => [['tenantId' => 321, 'clean' => ['app:40  (apps/../outside.yaml)']]], 'wrong folder' => [['tenantId' => 321, 'clean' => ['app:40  (credentials/private.yaml)']]], 'absolute path' => [['tenantId' => 321, 'clean' => ['app:40  (/tmp/private.yaml)']]], 'duplicate identity' => [['tenantId' => 321, 'clean' => ['app:40  (apps/recurso--40.yaml)', 'app:40  (apps/recurso--40.yaml)']]]];
    }

    #[DataProvider('invalidSnapshotStatuses')]
    public function test_full_refresh_keeps_details_unknown_when_the_native_status_cannot_be_verified(mixed $status): void
    {
        $this->allowFullPull = true;
        [$user] = $this->connectSection(['app:40' => [...$this->appBody('Nome autorizado'), 'descricao' => 'SYNTHETIC-UNVERIFIED-DOCUMENT']]);
        $this->nativeStatus = $status;

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('metadata_fresh', false)->assertJsonPath('resources.app.0.name', 'Nome autorizado')->assertJsonPath('resources.app.0.metadata_available', false)->assertJsonPath('resources.app.0.dashboard_count', null)->assertDontSee('SYNTHETIC-UNVERIFIED-DOCUMENT');
        $this->assertNotEmpty($response->json('warnings'));
    }

    public function test_only_the_exact_clean_file_is_used_when_old_files_share_its_resource_identity(): void
    {
        $this->allowFullPull = true;
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Nome atual')]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $retained = $this->write($context, 'app:40', $this->appBody('Arquivo antigo com mesmo ID'), filename: 'zz-antigo');
        $hash = hash_file('sha256', $retained);

        $response = $this->actingAs($user)->getJson('/api/catalog/321?refresh=1');

        $response->assertOk()->assertJsonPath('metadata_fresh', true)->assertJsonPath('resources.app.0.name', 'Nome atual')->assertJsonPath('resources.app.0.dashboard_count', 1)->assertDontSee('Arquivo antigo com mesmo ID');
        $this->assertSame($hash, hash_file('sha256', $retained));
    }

    public function test_a_symbolic_link_cannot_confirm_a_catalog_document(): void
    {
        $this->allowFullPull = true;
        [$user, $connection] = $this->connectSection(['app:40' => $this->appBody('Nome autorizado')]);
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Ambiente sintético']);
        $target = $this->write($context, 'app:40', [...$this->appBody('Outro documento'), 'descricao' => 'SYNTHETIC-LINKED-DOCUMENT'], filename: 'referencia');
        symlink($target, dirname($target).'/alias.yaml');
        $this->nativeStatus = ['tenantId' => 321, 'clean' => ['app:40  (apps/alias.yaml)']];

        $this->actingAs($user)->getJson('/api/catalog/321?refresh=1')->assertOk()->assertJsonPath('metadata_fresh', false)->assertJsonPath('resources.app.0.metadata_available', false)->assertDontSee('SYNTHETIC-LINKED-DOCUMENT');
    }

    public function test_the_native_null_clean_list_is_a_valid_empty_snapshot(): void
    {
        $this->allowFullPull = true;
        [$user] = $this->connectSection();
        $this->nativeStatus = ['tenantId' => 321, 'clean' => null];

        $this->actingAs($user)->getJson('/api/catalog/321?refresh=1')->assertOk()->assertJsonPath('metadata_fresh', true)->assertJsonPath('resources.app', [])->assertJsonPath('warnings', []);
    }
}
