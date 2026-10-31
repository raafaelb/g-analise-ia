<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiFlowClonesTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'editor'): array
    {
        $root = storage_path('framework/testing/flow-clones-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Homologação']]]]);
        $body = ['nome' => 'ETL original', 'deskId' => 70, 'tokenId' => 55, 'load_type' => 'Total', 'nodes' => ['Processors' => [['internalId' => 'extract-original', 'kind' => 'ExtractPostgreSQL', 'inputs' => [], 'options' => ['Credential' => 'private-reference', 'SQL' => "SELECT 'synthetic-query-not-for-the-review' AS value"]], ['internalId' => 'load-original', 'kind' => 'InsertDatawarehouse', 'inputs' => ['extract-original'], 'options' => ['TableID' => 500]]]]];
        $state = (object) ['source' => $body, 'copy' => null, 'ids' => [1], 'clones' => 0, 'pushes' => 0, 'commands' => [], 'pull_fails' => false, 'lost_response' => false, 'reuse_nodes' => false, 'reuse_tables' => false, 'ignore_name' => false, 'unknown_receipt' => false, 'large_receipt' => false, 'changed_copy' => false];

        return [$user, $connection, $state];
    }

    private function write(Project $project, string $id, array $body): string
    {
        $folder = app(WorkspaceFiles::class)->path($project).'/flows';
        File::ensureDirectoryExists($folder, 0700, true);
        $path = $folder.'/flow--'.$id.'.yaml';
        File::put($path, "kind: flow\nid: ".$id."\ntenantId: 100\nlumo: v2\n---\n".Yaml::dump($body, 20, 2));

        return $path;
    }

    private function native(object $state): void
    {
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldReceive('initialize')->andReturnNull();
        $lumo->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, ?Project $project = null) use ($state): array {
            $state->commands[] = $args;
            $this->assertSame('100', (string) $project?->tenant_id);
            if ($args[0] === 'list' && $args[1] === 'flow') {
                return ['data' => ['results' => array_map(fn ($id) => ['id' => $id, 'nome' => 'ETL '.$id], $state->ids), 'matched' => count($state->ids)]];
            }
            if ($args[0] === 'info') {
                $id = explode(':', $args[1], 2)[1];
                $body = $id === '1' ? $state->source : $state->copy;

                return ['data' => ['payload' => ['id' => (int) $id, 'nome' => $body['nome'], 'deskId' => $body['deskId'], 'load_type' => 'Total', 'tokenId' => 55]]];
            }
            if ($args[0] === 'pull') {
                if ($state->pull_fails) {
                    throw new RuntimeException('Autoria indisponível no teste.');
                }
                $id = explode(':', $args[1], 2)[1];
                $this->write($project, $id, $id === '1' ? $state->source : $state->copy);

                return ['data' => []];
            }
            if ($args === ['flow', 'clone', 'flow:1', '--name', 'Cópia de ETL']) {
                $state->clones++;
                $state->copy = [...$state->source, 'nome' => $state->ignore_name ? 'Clone of ETL' : 'Cópia de ETL', 'deskId' => null];
                $state->copy['nodes']['Processors'][0]['internalId'] = $state->reuse_nodes ? 'extract-original' : 'extract-copy';
                $state->copy['nodes']['Processors'][1]['internalId'] = 'load-copy';
                $state->copy['nodes']['Processors'][1]['inputs'] = [$state->copy['nodes']['Processors'][0]['internalId']];
                $state->copy['nodes']['Processors'][1]['options']['TableID'] = $state->reuse_tables ? 500 : 600;
                $state->ids[] = 2;
                $this->write($project, '2', $state->copy);
                if ($state->lost_response) {
                    throw new RuntimeException('Resultado nativo sem confirmação.');
                }

                return ['exit_code' => 0, 'data' => $state->unknown_receipt ? ['status' => 'created'] : ['cloned_id' => 2, ...($state->large_receipt ? ['native_document' => str_repeat('synthetic-private-content', 4000)] : [])]];
            }
            if ($args[0] === 'lint') {
                return ['data' => ['valid' => true]];
            }
            if ($args === ['push', 'flow:2']) {
                $state->pushes++;
                $path = app(WorkspaceFiles::class)->path($project).'/flows/flow--2.yaml';
                $state->copy = Yaml::parse(explode("---\n", File::get($path), 2)[1]);
                if ($state->changed_copy) {
                    $state->copy['nodes']['Processors'][0]['options']['SQL'] = 'SELECT 99';
                    $this->write($project, '2', $state->copy);
                }

                return ['data' => []];
            }
            throw new RuntimeException('Comando inesperado: '.json_encode($args));
        });
    }

    private function data(): array
    {
        return ['action' => 'clone_flow', 'name' => 'Cópia de ETL', 'request_id' => (string) Str::uuid()];
    }

    private function prepare(User $user, ?array $data = null): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', $data ?? $this->data())->assertCreated();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_only_authors_can_prepare_a_flow_clone(): void
    {
        $this->postJson('/api/catalog/100/flows/1/actions', $this->data())->assertUnauthorized();
        [$user] = $this->context('viewer');
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', $this->data())->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_live_definition_is_reviewed_once_without_exposing_queries_or_running_ai(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $this->native($state);
        $data = $this->data();

        $approval = $this->prepare($user, $data);
        $this->postJson('/api/catalog/100/flows/1/actions', $data)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);

        $this->assertSame('manual', $approval->type);
        $this->assertSame(2, $approval->payload['revision']['flow_clone']['node_count']);
        $this->assertSame(['table:500'], $approval->payload['revision']['flow_clone']['source_table_refs']);
        $this->assertStringNotContainsString('synthetic-query-not-for-the-review', json_encode($approval->payload));
        $this->assertStringNotContainsString('private-reference', json_encode($approval->payload));
        $this->assertSame(0, $state->clones);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_unknown_tenants_and_foreign_resources_cannot_be_cloned(): void
    {
        [$user,, $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/200/flows/1/actions', $this->data())->assertForbidden();
        $this->postJson('/api/catalog/100/flows/99/actions', $this->data())->assertNotFound();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->clones);
    }

    public function test_request_ids_cannot_be_reused_with_another_copy_name(): void
    {
        [$user,, $state] = $this->context();
        $this->native($state);
        $data = $this->data();
        $this->prepare($user, $data);
        $this->postJson('/api/catalog/100/flows/1/actions', [...$data, 'name' => 'Outra cópia'])->assertConflict();
        $this->assertDatabaseCount('approvals', 1);
    }

    #[DataProvider('invalidActions')]
    public function test_the_flow_endpoint_cannot_publish_delete_or_clone_an_app(string $action): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', [...$this->data(), 'action' => $action])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidActions(): array
    {
        return [['clone_app'], ['publish_flow'], ['delete_draft']];
    }

    public function test_missing_authoring_metadata_cannot_be_replaced_by_a_summary(): void
    {
        [$user,, $state] = $this->context();
        $state->pull_fails = true;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', $this->data())->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->clones);
    }

    public function test_a_read_only_tenant_blocks_the_clone_before_any_native_call(): void
    {
        [$user] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', $this->data())->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_approved_clone_executes_once_and_confirms_new_nodes_and_tables_in_its_own_project(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $this->native($state);
        $source = $state->source;
        $approval = $this->prepare($user);

        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $approval->refresh();
        $this->assertSame('completed', $approval->status);
        $this->assertSame(1, $state->clones);
        $this->assertSame($source, $state->source);
        $this->assertSame('flow:2', Project::findOrFail($approval->project_id)->source_ref);
        $managed = ManagedBiResource::firstOrFail();
        $this->assertSame('clone_flow', $managed->origin);
        $this->assertSame('flow:1', $managed->source_ref);
        $this->assertSame($approval->project_id, $managed->project_id);
        $result = $approval->payload['execution']['result'];
        $this->assertTrue($result['flow_clone']['verified']);
        $this->assertTrue($result['flow_clone']['requires_load_test']);
        $this->assertSame(['table:600'], $result['flow_clone']['table_refs']);
        $this->assertSame(['extract-copy', 'load-copy'], array_column($result['flow_clone']['nodes'], 'id'));
        $this->assertNotEmpty($approval->payload['flow_clone_attempt']['started_at']);
        $this->assertSame([], array_values(array_filter($state->commands, fn ($args) => in_array($args[1] ?? '', ['run', 'run-node', 'publish'], true))));
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_name_repair_changes_only_the_new_copy_and_preserves_its_steps(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $state->ignore_name = true;
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $result = $approval->fresh()->payload['execution']['result'];
        $this->assertTrue($result['name_verification']['renamed']);
        $this->assertTrue($result['flow_clone']['verified']);
        $this->assertSame(1, $state->pushes);
        $this->assertSame('Cópia de ETL', $state->copy['nome']);
        $this->assertSame('ETL original', $state->source['nome']);
        $this->assertSame(600, $state->copy['nodes']['Processors'][1]['options']['TableID']);
    }

    public function test_source_changes_after_review_prevent_the_clone(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->source['nodes']['Processors'][0]['options']['SQL'] = 'SELECT 2';

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->clones);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public function test_reconnection_invalidates_a_prepared_clone(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->clones);
    }

    #[DataProvider('unverifiedCopies')]
    public function test_reused_identifiers_leave_the_created_copy_unverified_without_retrying(string $flag): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $state->{$flag} = true;
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $result = $approval->fresh()->payload['execution']['result'];
        $this->assertFalse($result['flow_clone']['verified']);
        $this->assertNotEmpty($result['management_warning']);
        $this->assertSame('flow:2', $result['managed_resource']);
        $this->assertSame(1, $state->clones);
    }

    public static function unverifiedCopies(): array
    {
        return [['reuse_nodes'], ['reuse_tables']];
    }

    public function test_an_unknown_receipt_never_adopts_the_source_or_repeats_the_clone(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $state->unknown_receipt = true;
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $this->assertDatabaseCount('managed_bi_resources', 0);
        $this->assertNull(Project::findOrFail($approval->project_id)->source_ref);
        $this->assertNotEmpty($approval->fresh()->payload['execution']['result']['management_warning']);
        $this->assertSame(1, $state->clones);
    }

    public function test_a_lost_response_keeps_the_attempt_and_never_resends_it(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $state->lost_response = true;
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertNotEmpty($approval->fresh()->payload['flow_clone_attempt']['started_at']);
        $this->assertSame(1, $state->clones);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public function test_large_native_documents_do_not_hide_the_new_resource_or_leak_into_the_result(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $state->large_receipt = true;
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $result = $approval->fresh()->payload['execution']['result'];
        $this->assertSame('flow:2', $result['managed_resource']);
        $this->assertTrue($result['flow_clone']['verified']);
        $this->assertStringNotContainsString('synthetic-private-content', json_encode($result));
        $this->assertLessThan(60000, strlen(json_encode($result)));
    }

    public function test_ai_cloning_also_requires_review_and_preserves_an_existing_app_binding(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $this->native($state);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Dashboard existente', 'source_ref' => 'app:10']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);

        $prepared = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'clone_flow', 'resource' => 'flow:1', 'name' => 'Cópia de ETL']);
        $this->assertSame('awaiting_approval', $prepared['status']);
        $this->assertSame(0, $state->clones);
        $approval = Approval::findOrFail($prepared['approval_id']);
        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame('app:10', $project->fresh()->source_ref);
        $this->assertSame('flow:2', $approval->fresh()->payload['execution']['result']['managed_resource']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_copy_names_are_rejected_before_native_reads(string $name): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', [...$this->data(), 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidNames(): array
    {
        return [[''], [str_repeat('a', 101)], ["Nome\x01inválido"]];
    }

    public function test_rejecting_the_review_keeps_the_native_resources_unchanged(): void
    {
        Queue::fake();
        [$user,, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);

        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'rejected', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('rejected', $approval->fresh()->status);
        $this->assertSame(0, $state->clones);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        Queue::assertNotPushed(ExecuteApprovedAction::class);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_incomplete_or_ambiguous_steps_cannot_be_reviewed(string $type): void
    {
        [$user,, $state] = $this->context();
        if ($type === 'missing') {
            unset($state->source['nodes']);
        } elseif ($type === 'duplicate') {
            $state->source['nodes']['Processors'][1]['internalId'] = 'extract-original';
        } else {
            unset($state->source['nodes']['Processors'][0]['kind']);
        }
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/1/actions', $this->data())->assertUnprocessable()->assertJsonValidationErrors('operation');

        $this->assertSame(0, $state->clones);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidDefinitions(): array
    {
        return [['missing'], ['duplicate'], ['missing-kind']];
    }
}
