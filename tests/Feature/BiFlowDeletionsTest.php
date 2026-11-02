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
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiFlowDeletionsTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'editor'): array
    {
        $root = storage_path('framework/testing/flow-deletions-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Homologação']]]]);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'ETL de teste', 'source_ref' => 'flow:2']);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'flow:2', 'origin' => 'clone_flow', 'status' => 'draft', 'receipt' => []]);
        $bodies = [
            'flow:1' => ['nome' => 'ETL original', 'deskId' => 70, 'nodes' => ['Processors' => []]],
            'flow:2' => ['nome' => 'ETL de teste', 'deskId' => null, 'cloned_from' => 1, 'table' => ['id' => 600], 'nodes' => ['Processors' => [['kind' => 'ExtractPostgreSQL', 'internalId' => 'extract-copy', 'options' => ['SQL' => 'SELECT synthetic_private_query']], ['kind' => 'InsertDatawarehouse', 'internalId' => 'load-copy', 'options' => ['TableID' => 600]]]]],
            'table:500' => ['nome' => 'Original', 'deskId' => 70, 'originFlowId' => 1, 'columns' => ['VALUE (Number)' => ['label' => 'Valor']]],
            'table:600' => ['nome' => 'Destino de teste', 'deskId' => null, 'originFlowId' => 2, 'columns' => ['VALUE (Number)' => ['label' => 'Valor']]],
            'table:601' => ['nome' => 'Destino anterior do teste', 'deskId' => null, 'originFlowId' => 2, 'columns' => ['OTHER (String)' => ['label' => 'Outro']]],
            'app:9' => ['nome' => 'Dashboard original', '_state' => ['deskId' => 80], 'tables' => [['tableId' => 500]], 'dashboards' => []],
            'schedule:8' => ['nome' => 'Agenda original', 'flows' => [['flow_etl_id' => 1]], 'ativo' => true],
        ];
        $state = (object) ['bodies' => $bodies, 'sent' => 0, 'commands' => [], 'missing_metadata' => null, 'denied_list' => null, 'refused' => false, 'lost_response' => false, 'keep_table' => false, 'lookup_denied' => false, 'local_changed' => false];

        return [$user, $connection, $project, $state];
    }

    private function write(Project $project, string $ref, array $body): string
    {
        [$kind, $id] = explode(':', $ref, 2);
        $folders = ['flow' => 'flows', 'table' => 'tables', 'app' => 'apps', 'schedule' => 'schedules'];
        $folder = app(WorkspaceFiles::class)->path($project).'/'.$folders[$kind];
        File::ensureDirectoryExists($folder, 0700, true);
        $path = $folder.'/resource--'.$id.'.yaml';
        File::put($path, 'kind: '.$kind."\nid: ".$id."\ntenantId: 100\nlumo: v2\n---\n".Yaml::dump($body, 20, 2));

        return $path;
    }

    private function native(object $state): void
    {
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldReceive('initialize')->andReturnNull();
        $lumo->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, ?Project $project = null) use ($state): array {
            $this->assertSame('100', (string) $project?->tenant_id);
            $state->commands[] = $args;
            if ($args[0] === 'status') {
                return ['data' => ['tenantId' => 100, 'clean' => array_values(array_map(function (string $reference): string {
                    [$kind, $id] = explode(':', $reference);

                    return $reference.'  ('.['flow' => 'flows', 'table' => 'tables', 'app' => 'apps', 'schedule' => 'schedules'][$kind].'/resource--'.$id.'.yaml)';
                }, array_keys(array_diff_key($state->bodies, array_fill_keys(array_filter([$state->missing_metadata]), true)))))]];
            }
            if ($args[0] === 'list') {
                if ($args[1] === $state->denied_list) {
                    throw new RuntimeException('Área sem acesso no ensaio.', 6);
                }
                if ($args[1] === 'variables') {
                    return ['data' => ['variables' => [], 'count' => 0]];
                }
                $rows = [];
                foreach ($state->bodies as $ref => $body) {
                    [$kind, $id] = explode(':', $ref, 2);
                    if ($kind === $args[1]) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome'], ...array_intersect_key($body, ['deskId' => true])];
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'pull') {
                if (in_array('--discard-local', $args, true)) {
                    foreach ($state->bodies as $ref => $body) {
                        if ($ref !== $state->missing_metadata) {
                            $this->write($project, $ref, $body);
                        }
                    }
                } else {
                    if ($state->local_changed) {
                        throw new RuntimeException('Edições locais pendentes; sem sobrescrita.', 1);
                    }
                    $this->write($project, $args[1], $state->bodies[$args[1]]);
                }

                return ['data' => []];
            }
            if ($args[0] === 'info') {
                $body = $state->bodies[$args[1]] ?? null;
                if ($body === null) {
                    throw new RuntimeException('Recurso ausente no teste.', $state->lookup_denied ? 6 : 5);
                }

                return ['data' => ['payload' => $args[1] === 'app:9' ? ['header' => ['kind' => 'app', 'id' => 9, 'tenantId' => 100], 'body' => $body] : ['id' => (int) explode(':', $args[1], 2)[1], 'nome' => $body['nome'], ...array_intersect_key($body, ['deskId' => true])]]];
            }
            if ($args[0] === 'diff') {
                return ['data' => ['workingVsServer' => ['equal' => true]]];
            }
            if ($args === ['rm', 'flow:2', '--force']) {
                $state->sent++;
                if ($state->unconfirmed_before_removal ?? false) {
                    throw new RuntimeException('Resposta interrompida sem confirmação do envio.', 7);
                }
                if ($state->refused) {
                    throw new RuntimeException('Recusado por dependência.', 13);
                }
                unset($state->bodies['flow:2'], $state->bodies['table:601']);
                if (! $state->keep_table) {
                    unset($state->bodies['table:600']);
                }
                File::delete(app(WorkspaceFiles::class)->path($project).'/flows/resource--2.yaml');
                if ($state->lost_response) {
                    throw new RuntimeException('Resposta interrompida depois da remoção.', 7);
                }

                return ['exit_code' => 0, 'data' => ['status' => 'success']];
            }
            throw new RuntimeException('Comando inesperado no teste: '.json_encode($args));
        });
    }

    private function input(): array
    {
        return ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()];
    }

    private function prepare(User $user, ?array $data = null): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $data ?? $this->input())->assertCreated();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_deletion_requires_authentication_role_and_own_recorded_draft(): void
    {
        $this->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnauthorized();
        [$viewer] = $this->context('viewer');
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($viewer)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertForbidden();
        $other = User::factory()->create();
        Connection::create(['user_id' => $other->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => []], 'metadata' => ['tenants' => [['id' => '100', 'name' => 'Homologação']]]]);
        $this->actingAs($other)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_review_is_idempotent_lists_all_owned_tables_and_keeps_queries_out_of_the_payload(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $input = $this->input();
        $approval = $this->prepare($user, $input);

        $this->postJson('/api/catalog/100/flows/2/actions', $input)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);
        $this->assertSame(['table:600', 'table:601'], array_column($approval->payload['revision']['flow_deletion']['tables'], 'resource'));
        $this->assertFalse($approval->payload['revision']['flow_deletion']['cascade']);
        $this->assertStringNotContainsString('synthetic_private_query', json_encode($approval->payload));
        $this->assertSame('flow:2', $project->fresh()->source_ref);
        $this->assertSame(0, $state->sent);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('dependencies')]
    public function test_dependencies_and_incomplete_inventory_prevent_preparation(string $case): void
    {
        [$user,, , $state] = $this->context();
        match ($case) {
            'application' => $state->bodies['app:9']['tables'] = [['tableId' => 601]],
            'schedule' => $state->bodies['schedule:8']['flows'] = [['flow_etl_id' => 2]],
            'metadata' => $state->missing_metadata = 'table:500',
            'denied' => $state->denied_list = 'schedule',
            'foreign-table' => $state->bodies['table:600']['originFlowId'] = 1,
            'published-table' => $state->bodies['table:601']['deskId'] = 70,
            'published-flow' => $state->bodies['flow:2']['deskId'] = 70,
            'unknown-bindings' => $state->bodies['app:9']['tables'] = [['tableId' => 'not-an-id']],
            'local-edits' => $state->local_changed = true,
        };
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->sent);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'draft']);
    }

    public static function dependencies(): array
    {
        return array_combine($values = ['application', 'schedule', 'metadata', 'denied', 'foreign-table', 'published-table', 'published-flow', 'unknown-bindings', 'local-edits'], array_map(fn ($value) => [$value], $values));
    }

    public function test_authorized_deletion_executes_once_checks_every_target_and_preserves_a_git_checkpoint(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $saved = $approval->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertTrue($saved->payload['execution']['result']['deletion']['verified']);
        $this->assertSame(['table:600', 'table:601'], $saved->payload['execution']['result']['deletion']['table_refs']);
        $this->assertSame(1, $state->sent);
        $this->assertNull($project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'deleted']);
        $this->assertArrayHasKey('flow:1', $state->bodies);
        $this->assertArrayHasKey('table:500', $state->bodies);
        $root = app(WorkspaceFiles::class)->path($project);
        $process = new Process(['git', 'show', $saved->payload['deletion_attempt']['checkpoint'].':flows/resource--2.yaml'], $root);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('synthetic_private_query', $process->getOutput());
        $this->assertFileDoesNotExist($root.'/flows/resource--2.yaml');
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_new_dependency_after_review_prevents_the_delete_before_send(): void
    {
        Queue::fake();
        [$user,, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->bodies['app:9']['tables'][] = ['tableId' => 600];

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertArrayNotHasKey('deletion_attempt', $approval->fresh()->payload);
        $this->assertSame(0, $state->sent);
    }

    public function test_changed_configuration_after_review_requires_a_new_authorization(): void
    {
        Queue::fake();
        [$user,, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->bodies['table:601']['columns']['NEW (String)'] = ['label' => 'Novo'];

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->sent);
    }

    public function test_native_dependency_refusal_never_adds_cascade_or_claims_deletion(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->refused = true;

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('dependências', $approval->fresh()->payload['execution']['message']);
        $this->assertSame(1, $state->sent);
        $this->assertSame('flow:2', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'draft']);
        foreach ($state->commands as $command) {
            $this->assertNotContains('--cascade', $command);
        }
    }

    #[DataProvider('uncertainResults')]
    public function test_uncertain_native_results_remain_pending_without_a_second_delete(string $case): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->{$case} = true;

        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'deletion_pending']);
        $this->assertSame('flow:2', $project->fresh()->source_ref);
        $this->assertSame(1, $state->sent);
    }

    public static function uncertainResults(): array
    {
        return ['retained table' => ['keep_table'], 'missing permission' => ['lookup_denied'], 'interrupted receipt' => ['lost_response']];
    }

    public function test_interrupted_receipt_can_be_reconciled_by_reading_without_resending_or_starting_ai(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->lost_response = true;
        $this->execute($user, $approval);

        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.deletion.verified', true);

        $this->assertSame(1, $state->sent);
        $this->assertNull($project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'deleted']);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_reconciliation_is_private_and_requires_a_real_authorized_attempt(): void
    {
        Queue::fake();
        [$user,, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertUnprocessable();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertNotFound();

        $this->assertSame(0, $state->sent);
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_deleted_tables_cannot_be_recreated_by_pushing_retained_files(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'table:600', 'status' => 'deleted']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não recrie um recurso a partir de arquivos antigos');

        app(ActionBroker::class)->command(['action' => 'push', 'resource' => 'table:600'], $project);
    }

    public function test_unauthorized_tenant_is_refused_before_reading_native_data(): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->actingAs($user)->postJson('/api/catalog/200/flows/2/actions', $this->input())->assertForbidden();

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_agent_can_confirm_its_project_deletion_without_sending_another_delete(): void
    {
        Queue::fake();
        [$user,, $project, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->lost_response = true;
        $this->execute($user, $approval);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Conferência']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);

        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'deletion_status', 'approval_id' => $approval->id]);

        $this->assertSame('confirmed', $result['status']);
        $this->assertTrue($result['remote_read_only']);
        $this->assertTrue($result['deletion']['verified']);
        $this->assertSame(1, $state->sent);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_agent_cannot_confirm_a_deletion_from_another_project(): void
    {
        Queue::fake();
        [$user,, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $other = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Outro projeto']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $other->id, 'title' => 'Outro contexto']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $this->expectException(ModelNotFoundException::class);

        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'deletion_status', 'approval_id' => $approval->id]);
    }

    public function test_a_new_clone_cannot_be_prepared_while_the_source_deletion_is_unconfirmed(): void
    {
        Queue::fake();
        [$user,, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->unconfirmed_before_removal = true;
        $this->execute($user, $approval);

        $this->postJson('/api/catalog/100/flows/2/actions', ['action' => 'clone_flow', 'name' => 'Outra cópia', 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('operation');

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(1, $state->sent);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:2', 'status' => 'deletion_pending']);
    }

    public static function retainedDependencies(): array
    {
        return ['application' => ['app:9'], 'table' => ['table:500'], 'schedule' => ['schedule:8']];
    }

    #[DataProvider('retainedDependencies')]
    public function test_old_dependency_files_cannot_authorize_deletion_after_they_leave_the_clean_list(string $reference): void
    {
        [$user, $connection, , $state] = $this->context();
        $context = app(BiCatalog::class)->context($connection, ['id' => '100', 'name' => 'Homologação']);
        $retained = $this->write($context, $reference, $state->bodies[$reference]);
        $hash = hash_file('sha256', $retained);
        $state->missing_metadata = $reference;
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/flows/2/actions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('operation');

        $this->assertSame(0, $state->sent);
        $this->assertSame($hash, hash_file('sha256', $retained));
        $this->assertDatabaseCount('approvals', 0);
    }
}
