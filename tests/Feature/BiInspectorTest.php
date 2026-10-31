<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiInspectorTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/inspector-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function connect(array $report, string $kind, string $action, int $responseTenant = 321, array $metadata = []): User
    {
        $user = User::factory()->create();
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'Teste']]]]);
        $mock = Mockery::mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, $args, $project = null) use ($report, $kind, $action, $responseTenant, $metadata) {
            if ($args[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['tenantId' => $responseTenant], 'body' => ['nome' => 'Recurso autorizado']]]];
            }
            if ($args[0] === 'list') {
                return ['data' => $args[1] === 'variables' ? ['count' => 0, 'variables' => []] : ['matched' => $args[1] === $kind ? 1 : 0, 'results' => $args[1] === $kind ? [['id' => 10, 'nome' => 'Recurso autorizado']] : []]];
            }
            if ($args[0] === 'fetch') {
                $this->assertSame(['fetch', $kind.':10'], $args);

                return ['data' => []];
            }
            if ($args[0] === 'pull') {
                if ($metadata !== []) {
                    $folder = app(WorkspaceFiles::class)->path($project).'/writebacks';
                    File::ensureDirectoryExists($folder, 0700, true);
                    File::put($folder.'/cadastro--10.yaml', Yaml::dump(['kind' => 'writeback', 'id' => 10, 'tenantId' => $responseTenant, 'lumo' => 'v2'])."---\n".Yaml::dump($metadata, 15));
                }

                return ['data' => []];
            }
            $this->assertSame($kind, $args[0]);
            $this->assertSame($action, $args[1]);
            $this->assertSame($kind.':10', $args[2]);

            return ['data' => $report];
        });
        $this->app->instance(LumoClient::class, $mock);

        return $user;
    }

    public function test_table_sample_is_bounded_and_never_exposes_sql_or_password_columns(): void
    {
        $user = $this->connect(['rows' => array_fill(0, 30, ['CODIGO' => 1, 'VALOR' => 50, 'password' => 'private-secret']), 'sql' => 'private-sql'], 'table', 'preview');
        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10/inspect/data')->assertOk()->assertJsonCount(20, 'table.rows')->assertJsonPath('table.limit', 20)->assertJsonPath('table.rows.0.VALOR', 50)->assertJsonPath('table.rows.0.password', '[protegido]')->assertJsonMissingPath('sql');
        $this->assertStringNotContainsString('private-secret', $response->getContent());
        $this->assertStringNotContainsString('private-sql', $response->getContent());
    }

    public function test_flow_status_requires_finished_execution_to_confirm_success(): void
    {
        $user = $this->connect(['lista' => [['jobId' => 'queued-job', 'startedWhen' => null, 'finishedWhen' => null, 'success' => true], ['jobId' => 'running-job', 'startedWhen' => '2026-10-29T05:00:00-03:00', 'finishedWhen' => null, 'success' => true], ['jobId' => 'finished-job', 'startedWhen' => '2026-10-29T05:00:00-03:00', 'finishedWhen' => '2026-10-29T05:00:05-03:00', 'success' => true, 'rowsOut' => 14, 'duration' => 5000]]], 'flow', 'logs');
        $this->actingAs($user)->getJson('/api/catalog/321/resources/flow/10/inspect/history')->assertOk()->assertJsonPath('history.0.state', 'unknown')->assertJsonPath('history.1.state', 'running')->assertJsonPath('history.2.state', 'succeeded')->assertJsonPath('history.2.rows_out', 14)->assertJsonPath('history.2.duration_ms', 5000);
    }

    public function test_schedule_history_requires_confirmed_flow_outcomes_and_completion(): void
    {
        $finished = ['start_time' => '2026-10-29T05:00:00-03:00', 'end_time' => '2026-10-29T05:00:05-03:00', 'status' => null];
        $user = $this->connect(['lista' => [[...$finished, 'flows' => [['flow_id' => 1, 'success' => 1]]], [...$finished, 'flows' => [['success' => 1], ['success' => 0]]], [...$finished, 'status' => 'completed', 'flows' => []], [...$finished, 'end_time' => null, 'status' => 'queued', 'flows' => [['success' => 1]]]]], 'schedule', 'logs');
        $this->actingAs($user)->getJson('/api/catalog/321/resources/schedule/10/inspect/history')->assertOk()->assertJsonPath('history.0.state', 'succeeded')->assertJsonPath('history.1.state', 'failed')->assertJsonPath('history.2.state', 'unknown')->assertJsonPath('history.3.state', 'queued');
    }

    public function test_shared_agent_events_are_restricted_to_the_current_tenant(): void
    {
        $user = $this->connect(['lista' => [['tenantId' => 321, 'name' => 'Carga local', 'status' => 'MISSED', 'errorMessage' => 'password=never-expose'], ['tenantId' => 999, 'name' => 'Private other tenant', 'status' => 'ERROR'], ['name' => 'Unknown tenant event', 'status' => 'ERROR']]], 'agent', 'events');
        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/agent/10/inspect/events')->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('events.0.name', 'Carga local')->assertJsonPath('events.0.message', 'password=[protegido]')->assertJsonPath('other_tenants_omitted', true);
        $this->assertStringNotContainsString('Private other tenant', $response->getContent());
        $this->assertStringNotContainsString('never-expose', $response->getContent());
    }

    public function test_agent_health_uses_native_pulse_states(): void
    {
        $user = $this->connect(['uptimePct' => 99.5, 'current' => ['online' => true, 'activeJobs' => 2, 'queuedJobs' => 0], 'buckets' => [['ts' => 1793318400, 'state' => 'up'], ['ts' => 1793320200, 'state' => 'down'], ['ts' => 1793322000, 'state' => 'degraded']]], 'agent', 'health');
        $this->actingAs($user)->getJson('/api/catalog/321/resources/agent/10/inspect/health')->assertOk()->assertJsonPath('health.uptime_percent', 99.5)->assertJsonPath('health.timeline.0.state', 'ok')->assertJsonPath('health.timeline.1.state', 'offline')->assertJsonPath('health.timeline.2.state', 'degraded');
    }

    public function test_foreign_tenant_and_unsupported_action_are_rejected_before_inspection(): void
    {
        $user = $this->connect([], 'table', 'preview', 999);
        $this->actingAs($user)->getJson('/api/catalog/321/resources/table/10/inspect/data')->assertUnprocessable();
        $this->getJson('/api/catalog/321/resources/table/10/inspect/history')->assertUnprocessable();
        $this->getJson('/api/catalog/999/resources/table/10/inspect/data')->assertForbidden();
    }

    public function test_writeback_sample_is_bounded_redacted_and_uses_field_labels_when_available(): void
    {
        $user = $this->connect(['rows' => array_fill(0, 25, ['META' => 1200, 'password' => 'private-value']), 'sql' => 'private-query'], 'writeback', 'preview', 321, ['name' => 'Metas', 'deskId' => null, 'fields' => [['alias' => 'META', 'label' => 'Meta (R$)', 'type' => 'number']]]);

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/data')->assertOk()->assertHeaderContains('Cache-Control', 'no-store')->assertJsonCount(20, 'table.rows')->assertJsonPath('table.rows.0.password', '[protegido]')->assertJsonPath('table.column_labels.META', 'Meta (R$)')->assertJsonMissingPath('sql');
        $this->assertStringNotContainsString('private-value', $response->getContent());
        $this->assertStringNotContainsString('private-query', $response->getContent());
    }

    public function test_writeback_empty_history_is_a_valid_read_only_result(): void
    {
        $user = $this->connect([], 'writeback', 'imports');

        $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/history')->assertOk()->assertJsonCount(0, 'imports')->assertJsonPath('has_more', false);
    }

    public function test_writeback_history_differentiates_terminal_partial_and_pending_batches_without_private_urls(): void
    {
        $user = $this->connect([['id' => 1, 'status' => 'done', 'inserted_rows' => 2, 'error_rows' => 0, 'mode' => 'append', 'filename' => 'https://example.invalid/metas.xlsx?token=private-value', 'created_at' => '2026-10-31T05:00:00Z', 'download_url' => 'private-value'], ['id' => 2, 'status' => 'done', 'error_rows' => 1], ['id' => 3, 'status' => 'queued', 'success' => true], ['id' => 4, 'status' => 'rolled_back'], ['id' => 5, 'status' => 'unrecognized']], 'writeback', 'imports');

        $response = $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/history')->assertOk()->assertJsonPath('imports.0.state', 'completed')->assertJsonPath('imports.0.filename', 'metas.xlsx')->assertJsonPath('imports.0.rows_inserted', 2)->assertJsonPath('imports.1.state', 'partial')->assertJsonPath('imports.2.state', 'queued')->assertJsonPath('imports.3.state', 'reverted')->assertJsonPath('imports.4.state', 'unknown')->assertJsonMissingPath('imports.0.download_url');
        $this->assertStringNotContainsString('private-value', $response->getContent());
    }

    public function test_writeback_history_explicitly_limits_the_returned_batches(): void
    {
        $user = $this->connect(array_fill(0, 101, ['status' => 'queued']), 'writeback', 'imports');

        $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/history')->assertOk()->assertJsonCount(100, 'imports')->assertJsonPath('has_more', true);
    }

    public function test_writeback_data_with_an_explicit_native_error_is_not_reported_as_empty(): void
    {
        $user = $this->connect(['rows' => [], 'error' => 'private-query-error'], 'writeback', 'preview');

        $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/data')->assertUnprocessable()->assertJsonPath('message', 'O BI informou uma falha ao consultar os registros do cadastro. A amostra não foi confirmada.');
    }

    public function test_writeback_unrecognized_history_is_rejected_with_422(): void
    {
        $user = $this->connect(['message' => 'private-provider-error'], 'writeback', 'imports');

        $this->actingAs($user)->getJson('/api/catalog/321/resources/writeback/10/inspect/history')->assertUnprocessable()->assertJsonPath('message', 'O BI não retornou o histórico de importações em formato reconhecido.');
    }
}
