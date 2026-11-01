<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
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
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiAppDeletionsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $root = storage_path('framework/testing/app-deletions-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $user = User::factory()->create();
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Homologação']]]]);
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Cópia de teste', 'source_ref' => 'app:2']);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2', 'origin' => 'clone_app', 'status' => 'draft', 'receipt' => []]);
        $state = (object) ['mode' => 'normal', 'rm' => 0, 'commands' => [], 'equal' => true, 'bodies' => [
            'app:1' => ['nome' => 'Original', 'deskId' => 80, 'tables' => [['tableId' => 500]], 'dashboards' => []],
            'app:2' => ['nome' => 'Cópia de teste', '_state' => 'draft', 'deskId' => null, 'tables' => [['tableId' => 500]], 'dashboards' => [['id' => 21, 'widgets' => [['id' => 31, 'code' => 'synthetic-private-code']]]]],
            'table:500' => ['nome' => 'Fonte preservada', 'deskId' => 70, 'originFlowId' => 1, 'columns' => ['VALUE (Number)' => ['label' => 'Valor']]],
            'flow:1' => ['nome' => 'ETL original', 'deskId' => 70],
            'schedule:8' => ['nome' => 'Agenda original', 'ativo' => true],
        ]];
        $path = app(WorkspaceFiles::class)->path($project).'/apps';
        File::ensureDirectoryExists($path, 0700, true);
        File::put($path.'/copy--2.yaml', Yaml::dump(['id' => 2, 'kind' => 'app', 'tenantId' => 100, 'lumo' => 'v2'])."---\n".Yaml::dump($state->bodies['app:2'], 30, 2));
        $this->native($state);

        return [$user, $project, $state];
    }

    private function native(object $state): void
    {
        $this->mock(LumoClient::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('initialize')->andReturnNull();
            $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, Project $project) use ($state): array {
                $state->commands[] = $args;
                if ($args[0] === 'list') {
                    if ($state->mode === 'access_changed' && $state->rm > 0) {
                        throw new RuntimeException('Synthetic access refused', 6);
                    }
                    $rows = [];
                    foreach ($state->bodies as $ref => $body) {
                        if (str_starts_with($ref, $args[1].':')) {
                            $rows[] = ['id' => (int) explode(':', $ref)[1], 'nome' => $body['nome']];
                        }
                    }

                    return ['data' => ['results' => $rows, 'matched' => count($rows)]];
                }
                if ($args[0] === 'info') {
                    if ($state->mode === 'access_changed' && $state->rm > 0) {
                        throw new RuntimeException('Synthetic access refused', 6);
                    }
                    if (! isset($state->bodies[$args[1]])) {
                        throw new RuntimeException('Synthetic missing resource', 5);
                    }
                    [$kind, $id] = explode(':', $args[1]);

                    $body = $state->bodies[$args[1]];
                    if ($kind === 'app' && ($state->info_enriched ?? false)) {
                        $body['facts'] = $body['indicators'] ?? [];
                        unset($body['indicators']);
                        $body['tables'][0]['columns'] = [['column_name' => 'VALUE', 'data_type' => 'Number']];
                        $body['tables'][0]['nome'] = 'Nome derivado da tabela';
                        $body['expressions'][0]['qualifiedName'] = 'Total qualificado';
                        $body['expressions'][0]['dataSource'] = 'table:500';
                    }
                    if ($kind === 'app' && isset($state->info_table_id)) {
                        $body['tables'][0]['tableId'] = $state->info_table_id;
                    }
                    if ($kind === 'app' && isset($state->info_name)) {
                        $body['nome'] = $state->info_name;
                    }
                    if ($kind === 'app' && ($state->info_dashboard_extra ?? false)) {
                        $body['dashboards'][] = ['id' => 99, 'widgets' => []];
                    }

                    return ['data' => ['payload' => ['header' => ['id' => $state->header_id ?? (int) $id, 'kind' => $state->header_kind ?? $kind, 'tenantId' => $state->header_tenant ?? 100], 'body' => $body]]];
                }
                if ($args[0] === 'pull') {
                    $this->assertSame(['pull', 'app:2'], $args);
                    $path = app(WorkspaceFiles::class)->path($project).'/apps';
                    File::ensureDirectoryExists($path, 0700, true);
                    File::put($path.'/copy--2.yaml', Yaml::dump(['id' => 2, 'kind' => 'app', 'tenantId' => 100, 'lumo' => 'v2'])."---\n".Yaml::dump($state->bodies['app:2'], 30, 2));

                    return ['data' => []];
                }
                if ($args[0] === 'diff') {
                    $this->assertSame(['diff', 'app:2', '--include-remote'], $args);

                    return ['data' => ['workingVsServer' => ['equal' => $state->equal]]];
                }
                if ($args === ['rm', 'app:2', '--force']) {
                    $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('resource', 'app:2')->firstOrFail();
                    $this->assertSame('deletion_pending', $managed->status);
                    $approval = Approval::where('project_id', $project->id)->where('status', 'executing')->firstOrFail();
                    $this->assertSame($approval->id, $managed->receipt['deletion_attempt']['approval_id']);
                    $this->assertNotEmpty($approval->payload['deletion_attempt']['checkpoint']);
                    $state->rm++;
                    if ($state->mode === 'dependency') {
                        throw new RuntimeException('Synthetic dependency', 13);
                    }
                    if ($state->mode === 'timeout_before') {
                        throw new RuntimeException('Synthetic uncertain transmission', 7);
                    }
                    if ($state->mode !== 'accepted_but_present') {
                        unset($state->bodies['app:2']);
                    }
                    if ($state->mode === 'table_missing') {
                        unset($state->bodies['table:500']);
                    }
                    if ($state->mode === 'timeout_after') {
                        throw new RuntimeException('Synthetic uncertain confirmation', 7);
                    }

                    return ['exit_code' => 0, 'data' => ['removed' => 'app:2']];
                }
                throw new RuntimeException('Unexpected synthetic command: '.json_encode($args));
            });
        });
    }

    private function prepare(User $user): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertCreated();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->refresh();
    }

    public function test_derived_info_fields_do_not_reject_a_synced_canonical_application(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $state->bodies['app:2']['indicators'] = [];
        $state->bodies['app:2']['expressions'] = [['name' => 'Total', 'expression' => 'SUM(VALUE)']];
        $state->info_enriched = true;

        $approval = $this->prepare($user);

        $this->assertSame('pending', $approval->status);
        $this->assertSame(['table:500'], $approval->payload['revision']['app_deletion']['preserved_table_refs']);
        $this->assertSame(0, $state->rm);
        $this->assertStringNotContainsString('Nome derivado da tabela', json_encode($approval->payload));
        Queue::assertNothingPushed();

        $approval = $this->execute($user, $approval);

        $this->assertSame('completed', $approval->status);
        $this->assertTrue($approval->payload['execution']['result']['deletion']['verified']);
        $this->assertSame(1, $state->rm);
        $this->assertArrayHasKey('table:500', $state->bodies);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    #[DataProvider('infoProjectionMismatches')]
    public function test_disagreeing_effect_metadata_is_not_hidden_as_derived_info_fields(string $change): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        match ($change) {
            'tables' => $state->info_table_id = 999, 'name' => $state->info_name = 'Outro nome', 'dashboards' => $state->info_dashboard_extra = true
        };

        $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->rm);
        Queue::assertNothingPushed();
    }

    public static function infoProjectionMismatches(): array
    {
        return [['tables'], ['name'], ['dashboards']];
    }

    public function test_native_acceptance_does_not_confirm_an_application_that_is_still_present(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $state->mode = 'accepted_but_present';
        $approval = $this->prepare($user);

        $approval = $this->execute($user, $approval);

        $this->assertSame('failed', $approval->status);
        $this->assertSame('app:2', $project->fresh()->source_ref);
        $this->assertSame(1, $state->rm);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'deletion_pending']);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->rm);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_the_review_is_scoped_and_confirmed_deletion_preserves_all_sources(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $original = $state->bodies;
        $approval = $this->prepare($user);
        $plan = $approval->payload['revision']['app_deletion'];
        $this->assertSame('app', $plan['kind']);
        $this->assertSame(1, $plan['dashboard_count']);
        $this->assertSame(['table:500'], $plan['preserved_table_refs']);
        $this->assertSame([], $plan['tables']);
        $this->assertFalse($plan['cascade']);
        $this->assertStringNotContainsString('synthetic-private-code', json_encode($approval->payload));
        $this->assertSame(0, $state->rm);

        $approval = $this->execute($user, $approval);

        $this->assertSame('completed', $approval->status);
        $this->assertTrue($approval->payload['execution']['result']['deletion']['verified']);
        $this->assertSame('app', $approval->payload['execution']['result']['deletion']['kind']);
        unset($original['app:2']);
        $this->assertSame($original, $state->bodies);
        $this->assertNull($project->fresh()->source_ref);
        $this->assertSame(1, $state->rm);
        $this->assertNotEmpty($approval->payload['deletion_attempt']['checkpoint']);
        $this->assertDirectoryExists(app(WorkspaceFiles::class)->path($project).'/.git');
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'deleted']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    #[DataProvider('uncertainResults')]
    public function test_an_uncertain_application_removal_can_be_checked_without_another_delete(string $mode): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $state->mode = $mode;
        $approval = $this->execute($user, $this->prepare($user));
        $this->assertSame('failed', $approval->status);
        $this->assertArrayHasKey('deletion_attempt', $approval->payload);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'deletion_pending']);
        $state->mode = 'normal';

        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertOk()->assertJsonPath('approval.status', 'completed');

        $this->assertSame(1, $state->rm);
        $this->assertSame('completed', $approval->refresh()->status);
        $this->assertTrue($approval->payload['reconciliation']['remote_read_only']);
        $this->assertArrayHasKey('table:500', $state->bodies);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function uncertainResults(): array
    {
        return [['timeout_after'], ['access_changed']];
    }

    public function test_a_timeout_with_the_application_still_present_blocks_new_writes_until_confirmation(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $state->mode = 'timeout_before';
        $approval = $this->execute($user, $this->prepare($user));
        $this->assertSame('failed', $approval->status);
        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertUnprocessable();
        $this->postJson('/api/catalog/100/apps/2/actions', ['action' => 'clone_app', 'name' => 'Outra cópia', 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/catalog/100/apps/2/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
        unset($state->bodies['app:2']);
        $state->mode = 'normal';

        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertOk()->assertJsonPath('approval.status', 'completed');

        $this->assertSame(1, $state->rm);
        $this->assertTrue($approval->refresh()->payload['reconciliation']['remote_read_only']);
        $this->assertArrayHasKey('table:500', $state->bodies);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_a_missing_source_prevents_confirmation_without_deleting_or_recreating_it(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $table = $state->bodies['table:500'];
        $state->mode = 'table_missing';
        $approval = $this->execute($user, $this->prepare($user));
        $this->assertSame('failed', $approval->status);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'deletion_pending']);
        $state->bodies['table:500'] = $table;
        $state->mode = 'normal';

        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertOk()->assertJsonPath('approval.status', 'completed');

        $this->assertSame(1, $state->rm);
        $this->assertSame($table, $state->bodies['table:500']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    #[DataProvider('invalidIdentity')]
    public function test_unconfirmed_identity_or_private_state_returns_422_before_preparing_a_removal(string $change): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        match ($change) {
            'tenant' => $state->header_tenant = 200,
            'kind' => $state->header_kind = 'flow',
            'id' => $state->header_id = 99,
            'published' => $state->bodies['app:2']['deskId'] = 80,
            'unknown state' => $state->bodies['app:2'] = array_diff_key($state->bodies['app:2'], ['deskId' => true, '_state' => true]),
            'drift' => $state->equal = false,
        };

        $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'delete_draft', 'request_id' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertSame(0, $state->rm);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'draft']);
        Queue::assertNothingPushed();
    }

    public static function invalidIdentity(): array
    {
        return array_map(fn (string $case): array => [$case], ['tenant', 'kind', 'id', 'published', 'unknown state', 'drift']);
    }

    public function test_a_known_dependency_refusal_keeps_the_draft_and_its_sources(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $original = $state->bodies;
        $state->mode = 'dependency';

        $approval = $this->execute($user, $this->prepare($user));

        $this->assertSame('failed', $approval->status);
        $this->assertStringContainsString('dependências', $approval->payload['execution']['message']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'draft']);
        $this->assertSame('app:2', $project->fresh()->source_ref);
        $this->assertSame($original, $state->bodies);
        $this->assertSame(1, $state->rm);
        $commands = $state->commands;
        unset($state->bodies['app:2']);
        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertConflict();
        $this->assertSame('failed', $approval->refresh()->status);
        $this->assertSame($commands, $state->commands);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_deleting_an_owned_application_does_not_clear_a_different_current_project_resource(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $project->update(['source_ref' => 'flow:1']);

        $approval = $this->execute($user, $this->prepare($user));

        $this->assertSame('completed', $approval->status);
        $this->assertSame('flow:1', $project->fresh()->source_ref);
        $this->assertArrayHasKey('flow:1', $state->bodies);
        $this->assertSame(1, $state->rm);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_a_legacy_review_without_the_durable_plan_requires_a_new_authorization(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $approval = $this->prepare($user);
        $payload = $approval->payload;
        unset($payload['revision']['app_deletion']);
        $fingerprint = hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $payload['action'], $payload['revision']], JSON_THROW_ON_ERROR));
        $approval->update(['payload' => $payload, 'fingerprint' => $fingerprint]);

        $approval = $this->execute($user, $approval);

        $this->assertSame('failed', $approval->status);
        $this->assertArrayNotHasKey('deletion_attempt', $approval->payload);
        $this->assertSame(0, $state->rm);
        $this->assertSame('app:2', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'draft']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_a_superseded_attempt_cannot_claim_a_later_removal_as_its_result(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $project, $state] = $this->context();
        $state->mode = 'timeout_after';
        $approval = $this->execute($user, $this->prepare($user));
        $managed = ManagedBiResource::where('project_id', $project->id)->where('resource', 'app:2')->firstOrFail();
        $receipt = $managed->receipt;
        $receipt['deletion_attempt']['approval_id'] = (string) Str::uuid();
        $managed->update(['receipt' => $receipt]);
        $commands = $state->commands;

        $this->postJson('/api/approvals/'.$approval->id.'/deletion/verify')->assertConflict();

        $this->assertSame('failed', $approval->refresh()->status);
        $this->assertSame(1, $state->rm);
        $this->assertSame($commands, $state->commands);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_a_later_edit_requires_a_new_review_before_the_native_removal(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user);
        $state->bodies['app:2']['nome'] = 'Uma edição posterior';

        $approval = $this->execute($user, $approval);

        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->rm);
        $this->assertArrayNotHasKey('deletion_attempt', $approval->payload);
        $this->assertSame('Uma edição posterior', $state->bodies['app:2']['nome']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }
}
