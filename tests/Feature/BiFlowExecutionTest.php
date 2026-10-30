<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiCatalog;
use App\Services\BiCredentials;
use App\Services\BiViewer;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BiFlowExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Mapa de demonstração', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['session_generation' => 'original', 'tenants' => [['id' => '100', 'name' => 'Demonstração']]]]);

        return [$user, $project, $run];
    }

    private function flow(): array
    {
        return ['nome' => 'Visitas', 'deskId' => null, 'tokenId' => 55, 'load_type' => 'Total', 'nodes' => ['Processors' => [['internalId' => 'source', 'complete_id' => 'ExtractPostgreSQL:alias|source', 'kind' => 'ExtractPostgreSQL', 'inputs' => [], 'options' => ['Credential' => 'source-alias', 'SQL' => 'SELECT latitude, longitude, responsavel FROM visitas LIMIT 50'], 'metadata' => ['File' => '/private/source.parquet', 'Fields' => [['columnName' => 'latitude']]]], ['internalId' => 'dw', 'kind' => 'InsertDatawarehouse', 'inputs' => ['ExtractPostgreSQL:alias|source'], 'options' => ['TableID' => 500]]]]];
    }

    private function catalog(array &$body): void
    {
        $catalog = $this->partialMock(BiCatalog::class);
        $catalog->shouldReceive('detail')->withArgs(fn ($user, $tenant, $kind, $id, $relations) => $tenant === '100' && $kind === 'flow' && $id === '30' && $relations === false)->andReturn(['metadata_complete' => true, 'resource' => ['id' => '30'], 'tenant' => ['id' => '100']]);
        $catalog->shouldReceive('workingMetadata')->andReturnUsing(function () use (&$body): array {
            return ['flow:30' => $body];
        });
    }

    private function cli(bool $equal = true): MockInterface
    {
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldReceive('initialize')->andReturnNull();
        $lumo->shouldReceive('run')->withArgs(fn ($c, $args) => $args === ['diff', 'flow:30', '--include-remote'])->andReturn(['data' => ['workingVsServer' => ['equal' => $equal]]]);

        return $lumo;
    }

    public function test_etl_execution_requires_a_review_of_the_node_and_all_upstream_steps(): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $lumo = $this->cli();
        $lumo->shouldNotReceive('run')->withArgs(fn ($c, $args) => ($args[1] ?? '') === 'run-node');

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'dw']);

        $review = Approval::findOrFail($result['approval_id']);
        $this->assertSame('awaiting_approval', $result['status']);
        $this->assertSame('pending', $review->status);
        $this->assertSame('dw', $review->payload['revision']['etl']['node']);
        $this->assertTrue($review->payload['revision']['etl']['writes_datawarehouse']);
        $this->assertSame(['source', 'dw'], array_column($review->payload['revision']['etl']['steps'], 'id'));
        $this->assertSame(500, $review->payload['revision']['etl']['steps'][1]['table_id']);
        $this->assertStringContainsString('FROM visitas', $review->payload['revision']['etl']['steps'][0]['sql']);
    }

    public function test_approved_node_waits_for_completion_and_resumes_the_same_conversation_once(): void
    {
        $this->freezeTime();
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $lumo = $this->cli();
        $lumo->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['flow', 'run-node', 'flow:30', '--node', 'source'])->andReturnUsing(function ($connection, $arguments, $context, $timeout, $capture, $heartbeat) use ($run): array {
            $this->assertSame(420, $timeout);
            $this->assertIsCallable($heartbeat);
            $run->update(['heartbeat_at' => now()->subMinutes(6)]);
            $heartbeat();
            $this->assertTrue($run->fresh()->heartbeat_at->isAfter(now()->subSeconds(2)));

            return ['exit_code' => 0, 'data' => ['state' => 'finished', 'node' => 'source', 'fields' => 3, 'rows' => 50]];
        });
        $broker = app(ActionBroker::class);
        $result = $broker->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']);
        $review = Approval::findOrFail($result['approval_id']);
        $this->actingAs($user)->postJson('/api/approvals/'.$review->id.'/decide', ['decision' => 'approved', 'fingerprint' => $review->fingerprint])->assertOk();
        Queue::assertPushed(ExecuteApprovedAction::class, 1);

        $job = new ExecuteApprovedAction($review->id);
        $job->handle($broker);
        $job->handle($broker);

        $this->assertSame('completed', $review->fresh()->status);
        $this->assertTrue($review->fresh()->payload['execution']['result']['execution']['native_command_finished']);
        $this->assertSame('completed', $run->fresh()->status);
        $continuation = Message::where('conversation_id', $run->conversation_id)->where('role', 'system')->firstOrFail();
        $this->assertTrue($continuation->metadata['continuation']);
        $this->assertSame('finished', $continuation->metadata['tool_result']['data']['state']);
        $this->assertSame($project->id, $review->project_id);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public function test_source_connection_changed_after_review_blocks_execution(): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $this->cli();
        $broker = app(ActionBroker::class);
        $result = $broker->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']);
        $review = Approval::findOrFail($result['approval_id']);
        $review->update(['status' => 'executing', 'decided_at' => now()]);
        $body['nodes']['Processors'][0]['options']['Credential'] = 'different-source';

        $this->expectExceptionMessage('recurso mudou após a revisão');
        $broker->performApproved($review);
    }

    #[DataProvider('unavailableFlows')]
    public function test_invalid_flow_is_refused_before_an_execution_review(string $case, string $message): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        if ($case === 'published') {
            $body['deskId'] = 9;
        }
        if ($case === 'missing') {
            $body['nodes']['Processors'] = [];
        }
        if ($case === 'cycle') {
            $body['nodes']['Processors'][0]['inputs'] = ['source'];
        }
        $this->catalog($body);
        $this->cli($case !== 'unsynchronized');

        try {
            app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']);
            $this->fail('Fluxo inválido foi aceito.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function unavailableFlows(): array
    {
        return ['published' => ['published', 'publicado'], 'missing' => ['missing', 'não existe'], 'cycle' => ['cycle', 'circular'], 'unsynchronized' => ['unsynchronized', 'Sincronize']];
    }

    public function test_queued_native_result_is_not_reported_as_completed_or_replayed(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [,, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $lumo = $this->cli();
        $lumo->shouldReceive('run')->once()->withArgs(fn ($c, $args) => ($args[1] ?? '') === 'run-node')->andReturn(['exit_code' => 0, 'data' => ['state' => 'queued', 'node' => 'source']]);
        $broker = app(ActionBroker::class);
        $result = $broker->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']);
        $review = Approval::findOrFail($result['approval_id']);
        $review->update(['status' => 'approved', 'decided_at' => now()]);

        $job = new ExecuteApprovedAction($review->id);
        $job->handle($broker);
        $job->handle($broker);

        $this->assertSame('failed', $review->fresh()->status);
        $this->assertStringContainsString('não confirmou o término', $review->fresh()->payload['execution']['message']);
        Queue::assertNothingPushed();
    }

    public function test_null_native_preview_exposes_known_schema_without_claiming_empty_data(): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $lumo = $this->cli();
        $lumo->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['flow', 'preview-node', 'flow:30', '--node', 'source'])->andReturn(['exit_code' => 0, 'data' => ['data' => null, 'node' => 'source']]);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'flow_preview', 'resource' => 'flow:30', 'node' => 'source']);

        $this->assertFalse($result['preview_available']);
        $this->assertSame('latitude', $result['schema'][0]['columnName']);
        $this->assertStringContainsString('não prova que a fonte esteja vazia', $result['message']);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_node_without_previous_execution_gets_an_actionable_next_step(): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        $body['nodes']['Processors'][0]['metadata'] = [];
        $this->catalog($body);
        $this->cli();

        $this->expectExceptionMessage('Use run_flow_node');
        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'flow_preview', 'resource' => 'flow:30', 'node' => 'source']);
    }

    public function test_schema_discovery_is_available_to_ai_without_exposing_credentials(): void
    {
        [$user,, $run] = $this->context();
        $this->mock(BiCredentials::class)->shouldReceive('inspect')->once()->withArgs(fn ($actor, $tenant, $id, $action, $schema) => $actor->id === $user->id && $tenant === '100' && $id === '12' && $action === 'schema' && $schema === 'public')->andReturn(['verified' => true, 'tables' => [['name' => 'visitas']]]);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'credential_schema', 'resource' => 'credential:12', 'schema' => 'public']);

        $this->assertTrue($result['verified']);
        $this->assertSame('visitas', $result['tables'][0]['name']);
    }

    public function test_read_only_tenant_cannot_execute_etl_even_before_review(): void
    {
        [,, $run] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->expectExceptionMessage('somente leitura');
        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'run_flow_node', 'resource' => 'flow:30', 'node' => 'source']);
    }

    public function test_clone_for_maintenance_becomes_the_project_draft_without_publishing(): void
    {
        [$user, $project, $run] = $this->context();
        $this->mock(LumoClient::class)->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['app', 'clone', 'app:10'])->andReturn(['exit_code' => 0, 'data' => ['cloned_id' => 11]]);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'clone_app', 'resource' => 'app:10']);

        $this->assertSame('app:11', $result['project_resource']);
        $this->assertSame('app:11', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['user_id' => $user->id, 'project_id' => $project->id, 'resource' => 'app:11', 'source_ref' => 'app:10', 'status' => 'draft']);
    }

    #[DataProvider('plainLinks')]
    public function test_only_ordinary_app_links_on_the_allowed_bi_domain_are_returned(string $url, bool $valid): void
    {
        config(['horus.viewer.allowed_origins' => ['https://bi.example.test']]);
        if (! $valid) {
            $this->expectException(RuntimeException::class);
        }

        $result = app(BiViewer::class)->plainUrl(['url' => $url], '10');

        $this->assertSame($url, $result);
    }

    public static function plainLinks(): array
    {
        return ['app' => ['https://bi.example.test/app/10', true], 'tab' => ['https://bi.example.test/app/10?dashboard=99', true], 'native tab' => ['https://bi.example.test/app/10?ds=99', true], 'ticket' => ['https://bi.example.test/app/10?ticket=private', false], 'encoded ticket' => ['https://bi.example.test/app/10?%74icket=private', false], 'unknown query' => ['https://bi.example.test/app/10?redirect=https://other.example.test', false], 'other app' => ['https://bi.example.test/app/11', false], 'other domain' => ['https://other.example.test/app/10', false], 'http' => ['http://bi.example.test/app/10', false], 'userinfo' => ['https://user:secret@bi.example.test/app/10', false], 'fragment' => ['https://bi.example.test/app/10#ticket', false]];
    }

    public function test_a_malformed_native_link_cannot_break_preview_context(): void
    {
        $this->expectExceptionMessage('link comum válido');
        app(BiViewer::class)->plainUrl('unexpected native text', '10');
    }

    public function test_new_table_cannot_silently_rederive_an_existing_table(): void
    {
        [,, $run] = $this->context();
        $body = $this->flow();
        $this->catalog($body);
        $this->cli();

        $this->expectExceptionMessage('Já existe uma tabela');
        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'new', 'kind' => 'table', 'name' => 'Mapa', 'flow' => 'flow:30', 'node' => 'dw']);
    }

    public function test_first_table_creation_is_allowed_from_an_unbound_draft_insert_node(): void
    {
        [$user, $project, $run] = $this->context();
        $body = $this->flow();
        $body['nodes']['Processors'][1]['options']['TableID'] = null;
        $this->catalog($body);
        $lumo = $this->cli();
        $lumo->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['new', 'table', '--name', 'Mapa', '--from-flow', 'flow:30', '--node', 'dw'])->andReturn(['exit_code' => 0, 'data' => ['id' => 501]]);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'new', 'kind' => 'table', 'name' => 'Mapa', 'flow' => 'flow:30', 'node' => 'dw']);

        $this->assertSame('table:501', $result['managed_resource']);
        $this->assertSame('app:10', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['user_id' => $user->id, 'resource' => 'table:501', 'status' => 'draft']);
    }

    #[DataProvider('portalPrefixes')]
    public function test_ai_preview_links_keep_the_current_browser_origin_and_the_configured_portal_path(string $prefix): void
    {
        [$user, $project, $run] = $this->context();
        URL::forceRootUrl('http://localhost:8088'.$prefix);
        config(['horus.viewer.allowed_origins' => ['https://bi.example.test']]);
        $catalog = $this->partialMock(BiCatalog::class);
        $catalog->shouldReceive('detail')->once()->withArgs(fn ($actor, $tenant, $kind, $id, $relations) => $actor->id === $user->id && $tenant === '100' && $kind === 'app' && $id === '10' && $relations === false)->andReturn(['resource' => ['id' => '10', 'state' => 'draft', 'dashboards' => [['id' => 99]]]]);
        $lumo = $this->cli();
        $lumo->shouldReceive('run')->once()->withArgs(fn ($connection, $arguments) => $arguments === ['app', 'open', 'app:10', '--no-browser', '--dashboard', '99'])->andReturn(['data' => ['url' => 'https://bi.example.test/app/10?ds=99']]);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'app_link', 'resource' => 'app:10', 'dashboard' => 99]);

        $this->assertSame($prefix.'/app/project/'.$project->id.'?preview=interactive&dashboard=99', $result['preview_path']);
        $this->assertSame('https://bi.example.test/app/10?ds=99', $result['url']);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function portalPrefixes(): array
    {
        return ['root' => [''], 'suite subpath' => ['/ia-ganalise']];
    }
}
