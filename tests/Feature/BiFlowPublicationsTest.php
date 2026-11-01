<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ManagedBiResource;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiFlowPublications;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiFlowPublicationsTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'editor'): array
    {
        $root = storage_path('framework/testing/flow-publications-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $user = User::factory()->create(['role' => $role]);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Preparação dos dados', 'source_ref' => 'flow:2']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Homologação']]]]);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'flow:2', 'origin' => 'clone_flow', 'source_ref' => 'flow:1', 'receipt' => []]);
        $flow = fn (int $id, int $table, ?int $desk): array => ['nome' => $id === 1 ? 'ETL compartilhado' : 'ETL em revisão', 'deskId' => $desk, 'cloned_from' => $id === 2 ? 1 : null, 'originalTableId' => $id === 2 ? 500 : null, 'table' => ['id' => $table, 'nome' => 'Vendas'], 'tokenId' => 55, 'load_type' => 'Total', 'write_mode' => null, 'development_variables' => [], 'tags' => [], 'nodes' => ['Processors' => [['internalId' => 'extract'.$id, 'complete_id' => 'ExtractPostgreSQL_extract'.$id, 'kind' => 'ExtractPostgreSQL', 'description' => 'Ler vendas', 'inputs' => [], 'options' => ['Credential' => 'synthetic-private-reference', 'SQL' => "SELECT 'synthetic-query-not-for-review' AS value"], 'metadata' => ['ExecutedWhen' => '2026-10-01T00:00:00Z', 'Rows' => 3]], ['internalId' => 'load'.$id, 'complete_id' => 'InsertDatawarehouse_load'.$id, 'kind' => 'InsertDatawarehouse', 'description' => 'Salvar vendas', 'inputs' => ['ExtractPostgreSQL_extract'.$id], 'options' => ['TableID' => $table], 'metadata' => []]]]];
        $table = fn (int $owner, ?int $desk): array => ['nome' => 'Vendas', 'deskId' => $desk, 'originFlowId' => $owner, 'table_type' => 'table', 'key_type' => 'duplicate', 'key_columns' => [], 'partition_column' => null, 'columns' => ['ID (Number)' => ['label' => 'Código'], 'VALOR (Number)' => ['label' => 'Valor']]];
        $state = (object) ['bodies' => ['flow:1' => $flow(1, 500, 10), 'flow:2' => $flow(2, 600, null), 'table:500' => $table(1, 10), 'table:600' => $table(2, null)], 'desks' => [['id' => 10, 'nome' => 'Dados financeiros']], 'commands' => [], 'publishes' => 0, 'clean' => true, 'valid' => true, 'apply' => true, 'lost_response' => false, 'readback_unavailable' => false, 'corrupt_steps' => false, 'corrupt_schema' => false, 'keep_clone' => false, 'regenerate_ids' => false, 'large_receipt' => false, 'reconnect_during_pull' => false, 'revoke_during_pull' => false];

        return [$user, $project, $connection, $state];
    }

    private function document(Project $project, string $ref, array $body): void
    {
        [$kind, $id] = explode(':', $ref, 2);
        $folder = app(WorkspaceFiles::class)->path($project).($kind === 'flow' ? '/flows' : '/tables');
        File::ensureDirectoryExists($folder, 0700, true);
        File::put($folder.'/resource--'.$id.'.yaml', 'kind: '.$kind."\nid: ".$id."\ntenantId: 100\nlumo: v2\n---\n".Yaml::dump($body, 30, 2));
    }

    private function native(object $state): void
    {
        $native = $this->mock(LumoClient::class);
        $native->shouldReceive('initialize')->andReturnUsing(function (Connection $connection, Project $project, bool $readOnly = false) use ($state): void {
            if (! $readOnly) {
                foreach ($state->bodies as $ref => $body) {
                    $this->document($project, $ref, $body);
                }
            }
        });
        $native->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, ?Project $project = null) use ($state): array {
            $state->commands[] = $args;
            $this->assertSame('100', (string) $project?->tenant_id);
            if ($args[0] === 'list') {
                $rows = $args[1] === 'dw-desk' ? $state->desks : [];
                if (in_array($args[1], ['flow', 'table'], true)) {
                    foreach ($state->bodies as $ref => $body) {
                        if (str_starts_with($ref, $args[1].':')) {
                            $rows[] = ['id' => (int) explode(':', $ref, 2)[1], 'nome' => $body['nome'], 'deskId' => $body['deskId']];
                        }
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'info') {
                $body = $state->bodies[$args[1]];

                return ['data' => ['payload' => ['id' => (int) explode(':', $args[1], 2)[1], 'nome' => $body['nome'], 'deskId' => $body['deskId']]]];
            }
            if ($args[0] === 'pull') {
                if (($state->pull_fails ?? false) || ($state->publishes > 0 && $state->readback_unavailable)) {
                    throw new RuntimeException('Leitura indisponível no contrato de teste.');
                }
                $this->document($project, $args[1], $state->bodies[$args[1]]);
                if ($state->reconnect_during_pull) {
                    $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
                    $state->reconnect_during_pull = false;
                }
                if ($state->revoke_during_pull) {
                    User::findOrFail($connection->user_id)->forceFill(['role' => 'viewer'])->save();
                    $state->revoke_during_pull = false;
                }

                return ['data' => []];
            }
            if ($args[0] === 'diff') {
                return ['data' => ['workingVsServer' => ['equal' => $state->clean]]];
            }
            if ($args[0] === 'lint') {
                return ['exit_code' => 0, 'data' => ['valid' => $state->valid]];
            }
            if (array_slice($args, 0, 2) === ['flow', 'publish']) {
                $state->publishes++;
                $approval = Approval::where('status', 'executing')->latest('created_at')->firstOrFail();
                $this->assertNotEmpty($approval->payload['publication_attempt']['started_at']);
                if ($state->apply) {
                    $new = in_array('--new', $args, true);
                    $target = $new ? 'flow:2' : 'flow:1';
                    $table = $new ? 600 : 500;
                    $state->bodies[$target] = [...$state->bodies['flow:2'], 'deskId' => 10, 'table' => ['id' => $table, 'nome' => 'Vendas'], 'originalTableId' => null, 'cloned_from' => null];
                    $state->bodies[$target]['nodes']['Processors'][1]['options']['TableID'] = $table;
                    if ($state->regenerate_ids) {
                        $state->bodies[$target]['nodes']['Processors'][0]['internalId'] = 'extract-published';
                        $state->bodies[$target]['nodes']['Processors'][0]['complete_id'] = 'ExtractPostgreSQL_extract-published';
                        $state->bodies[$target]['nodes']['Processors'][1]['internalId'] = 'load-published';
                        $state->bodies[$target]['nodes']['Processors'][1]['complete_id'] = 'InsertDatawarehouse_load-published';
                        $state->bodies[$target]['nodes']['Processors'][1]['inputs'] = ['ExtractPostgreSQL_extract-published'];
                    }
                    $state->bodies['table:'.$table] = [...$state->bodies['table:600'], 'deskId' => 10, 'originFlowId' => $new ? 2 : 1];
                    if (! $new && ! $state->keep_clone) {
                        unset($state->bodies['flow:2'], $state->bodies['table:600']);
                    }
                    if ($state->corrupt_steps) {
                        $state->bodies[$target]['nodes']['Processors'][0]['options']['SQL'] = 'SELECT 999';
                    }
                    if ($state->corrupt_schema) {
                        unset($state->bodies['table:'.$table]['columns']['VALOR (Number)']);
                    }
                }
                if ($state->disconnect_openai ?? false) {
                    Connection::where('user_id', $approval->user_id)->where('provider', 'openai')->update(['status' => 'disconnected']);
                }
                if ($state->cancel_after_publish ?? false) {
                    AgentRun::whereKey($approval->agent_run_id)->update(['status' => 'cancel_requested']);
                }
                if ($state->lost_response) {
                    throw new RuntimeException('A resposta da publicação foi interrompida.');
                }

                return ['exit_code' => 0, 'data' => ['accepted' => true, ...($state->large_receipt ? ['native_document' => str_repeat('synthetic-private-document', 4000)] : [])]];
            }
            throw new RuntimeException('Comando inesperado: '.json_encode($args));
        });
    }

    private function input(string $mode = 'new', bool $copy = false): array
    {
        return ['action' => 'publish_flow', 'desk' => 10, 'publish_mode' => $mode, 'copy_data' => $copy, 'request_id' => (string) Str::uuid()];
    }

    private function prepare(User $user, ?array $input = null): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $input ?? $this->input())->assertCreated()->assertJsonPath('approval.status', 'pending');

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_guests_and_readers_cannot_prepare_a_flow_publication(): void
    {
        $this->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnauthorized();
        [$user] = $this->context('viewer');
        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertForbidden();
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_publication_is_reviewed_with_a_named_data_desk_and_without_private_queries(): void
    {
        Queue::fake();
        [$user,,, $state] = $this->context();
        $this->native($state);
        $input = $this->input();
        $approval = $this->prepare($user, $input);
        $plan = $approval->payload['revision']['publication'];

        $this->assertSame('flow', $plan['kind']);
        $this->assertSame('new', $plan['mode']);
        $this->assertSame('Dados financeiros', $plan['destination']['name']);
        $this->assertSame('dw-desk:10', $plan['destination']['resource']);
        $this->assertSame('flow:2', $plan['target']['resource']);
        $this->assertSame('table:600', $plan['tables'][0]['target_ref']);
        $this->assertFalse($plan['copy_data']);
        $this->assertStringNotContainsString('synthetic-query-not-for-review', json_encode($approval->payload));
        $this->assertStringNotContainsString('synthetic-private-reference', json_encode($approval->payload));
        $this->postJson('/api/catalog/100/flows/2/actions', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('approval.id', $approval->id);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->publishes);
        Queue::assertNothingPushed();
    }

    public function test_a_new_publication_preserves_the_original_and_confirms_the_schema_without_claiming_a_load(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        $original = [$state->bodies['flow:1'], $state->bodies['table:500']];
        $state->large_receipt = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $approval->refresh();

        $this->assertSame('completed', $approval->status);
        $this->assertSame(1, $state->publishes);
        $this->assertContains(['flow', 'publish', 'flow:2', '--desk', '10', '--new'], $state->commands);
        $this->assertSame($original, [$state->bodies['flow:1'], $state->bodies['table:500']]);
        $result = $approval->payload['execution']['result'];
        $this->assertTrue($result['publication']['verified']);
        $this->assertTrue($result['publication']['requires_data_check']);
        $this->assertFalse($result['publication']['data_verified']);
        $this->assertSame(['table:600'], $result['publication']['table_refs']);
        $this->assertSame('flow:2', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'published']);
        $this->assertStringNotContainsString('synthetic-private-document', json_encode($result));
        $this->assertSame([], array_values(array_filter($state->commands, fn ($args) => in_array($args[1] ?? '', ['run', 'run-node', 'resume'], true))));
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_replacement_names_the_original_and_detects_added_removed_and_changed_columns(): void
    {
        [$user,,, $state] = $this->context();
        $state->bodies['table:600']['columns'] = ['ID (String)' => [], 'TOTAL (Number)' => []];
        $state->bodies['table:600']['key_type'] = 'unique';
        $state->bodies['table:600']['key_columns'] = ['ID'];
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace', true));
        $plan = $approval->payload['revision']['publication'];

        $this->assertSame('flow:1', $plan['target']['resource']);
        $this->assertSame('ETL compartilhado', $plan['target']['name']);
        $this->assertTrue($plan['copy_data']);
        $this->assertSame('table:500', $plan['tables'][0]['target_ref']);
        $changes = $plan['tables'][0]['schema_changes'];
        $this->assertSame(['TOTAL'], $changes['added']);
        $this->assertSame(['VALOR'], $changes['removed']);
        $this->assertSame([['name' => 'ID', 'previous' => 'Number', 'next' => 'String']], $changes['changed_types']);
        $this->assertTrue($changes['storage_changed']);
        $this->assertSame(0, $state->publishes);
    }

    public function test_replacement_confirms_the_surviving_ids_and_only_requests_data_copy_when_reviewed(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        $state->regenerate_ids = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace', true));
        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertContains(['flow', 'publish', 'flow:2', '--desk', '10', '--copy-data'], $state->commands);
        $this->assertSame('flow:1', $project->fresh()->source_ref);
        $confirmation = $approval->fresh()->payload['execution']['result']['publication'];
        $this->assertTrue($confirmation['verified']);
        $this->assertTrue($confirmation['copy_data']);
        $this->assertFalse($confirmation['data_verified']);
        $this->assertSame(['table:500'], $confirmation['table_refs']);
        $this->assertArrayNotHasKey('flow:2', $state->bodies);
        $this->assertArrayNotHasKey('table:600', $state->bodies);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_an_existing_private_flow_can_be_reviewed_without_becoming_an_automatically_deletable_resource(): void
    {
        [$user, $project,, $state] = $this->context();
        ManagedBiResource::query()->delete();
        $project->update(['source_ref' => 'app:77']);
        $this->native($state);
        $approval = $this->prepare($user);

        $this->assertNotSame($project->id, $approval->project_id);
        $this->assertDatabaseHas('projects', ['id' => $approval->project_id, 'user_id' => $user->id, 'source_ref' => 'flow:2']);
        $this->assertSame('app:77', $project->fresh()->source_ref);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        $this->assertSame(0, $state->publishes);
    }

    public function test_publishing_a_supporting_flow_preserves_the_projects_existing_app_binding(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        $project->update(['source_ref' => 'app:77']);
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace'));
        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame('app:77', $project->fresh()->source_ref);
        $this->assertContains(['flow', 'publish', 'flow:2', '--desk', '10'], $state->commands);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    #[DataProvider('invalidStructures')]
    public function test_incomplete_definitions_are_rejected_before_a_publication_can_be_authorized(string $case): void
    {
        [$user,,, $state] = $this->context();
        if ($case === 'table') {
            $state->bodies['flow:2']['nodes']['Processors'][1]['options']['TableID'] = null;
        } elseif ($case === 'columns') {
            $state->bodies['table:600']['columns'] = [];
        } else {
            $state->bodies['flow:2']['nodes']['Processors'][1]['inputs'] = ['unknown-node'];
        }
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->publishes);
    }

    public static function invalidStructures(): array
    {
        return [['table'], ['columns'], ['edge']];
    }

    public function test_a_read_only_environment_cannot_prepare_a_publication(): void
    {
        [$user, , $connection, $state] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->publishes);
    }

    public function test_multiple_private_destinations_are_named_but_cannot_be_guessed_during_replacement(): void
    {
        [$user, $project, $connection, $state] = $this->context();
        $second = $state->bodies['flow:2']['nodes']['Processors'][1];
        $second['internalId'] = 'second-sink';
        $second['complete_id'] = 'InsertDatawarehouse_second-sink';
        $second['options']['TableID'] = 700;
        $state->bodies['flow:2']['nodes']['Processors'][] = $second;
        $state->bodies['table:700'] = [...$state->bodies['table:600'], 'nome' => 'Vendas por equipe'];
        $this->native($state);

        $plan = app(BiFlowPublications::class)->plan($connection, $project, ['resource' => 'flow:2', 'desk' => 10, 'publish_mode' => 'new']);
        $this->assertSame(['table:600', 'table:700'], array_column($plan['tables'], 'target_ref'));
        $this->assertSame(['Vendas', 'Vendas por equipe'], array_column($plan['tables'], 'name'));
        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input('replace'))->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    #[DataProvider('invalidDestinations')]
    public function test_unavailable_or_incompatible_targets_are_rejected_without_sending(string $case, int $status): void
    {
        [$user,,, $state] = $this->context();
        if ($case === 'desk') {
            $state->desks = [];
        } elseif ($case === 'origin') {
            $state->bodies['flow:2']['cloned_from'] = 99;
        } elseif ($case === 'table') {
            $state->bodies['flow:2']['originalTableId'] = 999;
        } elseif ($case === 'desk-mismatch') {
            $state->bodies['flow:1']['deskId'] = 11;
        } elseif ($case === 'published-source') {
            $state->bodies['flow:2']['deskId'] = 10;
        } else {
            $state->bodies['table:600']['originFlowId'] = 99;
        }
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input('replace'))->assertStatus($status);
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidDestinations(): array
    {
        return [['desk', 422], ['origin', 404], ['table', 422], ['desk-mismatch', 422], ['published-source', 422], ['foreign-owner', 422]];
    }

    public function test_copying_data_is_rejected_for_a_new_publication(): void
    {
        [$user,,, $state] = $this->context();
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input('new', true))->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->publishes);
    }

    #[DataProvider('changedRevisions')]
    public function test_drift_or_lost_access_after_review_prevents_publication(string $case): void
    {
        Queue::fake();
        [$user, $project, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace'));
        if ($case === 'source') {
            $state->bodies['flow:2']['nodes']['Processors'][0]['options']['SQL'] = 'SELECT 33';
        } elseif ($case === 'original') {
            $state->bodies['flow:1']['nome'] = 'Outra versão';
        } elseif ($case === 'table') {
            $state->bodies['table:500']['columns']['NOVO (Number)'] = [];
        } elseif ($case === 'session') {
            $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        } elseif ($case === 'archive') {
            $project->update(['status' => 'archived']);
        } else {
            $state->clean = false;
        }
        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function changedRevisions(): array
    {
        return array_map(fn ($case) => [$case], ['source', 'original', 'table', 'session', 'archive', 'unsynchronized']);
    }

    #[DataProvider('unconfirmedResults')]
    public function test_an_incomplete_native_result_remains_pending_without_resending(string $case): void
    {
        Queue::fake();
        [$user,,, $state] = $this->context();
        $state->{$case} = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace'));
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'publication_pending']);
        $this->assertNotEmpty($approval->fresh()->payload['publication_attempt']['started_at']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function unconfirmedResults(): array
    {
        return array_map(fn ($case) => [$case], ['corrupt_steps', 'corrupt_schema', 'keep_clone', 'readback_unavailable']);
    }

    public function test_a_lost_response_can_be_reconciled_after_reconnection_without_publishing_twice(): void
    {
        Queue::fake();
        [$user, $project, $connection, $state] = $this->context();
        $state->lost_response = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input('replace'));
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertConflict();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        $state->lost_response = false;

        $this->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.published_resource', 'flow:1')->assertJsonPath('approval.payload.reconciliation.remote_read_only', true);
        $this->assertSame('flow:1', $project->fresh()->source_ref);
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_a_reader_or_another_user_cannot_reconcile_someone_elses_publication(): void
    {
        Queue::fake();
        [$user,,, $state] = $this->context();
        $state->readback_unavailable = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $other = User::factory()->create(['role' => 'editor']);

        $this->actingAs($other)->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertNotFound();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertForbidden();
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_permission_revocation_during_reading_does_not_produce_a_usable_review(): void
    {
        [$user,,, $state] = $this->context();
        $state->revoke_during_pull = true;
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable();
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_ai_publication_uses_the_same_named_plan_and_requires_a_human_decision(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        $this->native($state);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);

        $prepared = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'publish_flow', 'resource' => 'flow:2', 'desk' => 10]);
        $this->assertSame('awaiting_approval', $prepared['status']);
        $approval = Approval::findOrFail($prepared['approval_id']);
        $this->assertSame('new', $approval->payload['revision']['publication']['mode']);
        $this->assertSame('Dados financeiros', $approval->payload['revision']['publication']['destination']['name']);
        $this->assertSame(0, $state->publishes);
        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame(1, $state->publishes);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public function test_missing_destinations_and_invalid_copy_choices_are_validation_errors(): void
    {
        [$user] = $this->context();
        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', ['action' => 'publish_flow', 'copy_data' => 'yes', 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['desk', 'publish_mode', 'copy_data']);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_unknown_tenants_and_out_of_scope_flows_cannot_be_published(): void
    {
        [$user,,, $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/200/flows/2/actions', $this->input())->assertForbidden();
        $this->postJson('/api/catalog/100/flows/999/actions', $this->input())->assertNotFound();
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    private function agentPublication(User $user, Project $project): array
    {
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $prepared = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'publish_flow', 'resource' => 'flow:2', 'desk' => 10, 'publish_mode' => 'replace']);

        return [Approval::findOrFail($prepared['approval_id']), $run];
    }

    public function test_ai_recovers_a_lost_publication_response_inside_the_existing_project_lock_without_resending(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => []]);
        $state->lost_response = true;
        $this->native($state);
        [$approval, $originalRun] = $this->agentPublication($user, $project);
        $attachment = Artifact::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'referencia.txt', 'kind' => 'attachment', 'mime' => 'text/plain', 'path' => 'uploads/test-reference']);
        $originalRun->update(['attachment_ids' => [$attachment->id]]);
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $resumed = AgentRun::where('conversation_id', $originalRun->conversation_id)->where('id', '!=', $originalRun->id)->sole();
        $this->assertSame([$attachment->id], $resumed->attachment_ids);
        $message = Message::findOrFail($resumed->input_message_id);
        $this->assertSame('queued', $resumed->status);
        $this->assertSame($approval->id, $message->metadata['publication_recovery_approval_id']);
        $this->assertSame(1, $message->metadata['publication_recovery_attempt']);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(1, $state->publishes);
        $resumed->update(['status' => 'running']);
        $state->lost_response = false;
        $lock = Cache::lock('project:'.$project->id, 630);
        $this->assertTrue($lock->get());
        try {
            $result = app(ActionBroker::class)->execute($resumed, 'horus_action', ['action' => 'publication_status', 'approval_id' => $approval->id]);
        } finally {
            $lock->release();
        }

        $this->assertSame('confirmed', $result['status']);
        $this->assertTrue($result['remote_read_only']);
        $this->assertSame('flow:1', $result['publication']['resource']);
        $this->assertFalse($result['publication']['data_verified']);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame('flow:1', $project->fresh()->source_ref);
        $this->assertSame(1, $state->publishes);
        $info = app(ActionBroker::class)->execute($resumed, 'horus_action', ['action' => 'info', 'resource' => 'flow:1']);
        $this->assertSame('ETL em revisão', $info['data']['payload']['nome']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    #[DataProvider('restrictedRecoveryTools')]
    public function test_an_automatic_recovery_cannot_write_or_repeat_publication_before_confirmation(string $tool, array $arguments): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => []]);
        $state->lost_response = true;
        $this->native($state);
        [$approval, $originalRun] = $this->agentPublication($user, $project);
        $this->execute($user, $approval);
        $resumed = AgentRun::where('conversation_id', $originalRun->conversation_id)->where('id', '!=', $originalRun->id)->sole();
        $resumed->update(['status' => 'running']);
        $commands = $state->commands;
        try {
            app(ActionBroker::class)->execute($resumed, $tool, $arguments);
            $this->fail('A retomada não pode executar outras ferramentas com publicação pendente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permite somente conferir a publicação autorizada', $e->getMessage());
        }

        $this->assertSame($commands, $state->commands);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertFileDoesNotExist(app(WorkspaceFiles::class)->path($project).'/flows/forbidden.yaml');
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public static function restrictedRecoveryTools(): array
    {
        return [
            ['workspace_file', ['operation' => 'write', 'path' => 'flows/forbidden.yaml', 'content' => 'untrusted']],
            ['horus_action', ['action' => 'publish_flow', 'resource' => 'flow:1', 'desk' => 10]],
            ['horus_action', ['action' => 'publication_status', 'approval_id' => '00000000-0000-4000-8000-000000000099']],
        ];
    }

    #[DataProvider('interruptedRecoveries')]
    public function test_disconnected_ai_or_user_cancellation_prevents_automatic_publication_recovery(string $case): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => []]);
        $state->lost_response = true;
        $state->{$case} = true;
        $this->native($state);
        [$approval] = $this->agentPublication($user, $project);
        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function interruptedRecoveries(): array
    {
        return [['disconnect_openai'], ['cancel_after_publish']];
    }

    public function test_publication_status_cannot_inspect_another_project_even_for_the_same_user(): void
    {
        Queue::fake();
        [$user, $project,, $state] = $this->context();
        $state->lost_response = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $other = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Outro projeto', 'source_ref' => 'flow:2']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $other->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $commands = $state->commands;
        try {
            app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'publication_status', 'approval_id' => $approval->id]);
            $this->fail('Uma revisão de outro projeto não pode ser conferida por esta conversa.');
        } catch (ModelNotFoundException) {
            $this->assertSame($commands, $state->commands);
        }
        $this->assertSame('failed', $approval->fresh()->status);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_the_agent_cannot_treat_a_string_as_consent_to_copy_data(): void
    {
        $project = new Project(['tenant_id' => '100']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A escolha de copiar dados deve ser verdadeira ou falsa.');

        app(ActionBroker::class)->command(['action' => 'publish_flow', 'resource' => 'flow:2', 'desk' => 10, 'publish_mode' => 'replace', 'copy_data' => 'false'], $project);
    }

    public function test_a_previous_cache_cannot_authorize_publication_when_current_metadata_cannot_be_read(): void
    {
        [$user,,, $state] = $this->context();
        $this->native($state);
        $this->prepare($user);
        $state->pull_fails = true;
        $state->bodies['flow:2']['nodes']['Processors'][0]['options']['SQL'] = 'SELECT 888';

        $this->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->publishes);
    }

    public function test_request_reuse_cannot_retain_data_copy_consent_after_that_choice_changes(): void
    {
        [$user,,, $state] = $this->context();
        $this->native($state);
        $input = $this->input('replace', true);
        $this->prepare($user, $input);

        $this->postJson('/api/catalog/100/flows/2/actions', [...$input, 'copy_data' => false])->assertConflict();
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->publishes);
    }
}
