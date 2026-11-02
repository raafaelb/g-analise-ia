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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiTablePresentationTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/table-presentation-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function actor(string $role = 'editor'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '321', 'name' => 'Teste']]]]);
        $state = (object) [
            'body' => ['nome' => 'Tabela de exemplo', 'description' => 'Descrição atual', '_state' => 'published', 'deskId' => 8, 'originFlowId' => 90, 'table_type' => 'table', 'key_type' => 'unique', 'key_columns' => ['CODIGO'], 'partition_column' => 'DATA', 'expressions' => [['id' => 91, 'name' => 'Total existente', 'expression' => 'SUM(VALOR)']],
                'columns' => [
                    'CODIGO (Number)' => ['label' => 'Código', 'mask' => 'N0', 'role' => 'identificador', 'description' => null, 'defaultBehavior' => 'default'],
                    'VALOR (Number)' => ['label' => 'Valor', 'mask' => 'CUSTOM_EXISTING', 'role' => 'saldo', 'description' => null, 'defaultBehavior' => 'default', 'future_metadata' => ['keep' => true]],
                    'NOME (String)' => ['label' => 'Nome', 'mask' => null, 'role' => null, 'description' => 'Texto da origem', 'defaultBehavior' => 'default'],
                    'DATA (Date)' => ['label' => 'Data', 'mask' => 'DATE_EXISTING', 'role' => 'data', 'description' => null, 'defaultBehavior' => 'default'],
                ]],
            'push' => 0, 'commands' => [], 'listed' => true,
        ];

        return [$user, $connection, $state];
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->withArgs(fn ($c, $p, $readOnly) => $readOnly)->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments, $context) use ($state): array {
            $state->commands[] = $arguments;
            if ($arguments[0] === 'list') {
                $this->assertSame('table', $arguments[1]);

                return ['data' => ['results' => $state->listed ? [['id' => 10, 'nome' => $state->body['nome']]] : [], 'matched' => $state->listed ? 1 : 0]];
            }
            if ($arguments[0] === 'pull') {
                $this->assertSame('table:10', $arguments[1]);
                if (($state->fail_confirmation ?? false) && $state->push > 0) {
                    throw new RuntimeException('Synthetic readback unavailable');
                }
                $path = app(WorkspaceFiles::class)->path($context).'/tables';
                File::ensureDirectoryExists($path, 0700, true);
                if ($state->preserve_empty_shapes ?? false) {
                    $state->body['future_metadata'] = new \stdClass;
                    $state->body['future_numeric_map'] = (object) ['0' => (object) ['items' => []]];
                }
                File::put($path.'/table--10.yaml', Yaml::dump(['id' => 10, 'kind' => 'table', 'tenantId' => $state->returned_tenant ?? 321, 'lumo' => 'v2'])."---\n".Yaml::dump($state->body, 30, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP));

                return ['data' => []];
            }
            if ($arguments[0] === 'lint') {
                $this->assertFileExists($arguments[1]);
                if ($state->preserve_empty_shapes ?? false) {
                    $parts = preg_split('/^---\s*$/m', File::get($arguments[1]), 2);
                    $candidate = Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
                    $this->assertSame([], $candidate->expressions);
                    $this->assertSame([], $candidate->key_columns);
                    $this->assertInstanceOf(\stdClass::class, $candidate->future_metadata);
                    $this->assertInstanceOf(\stdClass::class, $candidate->future_numeric_map);
                    $this->assertSame([], $candidate->future_numeric_map->{'0'}->items);
                    $this->assertInstanceOf(\stdClass::class, $candidate->columns);
                }
                if ($state->reconnect_on_lint ?? false) {
                    Connection::whereKey($connection->id)->update(['metadata' => json_encode([...$connection->metadata, 'session_generation' => 'replacement'])]);
                }
                if ($state->fail_lint ?? false) {
                    return ['exit_code' => 8, 'data' => ['valid' => false, 'errorCount' => 1, 'results' => [['issues' => [['path' => 'columns', 'message' => 'Formato incompatível com a coluna.', 'severity' => 'error']]]]]];
                }

                return ['data' => ['valid' => true, 'errorCount' => 0]];
            }
            if ($arguments[0] === 'push') {
                $this->assertSame(['push', 'table:10'], $arguments);
                $state->push++;
                $path = app(WorkspaceFiles::class)->path($context).'/tables/table--10.yaml';
                $parts = preg_split('/^---\s*$/m', File::get($path), 2);
                if (! ($state->ignore_write ?? false)) {
                    $previousMask = $state->body['columns']['VALOR (Number)']['mask'] ?? null;
                    $state->body = Yaml::parse($parts[1]);
                    if (($state->ignore_null_mask ?? false) && $state->body['columns']['VALOR (Number)']['mask'] === null) {
                        $state->body['columns']['VALOR (Number)']['mask'] = $previousMask;
                    }
                }
                if ($state->alter_structure ?? false) {
                    $state->body['key_type'] = 'duplicate';
                }
                if ($state->alter_unselected_label ?? false) {
                    $state->body['columns']['NOME (String)']['label'] = 'Mudança não solicitada';
                }
                if ($state->fail_push ?? false) {
                    throw new RuntimeException('Synthetic uncertain transmission');
                }

                return ['data' => []];
            }
            $this->fail('Comando não permitido no fluxo de apresentação: '.$arguments[0]);
        });
    }

    private function input(string $version, array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'version' => $version, 'columns' => [['key' => 'VALOR (Number)', 'label' => 'Valor atualizado', 'description' => 'Descrição de negócio']]], $overrides);
    }

    private function read(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/catalog/321/tables/10/presentation')->assertOk()->json();
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->refresh();
    }

    private function pendingSourceDeletion(User $user): Approval
    {
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '321', 'name' => 'ETL em conferência', 'source_ref' => 'flow:90']);
        $plan = ['kind' => 'flow', 'resource' => 'flow:90', 'tables' => [['resource' => 'table:10']]];
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'type' => 'manual', 'title' => 'Excluir ETL de teste', 'description' => 'Teste sintético', 'status' => 'failed', 'payload' => ['action' => ['action' => 'delete_draft', 'resource' => 'flow:90'], 'revision' => ['flow_deletion' => $plan]], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(12)]);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '321', 'resource' => 'flow:90', 'origin' => 'new', 'status' => 'deletion_pending', 'receipt' => ['deletion_attempt' => ['approval_id' => $approval->id, 'plan_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))]]]);

        return $approval;
    }

    public function test_pending_source_deletion_allows_reading_but_returns_422_for_a_new_table_review(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $state->body['_state'] = 'draft';
        $state->body['deskId'] = null;
        $this->native($state);
        $this->pendingSourceDeletion($user);
        $form = $this->read($user);

        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version']))->assertUnprocessable()->assertJsonValidationErrors('table');

        $this->assertSame(0, $state->push);
        $this->assertDatabaseCount('approvals', 1);
        Queue::assertNothingPushed();
    }

    public function test_an_existing_table_review_is_recovered_but_cannot_execute_after_its_source_deletion_started(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $state->body['_state'] = 'draft';
        $state->body['deskId'] = null;
        $this->native($state);
        $original = $state->body;
        $input = $this->input($this->read($user)['version']);
        $approval = $this->prepare($user, $input);
        $deletion = $this->pendingSourceDeletion($user);
        $this->assertSame($approval->id, $this->prepare($user, $input)->id);

        $approval = $this->execute($user, $approval);

        $this->assertSame('failed', $approval->status);
        $this->assertStringContainsString('exclusão em conferência', $approval->payload['execution']['message']);
        $this->assertArrayNotHasKey('table_presentation_attempt', $approval->payload);
        $this->assertSame('failed', $deletion->fresh()->status);
        $this->assertSame(0, $state->push);
        $this->assertSame($original, $state->body);
        $this->assertDatabaseCount('approvals', 2);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_configuration_exposes_friendly_fields_and_preserves_unknown_formats(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $response = $this->actingAs($user)->getJson('/api/catalog/321/tables/10/presentation')->assertOk()->assertJsonPath('name', 'Tabela de exemplo')->assertJsonPath('columns.1.label', 'Valor')->assertJsonPath('columns.1.mask', 'CUSTOM_EXISTING')->assertJsonPath('columns.1.has_custom_mask', true)->assertJsonPath('columns.3.mask_editable', false)->assertJsonMissingPath('expressions')->assertJsonMissingPath('key_type');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, $state->push);
        $this->assertSame([], File::directories($this->root.'/'.$user->id));
    }

    public function test_review_validates_without_writing_and_execution_changes_only_selected_fields_once(): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $connection, $state] = $this->actor();
        $this->native($state);
        $original = $state->body;
        $form = $this->read($user);
        $input = $this->input($form['version'], ['name' => 'Nome amigável da tabela', 'columns' => [['key' => 'VALOR (Number)', 'label' => 'Valor atualizado', 'mask' => 'N2']]]);
        $approval = $this->prepare($user, $input);
        $this->assertSame(0, $state->push);
        $this->assertSame('pending', $approval->status);
        $this->assertCount(3, $approval->payload['revision']['changes']);

        $sectionKey = 'bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':321:section:'.'table';
        Cache::put($sectionKey, ['outdated' => true], 90);
        $approval = $this->execute($user, $approval);
        $this->assertFalse(Cache::has($sectionKey));
        $expected = $original;
        $expected['nome'] = 'Nome amigável da tabela';
        $expected['columns']['VALOR (Number)']['label'] = 'Valor atualizado';
        $expected['columns']['VALOR (Number)']['mask'] = 'N2';
        $this->assertSame($expected, $state->body);
        $this->assertSame('completed', $approval->status);
        $this->assertTrue($approval->payload['execution']['result']['configuration_verified']);
        $this->assertSame(1, $state->push);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->push);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_editing_a_label_keeps_custom_masks_roles_and_future_metadata_unchanged(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $this->native($state);
        $original = $state->body;
        $form = $this->read($user);
        $approval = $this->prepare($user, $this->input($form['version']));
        $this->execute($user, $approval);
        $expected = $original;
        $expected['columns']['VALOR (Number)']['label'] = 'Valor atualizado';
        $expected['columns']['VALOR (Number)']['description'] = 'Descrição de negócio';
        $this->assertSame($expected, $state->body);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_stale_form_is_rejected_before_a_review_is_created(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $form = $this->read($user);
        $state->body['columns']['NOME (String)']['label'] = 'Mudança de outra pessoa';
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version']))->assertConflict();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->push);
    }

    public function test_table_changed_after_approval_is_not_overwritten(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version']));
        $state->body['description'] = 'Alteração posterior';
        $approval = $this->execute($user, $approval);
        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->push);
        $this->assertSame('Alteração posterior', $state->body['description']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    #[DataProvider('invalidChanges')]
    public function test_structural_or_invalid_input_never_prepares_a_write(array $overrides): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $input = $this->input($this->read($user)['version'], $overrides);
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $input)->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->push);
    }

    public static function invalidChanges(): array
    {
        return [
            'structure' => [['key_type' => 'duplicate']],
            'source' => [['originFlowId' => 999]],
            'column schema' => [['columns' => [['key' => 'VALOR (Number)', 'data_type' => 'String', 'label' => 'Renomeada']]]],
            'unknown column' => [['columns' => [['key' => 'OUTRA (Number)', 'label' => 'Outra']]]],
            'repeated column' => [['columns' => [['key' => 'VALOR (Number)', 'label' => 'A'], ['key' => 'VALOR (Number)', 'label' => 'B']]]],
            'numeric format in text' => [['columns' => [['key' => 'NOME (String)', 'mask' => 'N2']]]],
            'clearing date format' => [['columns' => [['key' => 'DATA (Date)', 'mask' => null]]]],
            'unsupported new format' => [['columns' => [['key' => 'VALOR (Number)', 'mask' => 'INVENTED']]]],
            'blank label' => [['columns' => [['key' => 'VALOR (Number)', 'label' => '']]]],
            'nothing changed' => [['columns' => [['key' => 'VALOR (Number)', 'label' => 'Valor']]]],
            'empty patch' => [['columns' => []]],
        ];
    }

    public function test_native_validation_failure_is_shown_before_requesting_authorization(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $form = $this->read($user);
        $state->fail_lint = true;
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version']))->assertUnprocessable()->assertJsonValidationErrors('table');
        $this->assertSame(0, $state->push);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_duplicate_request_id_reuses_the_review_and_rejects_a_different_configuration(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $input = $this->input($this->read($user)['version']);
        $first = $this->prepare($user, $input);
        $second = $this->prepare($user, $input);
        $this->assertSame($first->id, $second->id);
        $input['columns'][0]['label'] = 'Outro pedido';
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $input)->assertConflict();
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->push);
    }

    public function test_reconnected_session_cannot_execute_an_old_revision(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->actor();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version']));
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'replacement']]);
        $approval = $this->execute($user, $approval);
        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->push);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_or_unexpected_remote_results_are_not_reported_as_success_and_are_not_replayed(string $failure): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version']));
        $state->{$failure} = true;
        $approval = $this->execute($user, $approval);
        $this->assertSame('failed', $approval->status);
        $this->assertSame(1, $state->push);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->push);
        $this->assertDatabaseMissing('audit_events', ['action' => 'bi.table_presentation.saved']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public static function uncertainOutcomes(): array
    {
        return [['fail_push'], ['fail_confirmation'], ['ignore_write'], ['alter_structure'], ['alter_unselected_label']];
    }

    public function test_guest_viewer_foreign_tenant_and_unlisted_table_cannot_edit(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $this->getJson('/api/catalog/321/tables/10/presentation')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/catalog/999/tables/10/presentation')->assertForbidden();
        $state->listed = false;
        $this->getJson('/api/catalog/321/tables/10/presentation')->assertNotFound();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->getJson('/api/catalog/321/tables/10/presentation')->assertForbidden();
        $this->assertSame(0, $state->push);
    }

    public function test_read_only_tenant_and_changed_access_are_checked_before_writing(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $this->native($state);
        $form = $this->read($user);
        config(['horus.read_only_tenants' => ['321']]);
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version']))->assertUnprocessable();
        config(['horus.read_only_tenants' => []]);
        $approval = $this->prepare($user, $this->input($form['version']));
        $user->forceFill(['role' => 'viewer', 'access_version' => $user->access_version + 1])->save();
        $approval->forceFill(['status' => 'executing', 'decided_at' => now()])->save();
        try {
            app(ActionBroker::class)->performApproved($approval);
            $this->fail('A revisão antiga não pode executar com um acesso alterado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('acesso', $e->getMessage());
        }
        $this->assertSame(0, $state->push);
        Queue::assertNothingPushed();
    }

    public function test_tampered_review_is_refused_before_sending_any_write(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version']));
        $payload = $approval->payload;
        $payload['action']['column_changes']['VALOR (Number)']['label'] = 'Sem revisão';
        $approval->update(['payload' => $payload]);
        $approval = $this->execute($user, $approval);
        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->push);
        $this->assertSame('Valor', $state->body['columns']['VALOR (Number)']['label']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_reconnect_during_native_validation_does_not_create_a_stale_authorization(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $form = $this->read($user);
        $state->reconnect_on_lint = true;
        $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version']))->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->push);
    }

    public function test_a_returned_table_from_another_tenant_is_never_exposed(): void
    {
        [$user, , $state] = $this->actor();
        $this->native($state);
        $state->returned_tenant = 999;
        $this->actingAs($user)->getJson('/api/catalog/321/tables/10/presentation')->assertUnprocessable()->assertJsonMissingPath('columns');
        $this->assertSame(0, $state->push);
    }

    public function test_source_column_keys_roundtrip_exactly_and_a_description_can_be_cleared(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $state->body['columns'][' CODIGO (Number)'] = $state->body['columns']['CODIGO (Number)'];
        unset($state->body['columns']['CODIGO (Number)']);
        $state->body['key_columns'] = [' CODIGO'];
        $this->native($state);
        $form = $this->read($user);
        $approval = $this->prepare($user, $this->input($form['version'], ['description' => '', 'columns' => [['key' => ' CODIGO (Number)', 'label' => 'Código amigável'], ['key' => 'NOME (String)', 'description' => null]]]));
        $approval = $this->execute($user, $approval);
        $this->assertSame('completed', $approval->status);
        $this->assertSame('Código amigável', $state->body['columns'][' CODIGO (Number)']['label']);
        $this->assertNull($state->body['description']);
        $this->assertNull($state->body['columns']['NOME (String)']['description']);
        $this->assertSame([' CODIGO'], $state->body['key_columns']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_an_unknown_structured_numeric_format_cannot_be_replaced_or_cleared(): void
    {
        [$user, , $state] = $this->actor();
        $state->body['columns']['VALOR (Number)']['mask'] = ['future' => 'format'];
        $this->native($state);
        $form = $this->read($user);
        $column = collect($form['columns'])->firstWhere('key', 'VALOR (Number)');
        $this->assertFalse($column['mask_editable']);
        foreach ([null, 'N2'] as $format) {
            $this->actingAs($user)->postJson('/api/catalog/321/tables/10/presentation/actions', $this->input($form['version'], ['columns' => [['key' => 'VALOR (Number)', 'mask' => $format]]]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->push);
        $this->assertSame(['future' => 'format'], $state->body['columns']['VALOR (Number)']['mask']);
    }

    public function test_native_empty_lists_and_maps_keep_their_distinct_shapes_during_review_and_execution(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $state->preserve_empty_shapes = true;
        $state->body['expressions'] = [];
        $state->body['key_columns'] = [];
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version']));
        $approval = $this->execute($user, $approval);
        $this->assertSame('completed', $approval->status);
        $this->assertSame(1, $state->push);
        $this->assertSame([], $state->body['expressions']);
        $this->assertSame([], $state->body['key_columns']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_restoring_default_numeric_format_uses_the_supported_clear_representation_and_reads_back_as_default(): void
    {
        Queue::fake();
        [$user, , $state] = $this->actor();
        $state->ignore_null_mask = true;
        $this->native($state);
        $approval = $this->prepare($user, $this->input($this->read($user)['version'], ['columns' => [['key' => 'VALOR (Number)', 'mask' => null]]]));
        $approval = $this->execute($user, $approval);
        $this->assertSame('completed', $approval->status);
        $this->assertSame('', $state->body['columns']['VALOR (Number)']['mask']);
        $form = $this->read($user);
        $column = collect($form['columns'])->firstWhere('key', 'VALOR (Number)');
        $this->assertNull($column['mask']);
        $this->assertFalse($column['has_custom_mask']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }
}
