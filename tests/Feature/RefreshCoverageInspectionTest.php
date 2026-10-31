<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\BiLoadExecution;
use App\Models\Connection;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class RefreshCoverageInspectionTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/refresh-inspection-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeDocuments(Project $context, array $documents, array $skip = []): void
    {
        foreach ($documents as $reference => $body) {
            if (in_array($reference, $skip, true)) {
                continue;
            }
            [$kind, $id] = explode(':', $reference);
            $folder = ['app' => 'apps', 'table' => 'tables', 'flow' => 'flows', 'schedule' => 'schedules'][$kind];
            $path = app(WorkspaceFiles::class)->path($context).'/'.$folder;
            File::ensureDirectoryExists($path, 0700, true);
            File::put($path.'/resource-'.$id.'.yaml', Yaml::dump(['kind' => $kind, 'id' => (int) $id, 'tenantId' => 321])."---\n".Yaml::dump($body, 20));
        }
    }

    private function fixture(bool $preload = false, string $role = 'editor'): array
    {
        $this->travelTo('2026-10-31T18:00:00Z');
        $user = User::factory()->create(['role' => $role]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Conferência', 'tenant_id' => '321', 'source_ref' => 'app:10']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => (string) Str::uuid(), 'tenants' => [['id' => '321', 'name' => 'Homologação']]]]);
        $documents = ['app:10' => ['nome' => 'Painel', 'deskId' => 7, 'tables' => [['tableId' => 1]], 'dashboards' => []], 'table:1' => ['nome' => 'Pedidos', 'originFlowId' => 20, 'deskId' => 8], 'flow:20' => ['nome' => 'Carga', 'deskId' => 8, 'load_type' => 'Total', 'nodes' => ['Processors' => []]], 'schedule:100' => ['nome' => 'Madrugada', 'ativo' => true, 'flows' => [['flow_etl_id' => 20, 'context' => 'Total']], 'triggers' => [['type' => 'Day', 'start_when' => '03:00']], 'timezone' => 'America/Sao_Paulo']];
        $context = app(BiCatalog::class)->context($connection, ['id' => '321', 'name' => 'Homologação']);
        if ($preload) {
            $this->writeDocuments($context, $documents);
        }
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Carga anterior', 'payload' => [], 'fingerprint' => str_repeat('a', 64), 'status' => 'approved', 'expires_at' => now()->addHour()]);
        $load = BiLoadExecution::create(['user_id' => $user->id, 'project_id' => $project->id, 'approval_id' => $approval->id, 'connection_id' => $connection->id, 'tenant_id' => '321', 'schedule_id' => '100', 'status' => 'succeeded', 'requested_at' => now()->subMinutes(4), 'metadata' => ['proof' => ['verified' => true, 'status' => 'succeeded', 'run_id' => 'test-run'], 'bindings' => [['flow_id' => '20']], 'configuration_hash' => app(RefreshCoverage::class)->signature($documents['schedule:100'], $documents)]]);
        $state = (object) ['documents' => $documents, 'failPull' => false, 'denied' => [], 'skip' => [], 'commands' => [], 'diff' => [], 'info' => []];
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, Project $context) use ($state): array {
            $state->commands[] = $args;
            if ($args[0] === 'pull') {
                if ($state->failPull) {
                    throw new RuntimeException('SYNTHETIC-PRIVATE-REFRESH-ERROR');
                }
                $this->writeDocuments($context, $state->documents, $state->skip);

                return ['data' => []];
            }
            if ($args[0] === 'list') {
                $kind = $args[1];
                if (in_array($kind, $state->denied, true)) {
                    throw new RuntimeException('SYNTHETIC-PRIVATE-AREA-ERROR', 6);
                }
                if ($kind === 'variables') {
                    return ['data' => ['count' => 0, 'variables' => []]];
                }
                $rows = [];
                foreach ($state->documents as $reference => $body) {
                    [$type, $id] = explode(':', $reference);
                    if ($type === $kind) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome']];
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args === ['info', 'app:10', '--full']) {
                return ['data' => ['payload' => ['header' => ['tenantId' => 321, 'kind' => 'app', 'id' => 10], 'body' => $state->documents['app:10']]]];
            }
            if ($args[0] === 'diff' && ($args[2] ?? null) === '--include-remote') {
                return ['data' => ['workingVsServer' => ['equal' => array_key_exists($args[1], $state->diff) ? $state->diff[$args[1]] : true]]];
            }
            if ($args[0] === 'info' && str_starts_with($args[1], 'schedule:')) {
                $reference = $args[1];
                $id = (int) substr($reference, 9);
                $flows = array_map(fn ($binding) => ['FlowID' => $binding['flow_etl_id'], 'Success' => true, 'FinishedWhen' => now()->subMinute()->toIso8601String(), 'RowsIn' => 2, 'RowsOut' => 2], $state->documents[$reference]['flows']);
                $summary = ['id' => $id, 'nome' => $state->documents[$reference]['nome'], 'ativo' => $state->documents[$reference]['ativo'], 'last_log' => ['scheduleId' => $id, 'run_id' => $id === 100 ? 'test-run' : 'other-run', 'startedWhen' => now()->subMinutes(2)->toIso8601String(), 'finishedWhen' => now()->subMinute()->toIso8601String(), 'flows' => $flows]];

                return ['data' => ['payload' => array_replace($summary, $state->info[$reference] ?? [])]];
            }
            throw new RuntimeException('Unexpected remote operation in coverage test.');
        });

        return [$user, $project, $state, $load];
    }

    public function test_current_metadata_keeps_the_verified_proof_without_creating_a_load(): void
    {
        [$user, , , $load] = $this->fixture();

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('verified', true)->assertJsonPath('sources.0.test_execution.status', 'succeeded')->assertHeaderContains('Cache-Control', 'no-store');

        $this->assertSame(1, BiLoadExecution::count());
        $this->assertSame('succeeded', $load->fresh()->status);
        $this->assertSame(0, RefreshSchedule::count());
    }

    public function test_project_inspection_records_the_verified_result_without_repeating_the_test(): void
    {
        [$user, $project, , $load] = $this->fixture();

        $this->actingAs($user)->getJson('/api/projects/'.$project->id.'/refresh-coverage')->assertOk()->assertJsonPath('verified', true)->assertHeaderContains('Cache-Control', 'no-store');

        $record = RefreshSchedule::where('user_id', $user->id)->sole();
        $this->assertSame($project->id, $record->project_id);
        $this->assertTrue($record->metadata['test_verified']);
        $this->assertSame(1, BiLoadExecution::count());
        $this->assertSame('succeeded', $load->fresh()->status);
    }

    public function test_failed_refresh_returns_422_without_reusing_stale_files_and_a_previous_proof(): void
    {
        [$user, , $state] = $this->fixture(preload: true);
        $state->failPull = true;
        $state->documents['flow:20']['description'] = 'Changed after the old proof';

        $response = $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertUnprocessable()->assertJsonPath('errors.coverage.0', 'Não foi possível atualizar os metadados necessários para conferir as fontes e agendas. Tente Verificar atualizações novamente; os arquivos anteriores não confirmam a configuração atual.');

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', $response->getContent());
        $this->assertNotContains(['info', 'schedule:100'], $state->commands);
        $this->assertSame(1, BiLoadExecution::count());
        $this->assertSame(0, RefreshSchedule::count());
    }

    #[DataProvider('requiredAreas')]
    public function test_a_required_area_refusal_returns_422_instead_of_treating_it_as_empty(string $kind): void
    {
        [$user, , $state] = $this->fixture();
        $state->denied = [$kind];

        $response = $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertUnprocessable()->assertJsonPath('errors.coverage.0', 'Não foi possível consultar todas as tabelas, fontes e agendas com o acesso atual. Atualize os acessos em Conexões e confira as permissões no BI antes de concluir a conferência.');

        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', $response->getContent());
        $this->assertNotContains(['info', 'schedule:100'], $state->commands);
    }

    public static function requiredAreas(): array
    {
        return ['tables' => ['table'], 'sources' => ['flow'], 'schedules' => ['schedule']];
    }

    public function test_a_listed_schedule_without_authoring_returns_422_instead_of_suggesting_a_duplicate(): void
    {
        [$user, , $state] = $this->fixture();
        $state->skip = ['schedule:100'];

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertUnprocessable()->assertJsonPath('errors.coverage.0', 'Uma ou mais agendas não forneceram sua configuração para esta consulta. Confira o acesso às agendas antes de criar outra ou confirmar a atualização diária.');
    }

    public function test_a_confirmed_empty_schedule_inventory_keeps_the_source_pending(): void
    {
        [$user, , $state] = $this->fixture(preload: true);
        unset($state->documents['schedule:100']);

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('verified', false)->assertJsonPath('sources.0.status', 'schedule_required')->assertJsonPath('sources.0.recommendation', 'prepare_dedicated_schedule');
    }

    public function test_a_missing_table_document_is_reported_as_unresolved(): void
    {
        [$user, , $state] = $this->fixture();
        $state->skip = ['table:1'];

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('verified', false)->assertJsonPath('unresolved_tables.0.table_id', '1');
    }

    public function test_an_unidentified_binding_blocks_the_overall_proof_despite_a_valid_source(): void
    {
        [$user, , $state] = $this->fixture();
        $state->documents['app:10']['tables'][] = ['tableId' => true];

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('verified', false)->assertJsonPath('sources.0.configured', true)->assertJsonPath('sources.0.test_execution.verified', true)->assertJsonPath('unresolved_tables.0.table_id', null);
    }

    public function test_retrying_after_a_read_failure_recovers_without_a_new_load_or_authorization(): void
    {
        [$user, , $state] = $this->fixture(preload: true);
        $state->failPull = true;
        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertUnprocessable();
        $state->failPull = false;

        $this->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('verified', true);

        $this->assertSame(1, BiLoadExecution::count());
        $this->assertSame(1, Approval::count());
    }

    public function test_viewer_inspection_does_not_modify_project_refresh_records(): void
    {
        [$user, $project] = $this->fixture(role: 'viewer');

        $this->actingAs($user)->getJson('/api/projects/'.$project->id.'/refresh-coverage')->assertOk()->assertJsonPath('verified', true);

        $this->assertSame(0, RefreshSchedule::count());
    }

    public function test_anonymous_inspection_returns_401_before_querying_the_bi(): void
    {
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertUnauthorized();
    }

    public function test_an_agenda_changed_since_the_snapshot_cannot_reuse_its_old_test(): void
    {
        [$user, , $state] = $this->fixture();
        $state->diff['schedule:100'] = false;

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('verified', false)->assertJsonPath('sources.0.status', 'configuration_changed')->assertJsonPath('sources.0.schedules.0.configuration_current', false);

        $this->assertNotContains(['info', 'schedule:100'], $state->commands);
    }

    public function test_an_unknown_configuration_comparison_cannot_confirm_daily_refresh(): void
    {
        [$user, , $state] = $this->fixture();
        $state->diff['schedule:100'] = null;

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('verified', false)->assertJsonPath('sources.0.status', 'metadata_unavailable');
    }

    public function test_an_active_alternative_keeps_coverage_but_does_not_inherit_a_paused_agendas_proof(): void
    {
        [$user, , $state] = $this->fixture();
        $state->documents['schedule:101'] = [...$state->documents['schedule:100'], 'nome' => 'Alternativa'];
        $state->info['schedule:100'] = ['ativo' => false];

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('last_executions_successful', true)->assertJsonPath('verified', false)->assertJsonPath('sources.0.last_execution.schedule_id', '101')->assertJsonPath('sources.0.schedules.0.active', false)->assertJsonMissingPath('sources.0.test_execution');
    }

    public function test_a_successful_agenda_without_a_test_does_not_hide_a_verified_alternative(): void
    {
        [$user, , $state, $load] = $this->fixture();
        $state->documents['schedule:101'] = [...$state->documents['schedule:100'], 'nome' => 'Alternativa'];
        $load->update(['schedule_id' => '101', 'metadata' => [...$load->metadata, 'proof' => ['verified' => true, 'status' => 'succeeded', 'run_id' => 'other-run']]]);

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('verified', true)->assertJsonPath('sources.0.last_execution.schedule_id', '101')->assertJsonPath('sources.0.test_execution.run_id', 'other-run');
    }

    public function test_shared_agenda_configuration_and_execution_are_read_only_once_for_all_sources(): void
    {
        [$user, , $state, $load] = $this->fixture();
        $state->documents['app:10']['tables'][] = ['tableId' => 2];
        $state->documents['table:2'] = ['nome' => 'Itens', 'originFlowId' => 30, 'deskId' => 8];
        $state->documents['flow:30'] = [...$state->documents['flow:20'], 'nome' => 'Carga de itens'];
        $state->documents['schedule:100']['flows'][] = ['flow_etl_id' => 30, 'context' => 'Total'];
        $load->update(['metadata' => [...$load->metadata, 'bindings' => [['flow_id' => '20'], ['flow_id' => '30']], 'configuration_hash' => app(RefreshCoverage::class)->signature($state->documents['schedule:100'], $state->documents)]]);

        $this->actingAs($user)->getJson('/api/catalog/321/apps/10/refresh-coverage')->assertOk()->assertJsonPath('verified', true)->assertJsonCount(2, 'sources');

        $this->assertSame(1, count(array_filter($state->commands, fn ($args) => $args === ['diff', 'schedule:100', '--include-remote'])));
        $this->assertSame(1, count(array_filter($state->commands, fn ($args) => $args === ['info', 'schedule:100'])));
        $this->assertSame(1, BiLoadExecution::count());
    }
}
