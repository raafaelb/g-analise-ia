<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiBusy;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiFlowWizardTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private object $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/flow-wizard-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(string $type = 'postgresql'): User
    {
        $user = User::factory()->create();
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Ambiente autorizado']]]]);
        $this->state = (object) ['bodies' => ['credential:11' => ['nome' => 'Fonte disponível', 'type' => $type, 'agentId' => 7, 'key' => 'SYNTHETIC-PRIVATE-REFERENCE', 'config' => ['password' => 'SYNTHETIC-PRIVATE-PASSWORD']], 'flow:10' => ['nome' => 'ETL preexistente', 'deskId' => 6, 'tokenId' => 7, 'load_type' => 'Total', 'nodes' => ['Processors' => []]], 'table:500' => ['nome' => 'Tabela preexistente', 'deskId' => 6]], 'commands' => [], 'flows_created' => 0, 'tables_created' => 0, 'runs' => [], 'loads' => 0, 'fail_push' => false, 'fail_after_push' => false, 'query' => null, 'queued' => false, 'change_loaded_schema' => false, 'empty_preview' => false, 'callback' => null];
        $this->mock(LumoClient::class)->shouldReceive('initialize')->andReturnNull();
        app(LumoClient::class)->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args, Project $context): array {
            $s = $this->state;
            $s->commands[] = $args;
            if ($s->callback) {
                ($s->callback)($connection, $args, $context);
            }
            if ($args[0] === 'list') {
                $rows = $args[1] === 'agent' ? [['id' => 7, 'nome' => 'Agente disponível']] : [];
                foreach ($s->bodies as $ref => $body) {
                    if (str_starts_with($ref, $args[1].':')) {
                        $rows[] = ['id' => (int) explode(':', $ref)[1], 'nome' => $body['nome']];
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'pull') {
                $this->write($context, $args[1]);

                return ['data' => []];
            }
            if ($args[0] === 'info') {
                return ['data' => ['id' => (int) explode(':', $args[1])[1], ...$s->bodies[$args[1]]]];
            }
            if (array_slice($args, 0, 2) === ['credential', 'query']) {
                if ($s->query) {
                    return ['data' => ['rows' => ($s->query)($args[4])]];
                }
                $rows = array_map(fn (string $name, int $position): array => ['schema_name' => 'public', 'table_name' => 'orders', 'column_name' => $name, 'data_type' => $position === 1 ? 'integer' : 'numeric', 'is_nullable' => 'NO', 'ordinal_position' => $position, 'visible_column_count' => 2], ['id', 'amount'], [1, 2]);

                return ['data' => ['rows' => $rows]];
            }
            if (array_slice($args, 0, 2) === ['new', 'flow']) {
                $s->flows_created++;
                $s->bodies['flow:99'] = ['nome' => $args[3], 'deskId' => null, '_state' => 'draft', 'tokenId' => 7, 'load_type' => 'Total', 'nodes' => ['Processors' => [], 'Connections' => []]];
                $this->write($context, 'flow:99');

                return ['data' => ['resource' => ['kind' => 'flow', 'id' => 99]]];
            }
            if ($args[0] === 'lint') {
                return ['data' => ['valid' => true]];
            }
            if ($args[0] === 'push') {
                if ($s->fail_push) {
                    throw new RuntimeException('Falha sintética ao enviar a configuração.');
                }
                $s->bodies[$args[1]] = $this->working($context, $args[1]);
                if ($s->fail_after_push) {
                    throw new RuntimeException('Falha sintética da confirmação após envio.');
                }

                return ['data' => ['saved' => true]];
            }
            if ($args[0] === 'diff') {
                return ['data' => ['workingVsServer' => ['equal' => $this->working($context, $args[1]) === $s->bodies[$args[1]]]]];
            }
            if (array_slice($args, 0, 2) === ['flow', 'run-node']) {
                $flow = &$s->bodies[$args[2]];
                $node = collect($flow['nodes']['Processors'])->firstWhere('internalId', $args[4]);
                $s->runs[] = $node['kind'];
                if ($node['kind'] === 'InsertDatawarehouse') {
                    $s->loads++;
                }
                $flow['nodes']['Processors'][0]['metadata'] = ['File' => 'private-fixture-'.count($s->runs).'.parquet', 'Fields' => [['columnName' => 'ID', 'dataType' => 'Int32'], ['columnName' => 'AMOUNT', 'dataType' => 'Decimal']]];

                if ($node['kind'] === 'InsertDatawarehouse' && $s->change_loaded_schema) {
                    $flow['nodes']['Processors'][0]['metadata']['Fields'][] = ['columnName' => 'CHANGED', 'dataType' => 'Int32'];
                }

                return ['data' => ['state' => $s->queued ? 'queued' : 'finished', 'node' => $args[4]]];
            }
            if (array_slice($args, 0, 2) === ['new', 'table']) {
                $s->tables_created++;
                $s->bodies['table:501'] = ['nome' => 'ETL de teste', 'deskId' => null, 'flow_etl_id' => 99, 'columns' => ['ID (INT)' => ['label' => 'ID'], 'AMOUNT (DECIMAL)' => ['label' => 'AMOUNT']]];
                $s->bodies['flow:99']['nodes']['Processors'][1]['options']['TableID'] = 501;
                $this->write($context, 'table:501');
                $this->write($context, 'flow:99');

                return ['data' => ['resource' => ['kind' => 'table', 'id' => 501]]];
            }
            if (array_slice($args, 0, 2) === ['table', 'preview']) {
                return ['data' => ['rows' => $s->empty_preview ? [] : [['ID' => 1, 'AMOUNT' => 15, 'PRIVATE_SAMPLE' => 'SYNTHETIC-BUSINESS-ROW']]]];
            }
            throw new RuntimeException('Comando inesperado no limite nativo do teste.');
        });

        return $user;
    }

    private function path(Project $project, string $ref): string
    {
        [$kind, $id] = explode(':', $ref);
        $folder = ['flow' => 'flows', 'table' => 'tables', 'credential' => 'credentials'][$kind];

        return app(WorkspaceFiles::class)->path($project).'/'.$folder.'/fixture--'.$id.'.yaml';
    }

    private function write(Project $project, string $ref): void
    {
        [$kind, $id] = explode(':', $ref);
        $path = $this->path($project, $ref);
        File::ensureDirectoryExists(dirname($path), 0700, true);
        File::put($path, Yaml::dump(['lumo' => 'v2', 'kind' => $kind, 'id' => (int) $id, 'tenantId' => (int) $project->tenant_id])."---\n".Yaml::dump($this->state->bodies[$ref], 30, 2));
    }

    private function working(Project $project, string $ref): array
    {
        return Yaml::parse(preg_split('/^---\s*$/m', File::get($this->path($project, $ref)), 2)[1]);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['action' => 'create_source_flow', 'name' => 'ETL de teste', 'source' => ['credential' => 'credential:11', 'schema' => 'public', 'table' => 'orders', 'columns' => ['amount', 'id'], 'row_limit' => 10], 'request_id' => (string) Str::uuid()], $changes);
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/flow-wizard/actions', $input)->assertOk()->assertJsonPath('approval.status', 'pending');

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->fresh();
    }

    private function follow(User $user, Approval $creation, string $action): Approval
    {
        return $this->prepare($user, ['action' => $action, 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()]);
    }

    public function test_creation_is_reviewed_without_running_queries_of_business_records_or_exposing_secrets(): void
    {
        $user = $this->context();
        $approval = $this->prepare($user, $this->input());
        $this->assertSame(0, $this->state->flows_created);
        $this->assertSame([], $this->state->runs);
        $this->assertSame('SELECT "id", "amount" FROM "public"."orders" LIMIT 10', $approval->payload['revision']['flow_wizard']['sql']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', $approval->toJson());
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('projects', 1);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_four_separate_reviews_create_extract_bind_and_load_only_the_new_private_destination(): void
    {
        $user = $this->context();
        $originals = [$this->state->bodies['flow:10'], $this->state->bodies['table:500']];
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->assertSame('completed', $creation->status, $creation->payload['execution']['message'] ?? '');
        $this->assertSame(1, $this->state->flows_created);
        $this->assertSame(0, $this->state->loads);
        $this->assertSame('flow:99', Project::findOrFail($creation->project_id)->source_ref);
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->assertSame('completed', $test->status, $test->payload['execution']['message'] ?? '');
        $this->assertSame(2, $test->payload['execution']['result']['flow_wizard']['captured_fields']);
        $this->assertSame(0, $this->state->tables_created);
        $table = $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $this->assertSame('completed', $table->status, $table->payload['execution']['message'] ?? '');
        $this->assertSame(1, $this->state->tables_created);
        $this->assertSame(0, $this->state->loads);
        $load = $this->execute($user, $this->follow($user, $creation, 'load_source_table'));
        $this->assertSame('completed', $load->status, $load->payload['execution']['message'] ?? '');
        $this->assertTrue($load->payload['execution']['result']['flow_wizard']['data_check']['preview_confirmed']);
        $this->assertSame(1, $this->state->loads);
        (new ExecuteApprovedAction($load->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $this->state->loads);
        $this->assertSame($originals, [$this->state->bodies['flow:10'], $this->state->bodies['table:500']]);
        $this->assertDatabaseCount('approvals', 4);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertStringNotContainsString('SYNTHETIC-BUSINESS-ROW', Approval::all()->toJson().AuditEvent::all()->toJson());
        $this->assertFalse(collect($this->state->commands)->contains(fn (array $args): bool => in_array($args[1] ?? '', ['publish', 'run'], true)));
    }

    public function test_request_identity_reuses_the_same_review_and_rejects_different_content(): void
    {
        $user = $this->context();
        $input = $this->input();
        $review = $this->prepare($user, $input);
        $this->actingAs($user)->postJson('/api/catalog/100/flow-wizard/actions', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('approval.id', $review->id);
        $this->postJson('/api/catalog/100/flow-wizard/actions', [...$input, 'name' => 'Outro pedido'])->assertConflict();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
    }

    public function test_rejection_and_expiry_never_create_an_etl(): void
    {
        $user = $this->context();
        $review = $this->prepare($user, $this->input());
        $this->postJson('/api/approvals/'.$review->id.'/decide', ['decision' => 'rejected', 'fingerprint' => $review->fingerprint])->assertOk();
        (new ExecuteApprovedAction($review->id))->handle(app(ActionBroker::class));
        $expired = $this->prepare($user, $this->input());
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/approvals/'.$expired->id.'/decide', ['decision' => 'approved', 'fingerprint' => $expired->fingerprint])->assertConflict();
        $this->assertSame(0, $this->state->flows_created);
    }

    public function test_private_creation_receipt_survives_a_failed_push_without_recreating_the_flow(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $review = $this->execute($user, $this->prepare($user, $this->input()));
        (new ExecuteApprovedAction($review->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $review->status);
        $this->assertSame('flow:99', $review->payload['flow_wizard_attempt']['resource']);
        $this->assertSame(1, $this->state->flows_created);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:99', 'status' => 'config_pending']);
        $this->assertSame(0, $this->state->loads);
    }

    public function test_a_queued_source_is_not_a_successful_capture_and_cannot_create_a_table(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->state->queued = true;
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->assertSame('failed', $test->status);
        $this->actingAs($user)->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'create_source_table', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, $this->state->tables_created);
    }

    public function test_schema_sql_cannot_be_supplied_as_an_unreviewed_source_parameter(): void
    {
        $user = $this->context();
        $input = $this->input();
        $input['source']['sql'] = 'DELETE FROM customers';
        $this->actingAs($user)->postJson('/api/catalog/100/flow-wizard/actions', $input)->assertUnprocessable();
        $this->assertSame([], $this->state->commands);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_guests_readers_other_tenants_and_other_project_owners_cannot_use_the_wizard(): void
    {
        $user = $this->context();
        $this->getJson('/api/catalog/100/flow-wizard/options')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->getJson('/api/catalog/100/flow-wizard/options')->assertForbidden();
        $this->actingAs($user)->postJson('/api/catalog/200/flow-wizard/actions', $this->input())->assertForbidden();
        $other = Project::create(['user_id' => User::factory()->create()->id, 'tenant_id' => '100', 'name' => 'Outro projeto']);
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'test_source_flow', 'project_id' => $other->id, 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->getJson('/api/projects/'.$other->id.'/flow-wizard')->assertNotFound();
        $this->assertSame(0, $this->state->flows_created);
    }

    public function test_a_selected_credential_is_listed_and_its_fields_are_checked_without_exposing_its_configuration(): void
    {
        $user = $this->context();
        $options = $this->actingAs($user)->getJson('/api/catalog/100/flow-wizard/options')->assertOk()->assertJsonPath('credentials.0.ref', 'credential:11');
        $fields = $this->postJson('/api/catalog/100/flow-wizard/fields', ['credential' => 'credential:11', 'schema' => 'public', 'table' => 'orders'])->assertOk()->assertJsonCount(2, 'table.columns');
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', $options->getContent().$fields->getContent());
        $query = collect($this->state->commands)->last(fn (array $args): bool => array_slice($args, 0, 2) === ['credential', 'query'])[4];
        $this->assertStringContainsString("c.table_name = 'orders'", $query);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_changed_connection_configuration_invalidates_the_review_without_creating_a_flow(): void
    {
        $user = $this->context();
        $review = $this->prepare($user, $this->input());
        $this->state->bodies['credential:11']['config']['password'] = 'SYNTHETIC-CHANGED';
        $result = $this->execute($user, $review);
        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $this->state->flows_created);
        $this->assertSame([], $this->state->runs);
    }

    public function test_an_uncertain_table_attempt_cannot_be_resent_as_a_new_review(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->state->callback = function (Connection $connection, array $args): void {
            if (array_slice($args, 0, 2) === ['new', 'table']) {
                throw new RuntimeException('Resposta indisponível depois do início do envio.');
            }
        };
        $table = $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $this->assertSame('failed', $table->status);
        $this->state->callback = null;
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'create_source_table', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('flow');
        $this->assertSame(0, $this->state->tables_created);
    }

    public function test_source_or_destination_changes_prevent_loading_existing_resources(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $this->state->bodies['flow:99']['nodes']['Processors'][1]['options']['TableID'] = 500;
        $this->write(Project::findOrFail($creation->project_id), 'flow:99');
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'load_source_table', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, $this->state->loads);
    }

    public function test_revoked_access_and_modified_review_payload_never_create_a_flow(): void
    {
        $user = $this->context();
        $review = $this->prepare($user, $this->input());
        $payload = $review->payload;
        $payload['revision']['flow_wizard']['sql'] = 'SELECT * FROM another_table';
        $review->update(['payload' => $payload]);
        $tampered = $this->execute($user, $review);
        $this->assertSame('failed', $tampered->status);
        $next = $this->prepare($user, $this->input());
        $this->postJson('/api/approvals/'.$next->id.'/decide', ['decision' => 'approved', 'fingerprint' => $next->fingerprint])->assertOk();
        $user->forceFill(['role' => 'viewer'])->save();
        (new ExecuteApprovedAction($next->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $next->fresh()->status);
        $this->assertSame(0, $this->state->flows_created);
    }

    public function test_expired_unsubmitted_stage_can_prepare_a_new_revision_and_resume_from_its_project(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $stage = $this->follow($user, $creation, 'test_source_flow');
        $stage->update(['expires_at' => now()->subSecond()]);
        $replacement = $this->follow($user, $creation, 'test_source_flow');
        $this->assertNotSame($stage->id, $replacement->id);
        $this->getJson('/api/projects/'.$creation->project_id.'/flow-wizard')->assertOk()->assertJsonCount(3, 'steps')->assertJsonPath('flow', 'flow:99');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('projects.0.flow_wizard', true);
        $this->assertSame([], $this->state->runs);
    }

    public function test_a_schema_change_during_load_requires_conference_without_resending_the_load(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $review = $this->follow($user, $creation, 'load_source_table');
        $this->state->change_loaded_schema = true;
        $load = $this->execute($user, $review);
        $this->assertSame('failed', $load->status);
        $this->assertStringContainsString('esquema da fonte mudou', $load->payload['execution']['message']);
        (new ExecuteApprovedAction($load->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $this->state->loads);
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'load_source_table', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, $this->state->loads);
    }

    public function test_empty_table_preview_keeps_data_validation_pending_after_a_finished_command(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $this->state->empty_preview = true;
        $load = $this->execute($user, $this->follow($user, $creation, 'load_source_table'));
        $this->assertSame('completed', $load->status);
        $this->assertFalse($load->payload['execution']['result']['flow_wizard']['data_check']['preview_confirmed']);
        $this->assertSame(1, $this->state->loads);
    }

    public function test_selected_identifiers_are_quoted_instead_of_executed_as_sql_fragments(): void
    {
        $user = $this->context();
        $this->state->query = fn (): array => [['schema_name' => "odd'schema", 'table_name' => 'or"ders', 'column_name' => 'amount";DROP TABLE other;--', 'data_type' => 'numeric', 'is_nullable' => 'YES', 'ordinal_position' => 1, 'visible_column_count' => 1]];
        $input = $this->input();
        $input['source'] = ['credential' => 'credential:11', 'schema' => "odd'schema", 'table' => 'or"ders', 'columns' => ['amount";DROP TABLE other;--'], 'row_limit' => 3];
        $review = $this->prepare($user, $input);
        $this->assertSame('SELECT "amount"";DROP TABLE other;--" FROM "odd\'schema"."or""ders" LIMIT 3', $review->payload['revision']['flow_wizard']['sql']);
        $this->assertSame(0, $this->state->flows_created);
    }

    private function checkStep(User $user, Approval $approval): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/projects/'.$approval->project_id.'/flow-wizard/verify/'.$approval->id, ['fingerprint' => $approval->fingerprint]);
    }

    public function test_a_failed_confirmation_recovers_the_existing_configured_flow_without_another_push(): void
    {
        $user = $this->context();
        $this->state->fail_after_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->assertSame('failed', $creation->status);
        $before = $this->state->commands;
        $this->checkStep($user, $creation)->assertOk()->assertJsonPath('verification.verified', true)->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.flow_wizard_recovery.previous_message', 'Falha sintética da confirmação após envio.');
        $newCommands = array_slice($this->state->commands, count($before));
        $this->assertFalse(collect($newCommands)->contains(fn (array $args): bool => in_array($args[0], ['new', 'push'], true) || array_slice($args, 0, 2) === ['flow', 'run-node']));
        $this->assertSame(1, $this->state->flows_created);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'flow:99', 'status' => 'draft']);
        $this->assertSame([], $this->state->runs);
    }

    public function test_an_existing_empty_flow_is_completed_by_a_separate_review_without_creating_it_again(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->checkStep($user, $creation)->assertOk()->assertJsonPath('verification.state', 'needs_configuration')->assertJsonPath('approval.status', 'failed');
        $this->state->fail_push = false;
        $repair = $this->follow($user, $creation, 'configure_source_flow');
        $this->assertSame(1, $this->state->flows_created);
        $this->assertSame(0, $this->state->tables_created);
        $completed = $this->execute($user, $repair);
        $this->assertSame('completed', $completed->status, $completed->payload['execution']['message'] ?? '');
        $this->assertSame('completed', $creation->fresh()->status);
        $this->assertSame($repair->id, $creation->fresh()->payload['flow_wizard_attempt']['configured_by_approval_id']);
        $this->assertSame('failed', $creation->fresh()->payload['flow_wizard_recovery']['previous_status']);
        $this->assertSame(1, $this->state->flows_created);
        $this->assertSame([], $this->state->runs);
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->assertSame('completed', $test->status, $test->payload['execution']['message'] ?? '');
    }

    public function test_a_repair_with_lost_confirmation_can_be_checked_without_repeating_its_push(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->state->fail_push = false;
        $repair = $this->follow($user, $creation, 'configure_source_flow');
        $this->state->fail_after_push = true;
        $failed = $this->execute($user, $repair);
        $this->assertSame('failed', $failed->status);
        $this->checkStep($user, $creation)->assertOk()->assertJsonPath('verification.verified', true);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(1, $this->state->flows_created);
        $this->assertSame(2, collect($this->state->commands)->filter(fn (array $args): bool => $args[0] === 'push')->count());
    }

    public function test_local_authored_edits_are_preserved_and_prevent_repair_of_an_empty_flow(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $project = Project::findOrFail($creation->project_id);
        $path = $this->path($project, 'flow:99');
        File::append($path, "descricao: Edição local do usuário\n");
        $hash = hash_file('sha256', $path);
        $this->checkStep($user, $creation)->assertOk()->assertJsonPath('verification.state', 'needs_authorship')->assertJsonPath('approval.status', 'failed');
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'configure_source_flow', 'project_id' => $project->id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertSame([], $this->state->bodies['flow:99']['nodes']['Processors']);
        $this->assertSame(1, $this->state->flows_created);
    }

    public function test_a_remote_edit_prevents_confirmation_and_repair_without_overwriting_it(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->state->bodies['flow:99']['nome'] = 'Nome alterado fora do portal';
        $this->checkStep($user, $creation)->assertUnprocessable();
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'configure_source_flow', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame('Nome alterado fora do portal', $this->state->bodies['flow:99']['nome']);
        $this->assertSame('failed', $creation->fresh()->status);
    }

    public function test_table_creation_recovers_its_receipt_binding_and_schema_without_creating_another_table(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $review = $this->follow($user, $creation, 'create_source_table');
        $this->state->callback = function (Connection $connection, array $args): void {
            if ($args === ['info', 'table:501']) {
                $this->state->callback = null;
                throw new RuntimeException('Conferência de tabela temporariamente indisponível.');
            }
        };
        $table = $this->execute($user, $review);
        $this->assertSame('failed', $table->status);
        $this->checkStep($user, $table)->assertOk()->assertJsonPath('verification.verified', true)->assertJsonPath('approval.payload.execution.result.flow_wizard.table', 'table:501');
        $this->assertSame(1, $this->state->tables_created);
        $this->assertSame(0, $this->state->loads);
    }

    public function test_a_table_with_an_unexpected_schema_cannot_be_recovered_or_loaded(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $table = $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $table->update(['status' => 'failed']);
        $this->state->bodies['table:501']['columns'] = ['ID (TEXT)' => [], 'AMOUNT (DECIMAL)' => []];
        $this->checkStep($user, $table)->assertUnprocessable();
        $this->assertSame('failed', $table->fresh()->status);
        $this->assertSame(0, $this->state->loads);
    }

    public function test_a_finished_extraction_with_failed_workspace_refresh_is_confirmed_without_running_it_again(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $review = $this->follow($user, $creation, 'test_source_flow');
        $this->failPostRunPull($review->project_id, false);
        $test = $this->execute($user, $review);
        $this->assertSame('failed', $test->status);
        $this->checkStep($user, $test)->assertOk()->assertJsonPath('verification.verified', true)->assertJsonPath('approval.payload.execution.result.flow_wizard.captured_fields', 2);
        $this->assertSame(['ExtractPostgreSQL'], $this->state->runs);
    }

    private function failPostRunPull(string $projectId, bool $load): void
    {
        $this->state->callback = function (Connection $connection, array $args, Project $context) use ($projectId, $load): void {
            if ($args === ['pull', 'flow:99'] && $context->id === $projectId && ($load ? $this->state->loads > 0 : count($this->state->runs) > 0)) {
                $this->state->callback = null;
                throw new RuntimeException('Atualização de autoria temporariamente indisponível.');
            }
        };
    }

    public function test_a_finished_load_recovers_with_a_data_check_without_running_the_load_again(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->execute($user, $this->follow($user, $creation, 'create_source_table'));
        $review = $this->follow($user, $creation, 'load_source_table');
        $this->failPostRunPull($review->project_id, true);
        $load = $this->execute($user, $review);
        $this->assertSame('failed', $load->status);
        $this->checkStep($user, $load)->assertOk()->assertJsonPath('verification.verified', true)->assertJsonPath('approval.payload.execution.result.flow_wizard.data_check.preview_confirmed', true);
        (new ExecuteApprovedAction($load->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $this->state->loads);
        $this->assertStringNotContainsString('SYNTHETIC-BUSINESS-ROW', Approval::all()->toJson().AuditEvent::all()->toJson());
    }

    public function test_valid_metadata_without_a_signed_finish_receipt_cannot_confirm_or_repeat_an_execution(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->state->queued = true;
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->checkStep($user, $test)->assertOk()->assertJsonPath('verification.state', 'not_proven')->assertJsonPath('approval.status', 'failed');
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'test_source_flow', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, count($this->state->runs));
        $this->assertSame(0, $this->state->tables_created);
    }

    public function test_a_finish_receipt_cannot_be_changed_to_confirm_another_node(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $payload = $test->payload;
        $payload['etl_attempt']['native_finished']['receipt']['node'] = 'other-node';
        $test->update(['status' => 'failed', 'payload' => $payload]);
        $this->checkStep($user, $test)->assertOk()->assertJsonPath('verification.state', 'not_proven')->assertJsonPath('approval.status', 'failed');
        $this->assertSame(1, count($this->state->runs));
    }

    public function test_a_changed_source_connection_blocks_subsequent_stages_and_recovery(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->state->bodies['credential:11']['config']['password'] = 'SYNTHETIC-CHANGED';
        $this->postJson('/api/catalog/100/flow-wizard/actions', ['action' => 'test_source_flow', 'project_id' => $creation->project_id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $creation->update(['status' => 'failed']);
        $this->checkStep($user, $creation)->assertUnprocessable();
        $this->assertSame([], $this->state->runs);
    }

    public function test_recovery_requires_the_same_owner_and_a_valid_authorized_revision(): void
    {
        $user = $this->context();
        $this->state->fail_after_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $other = User::factory()->create();
        $this->checkStep($other, $creation)->assertNotFound();
        $reader = User::factory()->create(['role' => 'viewer']);
        $this->checkStep($reader, $creation)->assertNotFound();
        $this->actingAs($user)->postJson('/api/projects/'.$creation->project_id.'/flow-wizard/verify/'.$creation->id, ['fingerprint' => str_repeat('0', 64)])->assertConflict();
        $payload = $creation->payload;
        $payload['revision']['flow_wizard']['sql'] = 'SELECT another_source';
        $creation->update(['payload' => $payload]);
        $this->checkStep($user, $creation)->assertConflict();
        $this->assertSame('failed', $creation->fresh()->status);
    }

    public function test_pending_unsubmitted_reviews_cannot_be_confirmed_as_completed(): void
    {
        $user = $this->context();
        $creation = $this->prepare($user, $this->input());
        $this->checkStep($user, $creation)->assertConflict();
        $this->assertSame('pending', $creation->fresh()->status);
        $this->assertSame(0, $this->state->flows_created);
    }

    public function test_a_proven_session_lock_denial_allows_a_new_review_without_a_duplicate_execution(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $review = $this->follow($user, $creation, 'test_source_flow');
        $this->state->callback = function (Connection $connection, array $args): void {
            if (array_slice($args, 0, 2) === ['flow', 'run-node']) {
                $this->state->callback = null;
                throw new BiBusy;
            }
        };
        $failed = $this->execute($user, $review);
        $this->assertSame('failed', $failed->status);
        $this->assertSame([], $this->state->runs);
        $retry = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $this->assertSame('completed', $retry->status, $retry->payload['execution']['message'] ?? '');
        $this->assertSame(1, count($this->state->runs));
    }

    public function test_recovery_refuses_an_owning_reader_or_a_previous_access_revision(): void
    {
        $user = $this->context();
        $this->state->fail_after_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $user->forceFill(['role' => 'viewer'])->save();
        $this->checkStep($user->fresh(), $creation)->assertForbidden();
        $user->forceFill(['role' => 'editor', 'access_version' => 1])->save();
        $this->withSession(['user_access_version' => 1]);
        $this->checkStep($user->fresh(), $creation)->assertConflict();
        $this->assertSame('failed', $creation->fresh()->status);
    }

    public function test_reconnection_allows_reading_the_existing_result_and_requires_a_fresh_repair_review(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus')->sole();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        $this->checkStep($user, $creation)->assertOk()->assertJsonPath('verification.state', 'needs_configuration');
        $this->state->fail_push = false;
        $repair = $this->follow($user, $creation, 'configure_source_flow');
        $this->assertSame('session-b', $repair->payload['revision']['connection_generation']);
        $result = $this->execute($user, $repair);
        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame(1, $this->state->flows_created);
    }

    public function test_changes_after_the_repair_review_prevent_a_push_of_the_older_configuration(): void
    {
        $user = $this->context();
        $this->state->fail_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $repair = $this->follow($user, $creation, 'configure_source_flow');
        $this->state->fail_push = false;
        $this->state->bodies['flow:99']['nome'] = 'Alteração posterior à revisão';
        $result = $this->execute($user, $repair);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, collect($this->state->commands)->filter(fn (array $args): bool => $args[0] === 'push')->count());
        $this->assertSame('Alteração posterior à revisão', $this->state->bodies['flow:99']['nome']);
    }

    public function test_later_metadata_cannot_replace_the_result_bound_to_a_signed_finish_receipt(): void
    {
        $user = $this->context();
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $test = $this->execute($user, $this->follow($user, $creation, 'test_source_flow'));
        $test->update(['status' => 'failed']);
        $this->state->bodies['flow:99']['nodes']['Processors'][0]['metadata']['File'] = 'a-later-extraction.parquet';
        $this->checkStep($user, $test)->assertUnprocessable();
        $this->assertSame('failed', $test->fresh()->status);
        $this->assertSame(1, count($this->state->runs));
    }

    public function test_extra_recovery_parameters_cannot_redirect_the_checked_resource(): void
    {
        $user = $this->context();
        $this->state->fail_after_push = true;
        $creation = $this->execute($user, $this->prepare($user, $this->input()));
        $this->postJson('/api/projects/'.$creation->project_id.'/flow-wizard/verify/'.$creation->id, ['fingerprint' => $creation->fingerprint, 'resource' => 'flow:10'])->assertUnprocessable()->assertJsonValidationErrors('flow');
        $this->assertSame('failed', $creation->fresh()->status);
    }

    public static function drivers(): array
    {
        return [
            'postgres' => ['postgresql', 'SELECT "id", "amount" FROM "public"."orders" LIMIT 10', 'ExtractPostgreSQL'],
            'mysql' => ['mysql', 'SELECT `id`, `amount` FROM `public`.`orders` LIMIT 10', 'ExtractMySQL'],
            'sqlserver' => ['sqlserver', 'SELECT TOP (10) [id], [amount] FROM [public].[orders]', 'ExtractSQLServer'],
        ];
    }

    #[DataProvider('drivers')]
    public function test_supported_connectors_generate_quoted_selects_and_the_matching_native_extractor(string $driver, string $sql, string $kind): void
    {
        $user = $this->context($driver);
        $review = $this->prepare($user, $this->input());
        $this->assertSame($sql, $review->payload['revision']['flow_wizard']['sql']);
        $result = $this->execute($user, $review);
        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertSame($kind, $this->state->bodies['flow:99']['nodes']['Processors'][0]['kind']);
    }

    public function test_the_postgres_exact_table_and_selected_column_query_preserves_the_visible_table_count(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('O contrato SQL nativo é executado em PostgreSQL isolado.');
        }
        Schema::create('wizard_source_fixture', function (Blueprint $table): void {
            $table->integer('id');
            $table->decimal('amount');
            $table->string('unselected');
        });
        $user = $this->context();
        $this->state->query = fn (string $sql): array => array_map(get_object_vars(...), DB::select($sql));
        $input = $this->input();
        $input['source']['table'] = 'wizard_source_fixture';
        $input['source']['columns'] = ['amount'];
        $review = $this->prepare($user, $input);
        $this->assertCount(1, $review->payload['revision']['flow_wizard']['source']['columns']);
        $this->assertSame(3, $review->payload['revision']['flow_wizard']['source']['visible_column_count']);
        $this->assertSame('SELECT "amount" FROM "public"."wizard_source_fixture" LIMIT 10', $review->payload['revision']['flow_wizard']['sql']);
    }
}
