<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiDashboardCreationTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/dashboard-create-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(string $role = 'editor'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste']]]]);
        $state = (object) ['body' => ['nome' => 'Aplicação de teste', '_state' => 'draft', 'deskId' => null, 'tables' => [['tableId' => 500]], 'filtros' => [], 'filter_mode' => null, 'unknown' => ['preserve' => true], 'dashboards' => [['header' => ['kind' => 'app-dashboard', 'lumo' => 'v2', 'id' => 21, 'tenantId' => 100, 'appId' => 11], 'body' => ['nome' => 'Existente', 'ordem' => 0, 'widgets' => [['id' => 7, 'type' => 'Svelte', 'chart' => ['code' => 'SYNTHETIC-ORIGINAL-CONTENT']]], 'filters' => [], 'future' => (object) []]]]], 'pushes' => 0, 'commands' => [], 'coverage' => true, 'fields' => [['id' => 501, 'column_name' => 'VALOR', 'label' => 'Valor', 'data_type' => 'Number'], ['id' => 502, 'column_name' => 'REGIAO', 'label' => 'Região', 'data_type' => 'String'], ['id' => 503, 'column_name' => 'DATA', 'label' => 'Data', 'data_type' => 'Date']]];
        $this->native($state);

        return [$user, $connection, $state];
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnUsing(function ($connection, $context, bool $readOnly): void {
            $this->assertTrue($readOnly);
            app(WorkspaceFiles::class)->prepare($context);
        });
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments, $context) use ($state): array {
            $state->commands[] = $arguments;
            $folder = app(WorkspaceFiles::class)->path($context).'/apps';
            $path = $folder.'/application--11.yaml';
            if ($arguments[0] === 'list') {
                return ['data' => ['results' => $state->missing ?? false ? [] : [['id' => 11, 'nome' => $state->body['nome'], 'deskId' => $state->body['deskId']]], 'matched' => $state->missing ?? false ? 0 : 1]];
            }
            if ($arguments[0] === 'pull') {
                if (($state->readback_failed ?? false) && $state->pushes > 0) {
                    throw new RuntimeException('Conferência indisponível.', $state->readback_code ?? 0);
                }
                File::ensureDirectoryExists($folder, 0700, true);
                File::put($path, Yaml::dump(['kind' => 'app', 'lumo' => 'v2', 'id' => $state->wrong_id ?? 11, 'tenantId' => $state->wrong_tenant ?? 100])."---\n".Yaml::dump($state->body, 30, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

                return ['data' => []];
            }
            if ($arguments[0] === 'info') {
                return ['data' => ['payload' => ['header' => ['kind' => 'app', 'id' => 11, 'tenantId' => $state->fields_tenant ?? 100], 'body' => ['tables' => [['tableId' => 500, 'nome' => 'Vendas de teste', 'columns' => $state->fields]]]]]];
            }
            if ($arguments[0] === 'lint') {
                if (isset($state->on_lint)) {
                    ($state->on_lint)($connection);
                }

                return ['exit_code' => ($state->invalid ?? false) ? 8 : 0, 'data' => ['valid' => ! ($state->invalid ?? false), 'errorCount' => ($state->invalid ?? false) ? 1 : 0]];
            }
            if ($arguments[0] === 'app' && $arguments[1] === 'query') {
                if ($state->query_failure ?? false) {
                    throw new RuntimeException('Falha sintética do provedor.', 10);
                }
                $fields = explode(',', $arguments[array_search('--fields', $arguments, true) + 1]);
                $last = str_replace(':', '_', $fields[count($fields) - 1]);
                $aggregation = substr($last, strrpos($last, '_') + 1);
                $value = ['SUM' => 2100, 'COUNT' => 6, 'AVG' => 350, 'MIN' => 100, 'MAX' => 600][$aggregation];
                $north = ['SUM' => 1500, 'COUNT' => 3, 'AVG' => 500, 'MIN' => 400, 'MAX' => 600][$aggregation];
                $south = ['SUM' => 600, 'COUNT' => 3, 'AVG' => 200, 'MIN' => 100, 'MAX' => 300][$aggregation];
                $rows = count($fields) === 1 ? [[$last => $value]] : [[str_replace(':', '_', $fields[0]) => str_starts_with($fields[0], '503:') ? '2026-11' : 'Norte', $last => $north], [str_replace(':', '_', $fields[0]) => str_starts_with($fields[0], '503:') ? '2026-12' : 'Sul', $last => $south]];

                return ['data' => ['rows' => $state->query_rows ?? (count($fields) > 1 ? ($state->group_rows ?? $rows) : $rows), 'count' => count($rows), 'sql' => 'SYNTHETIC-PRIVATE-SQL']];
            }
            if ($arguments[0] === 'diff') {
                return ['data' => ['workingMissing' => false, 'workingVsState' => ['equal' => true], 'workingVsServer' => ['equal' => ! ($state->different_server ?? false)]]];
            }
            if ($arguments[0] === 'push') {
                $state->pushes++;
                if (isset($state->push_rejection)) {
                    throw new RuntimeException('Recusa sintética antes de salvar.', $state->push_rejection);
                }
                $shape = Yaml::parse(preg_split('/^---\s*$/m', File::get($path), 2)[1], Yaml::PARSE_OBJECT_FOR_MAP);
                $native = function (mixed $value) use (&$native): mixed {
                    if ($value instanceof \stdClass) {
                        $fields = get_object_vars($value);
                        $converted = array_map($native, $fields);

                        return $fields === [] || array_is_list($fields) ? (object) $converted : $converted;
                    }

                    return is_array($value) ? array_map($native, $value) : $value;
                };
                $state->submitted = $native($shape);
                if (! ($state->ignored_push ?? false)) {
                    $state->body = $state->submitted;
                    $index = count($state->body['dashboards']) - 1;
                    $new = &$state->body['dashboards'][$index];
                    $new['header'] = ['kind' => 'app-dashboard', 'lumo' => 'v2', 'id' => $state->created_dashboard_id ?? 22, 'tenantId' => 100, 'appId' => 11];
                    foreach ($new['body']['widgets'] as $i => &$widget) {
                        $widget['id'] = ($state->reused_widget ?? false) ? 7 : 1001 + $i;
                    }
                    unset($widget, $new);
                    foreach ($state->body['dashboards'] as &$dashboard) {
                        $dashboard['body']['ordem'] = 0;
                    }
                    unset($dashboard);
                    if ($state->indexed_options_as_lists ?? false) {
                        foreach ($state->body['dashboards'][$index]['body']['widgets'] as &$item) {
                            foreach (['valueConfig', 'fieldOptions'] as $field) {
                                if (($item['chart'][$field] ?? null) instanceof \stdClass) {
                                    $item['chart'][$field] = array_values(get_object_vars($item['chart'][$field]));
                                }
                            }
                        }
                        unset($item);
                    }
                    if ($state->changed_fields_after_push ?? false) {
                        $state->fields[0]['label'] = 'Outro significado após o envio';
                    }
                    if ($state->changed_original ?? false) {
                        $state->body['dashboards'][0]['body']['widgets'][0]['chart']['code'] = 'ALTERED';
                    }
                    if ($state->changed_new ?? false) {
                        $state->body['dashboards'][$index]['body']['widgets'][0]['chart']['primaryData']['dataSource'] = '999:SUM';
                    }
                    if ($state->extra_dashboard ?? false) {
                        $extra = $state->body['dashboards'][$index];
                        $extra['header']['id'] = 23;
                        $state->body['dashboards'][] = $extra;
                    }
                }
                if ($state->lost_response ?? false) {
                    throw new RuntimeException('Resposta perdida após salvar.');
                }

                return ['data' => []];
            }
            throw new RuntimeException('Comando inesperado no ensaio: '.json_encode($arguments));
        });
        $coverage = $this->mock(RefreshCoverage::class);
        $coverage->shouldReceive('inspect')->andReturnUsing(fn (): array => ['configured' => $state->coverage, 'verified' => $state->coverage, 'last_executions_successful' => $state->coverage, 'sources' => [['resource' => 'flow:600']], 'checked_at' => now()->toIso8601String()]);
    }

    private function input(User $user, array $changes = []): array
    {
        $read = $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertOk();

        return array_replace(['request_id' => (string) Str::uuid(), 'version' => $read->json('version'), 'name' => 'Resumo comercial', 'metric_id' => 501, 'group_id' => 502, 'aggregation' => 'SUM', 'grain' => 'AGP', 'format' => 'currency', 'metric_title' => 'Valor total'], $changes);
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->fresh();
    }

    public function test_descriptor_exposes_business_fields_without_source_code_or_queries(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertOk();

        $response->assertJsonPath('resource', 'app:11')->assertJsonPath('fields.0.label', 'Valor')->assertJsonPath('fields.1.numeric', false)->assertJsonPath('fields.2.date', true)->assertJsonPath('dashboard_count', 1);
        $this->assertStringNotContainsString('SYNTHETIC-ORIGINAL-CONTENT', $response->getContent());
    }

    public function test_revision_reads_actual_sample_and_does_not_create_a_dashboard_before_approval(): void
    {
        [$user, , $state] = $this->context();
        $original = json_encode($state->body);

        $approval = $this->prepare($user, $this->input($user));

        $this->assertSame('pending', $approval->status);
        $this->assertSame(2100, $approval->payload['revision']['dashboard_creation']['preview']['value']);
        $this->assertSame(2, count($approval->payload['revision']['dashboard_creation']['preview']['rows']));
        $this->assertTrue($approval->payload['revision']['dashboard_creation']['coverage']['verified']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-SQL', $approval->toJson());
        $this->assertSame(0, $state->pushes);
        $this->assertSame($original, json_encode($state->body));
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_approved_creation_preserves_existing_panels_and_refreshes_only_the_owners_linked_previews(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $original = $state->body;
        $linked = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:11', 'name' => 'Painel vinculado']);
        $foreign = Project::create(['user_id' => User::factory()->create()->id, 'tenant_id' => '100', 'source_ref' => 'app:11', 'name' => 'Outro usuário']);
        $approval = $this->prepare($user, $this->input($user));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $this->assertTrue($result->payload['execution']['result']['dashboard_creation']['verified']);
        $this->assertSame(22, $result->payload['execution']['result']['dashboard_creation']['dashboard_id']);
        $this->assertSame(1, $state->pushes);
        $this->assertSame(json_encode($original['dashboards'][0]), json_encode($state->body['dashboards'][0]));
        $this->assertSame($original['tables'], $state->body['tables']);
        $this->assertSame($approval->id, $linked->fresh()->preview_revision);
        $this->assertNull($foreign->fresh()->preview_revision);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_first_dashboard_can_be_created_in_an_empty_linked_application(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body['dashboards'] = [];
        $approval = $this->prepare($user, $this->input($user));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertCount(1, $state->body['dashboards']);
        $this->assertSame(0, $result->payload['execution']['result']['dashboard_creation']['preserved_dashboard_count']);
    }

    public function test_lost_response_blocks_another_creation_and_is_confirmed_without_resending(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $input = $this->input($user);
        $approval = $this->prepare($user, $input);
        $state->lost_response = true;
        $result = $this->execute($user, $approval);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $state->pushes);
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', [...$input, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/catalog/100/apps/11/settings')->assertUnprocessable();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'renewed']]);

        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.dashboard_creation.verified', true);

        $this->assertSame(1, $state->pushes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_known_native_rejection_is_resolved_by_reading_and_allows_a_new_review(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $approval = $this->prepare($user, $input);
        $state->push_rejection = 6;
        $result = $this->execute($user, $approval);
        $this->assertSame('failed', $result->status);
        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertUnprocessable();
        $state->readback_failed = true;
        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertUnprocessable();
        $this->assertArrayNotHasKey('dashboard_creation_resolution', $approval->fresh()->payload);
        $state->readback_failed = false;

        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertOk()->assertJsonPath('approval.status', 'failed')->assertJsonPath('approval.payload.dashboard_creation_resolution.state', 'rejected')->assertJsonPath('approval.payload.dashboard_creation_resolution.native_rejection_verified', true);

        $this->assertSame(1, $state->pushes);
        $this->assertCount(1, $state->body['dashboards']);
        $this->assertArrayNotHasKey('result', $approval->fresh()->payload['execution']);
        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertOk();
        unset($state->push_rejection);
        $next = $this->prepare($user, [...$input, 'request_id' => (string) Str::uuid()]);
        $this->assertSame('pending', $next->status);
        $this->assertSame(1, $state->pushes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_readback_permission_failure_after_a_successful_push_is_not_a_native_rejection(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        $state->readback_failed = true;
        $state->readback_code = 6;
        $result = $this->execute($user, $approval);
        $this->assertSame('failed', $result->status);
        $this->assertTrue($result->payload['dashboard_creation_attempt']['native_saved']);
        $this->assertArrayNotHasKey('native_rejection', $result->payload['dashboard_creation_attempt']);
        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertUnprocessable();
        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertUnprocessable();
        $state->readback_failed = false;

        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertOk()->assertJsonPath('approval.status', 'completed');

        $this->assertSame(1, $state->pushes);
    }

    public function test_unknown_lost_response_cannot_be_released_only_because_no_addition_is_visible_yet(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        $state->ignored_push = true;
        $state->lost_response = true;
        $result = $this->execute($user, $approval);
        $this->assertSame('failed', $result->status);

        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertUnprocessable();

        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertUnprocessable();
        $this->assertArrayNotHasKey('dashboard_creation_resolution', $approval->fresh()->payload);
        $this->assertSame(1, $state->pushes);
    }

    public function test_provider_query_failure_keeps_the_proposal_without_creating_a_review_or_dashboard(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->query_failure = true;

        $response = $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        $this->assertStringContainsString('Nenhum dashboard foi criado', $response->json('errors.dashboard.0'));
        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('approvals', 0);
        $state->query_failure = false;
        $this->prepare($user, $input);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->pushes);
    }

    public function test_missing_daily_coverage_and_a_published_application_do_not_create_reviews(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->coverage = false;
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard');
        $state->coverage = true;
        $state->body['deskId'] = 45;
        $state->body['_state'] = 'published';
        $input = $this->input($user);

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable();

        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidProposals(): array
    {
        return ['foreign field' => [['metric_id' => 999]], 'sum text' => [['metric_id' => 502]], 'date grouping' => [['group_id' => 503]], 'text period' => [['grain' => 'YEARMONTH']], 'unexpected code' => [['widgets' => [['code' => 'bad']]]], 'unknown function' => [['aggregation' => 'SQL']], 'empty name' => [['name' => '']], 'old version' => [['version' => str_repeat('f', 64)]]];
    }

    #[DataProvider('invalidProposals')]
    public function test_invalid_or_foreign_proposals_never_write(array $changes): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user, $changes);

        $response = $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input);

        $this->assertContains($response->status(), [409, 422]);
        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function unconfirmedSaves(): array
    {
        return ['not added' => ['ignored_push'], 'existing content changed' => ['changed_original'], 'new expression changed' => ['changed_new'], 'ambiguous additions' => ['extra_dashboard'], 'reused widget identity' => ['reused_widget'], 'remote drift' => ['different_server'], 'read unavailable' => ['readback_failed'], 'field meaning changed' => ['changed_fields_after_push']];
    }

    #[DataProvider('unconfirmedSaves')]
    public function test_native_saves_without_matching_content_never_receive_a_verified_receipt(string $property): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        $state->{$property} = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertArrayNotHasKey('result', $result->payload['execution']);
        $this->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertUnprocessable();
        $this->assertSame(1, $state->pushes);
    }

    public static function changedBeforeExecution(): array
    {
        return ['source' => ['source'], 'metadata' => ['metadata'], 'coverage' => ['coverage'], 'connection' => ['connection']];
    }

    #[DataProvider('changedBeforeExecution')]
    public function test_changed_context_after_review_prevents_a_native_creation(string $change): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        match ($change) {
            'source' => $state->body['unknown']['preserve'] = false,
            'metadata' => $state->fields[0]['label'] = 'Outro significado',
            'coverage' => $state->coverage = false,
            'connection' => $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'renewed']]),
        };

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
        $this->assertArrayNotHasKey('dashboard_creation_attempt', $result->payload);
    }

    public function test_repeated_request_recovers_only_the_same_immutable_proposal(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $first = $this->prepare($user, $input);
        $count = count($state->commands);
        $second = $this->prepare($user, $input);
        $this->assertSame($first->id, $second->id);
        $this->assertCount($count, $state->commands);

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', [...$input, 'name' => 'Outra proposta'])->assertConflict();

        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_date_dimension_builds_a_chronological_line_and_preserves_existing_filters(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body['filtros'] = [['column' => ['column_id' => '502', 'expression' => 'AGP'], 'operator' => '=', 'value' => 'Norte']];
        $approval = $this->prepare($user, $this->input($user, ['group_id' => 503, 'grain' => 'YEARMONTH']));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $chart = $state->body['dashboards'][1]['body']['widgets'][1]['chart'];
        $this->assertSame('line', $chart['globalType']);
        $this->assertSame(['503:YEARMONTH'], $chart['dimensionColumns']);
        $this->assertSame(['503:YEARMONTH'], $chart['orderColumns']);
        $this->assertSame(['ASC'], $chart['orderColumnsDir']);
        $this->assertFalse($chart['showDataLabels']);
        $this->assertSame('Norte', $state->body['filtros'][0]['value']);
        $this->assertStringContainsString('filtros iniciais', $approval->payload['revision']['dashboard_creation']['preview']['scope']);
    }

    public function test_an_average_has_a_global_indicator_and_no_sum_or_average_of_category_averages_in_the_footer(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user, ['aggregation' => 'AVG']));
        $this->assertSame(350, $approval->payload['revision']['dashboard_creation']['preview']['value']);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $widgets = $state->body['dashboards'][1]['body']['widgets'];
        $this->assertSame('501:AVG', $widgets[0]['chart']['primaryData']['dataSource']);
        $this->assertFalse($widgets[2]['chart']['showTotal']);
        $options = $widgets[2]['chart']['fieldOptions'];
        $this->assertArrayNotHasKey('totalizerType', $options->{'1'});
    }

    public function test_counting_a_text_field_uses_count_and_an_integer_format(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user, ['aggregation' => 'COUNT', 'metric_id' => 502]));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $chart = $state->body['dashboards'][1]['body']['widgets'][0]['chart'];
        $this->assertSame('502:COUNT', $chart['primaryData']['dataSource']);
        $this->assertSame('integer', $chart['visual']['numberFormat']);
    }

    public function test_preview_limits_the_sample_without_returning_generated_sql(): void
    {
        [$user, , $state] = $this->context();
        $state->group_rows = array_map(fn (int $i): array => ['502_AGP' => 'Categoria '.$i, '501_SUM' => $i], range(1, 26));

        $approval = $this->prepare($user, $this->input($user));

        $preview = $approval->payload['revision']['dashboard_creation']['preview'];
        $this->assertTrue($preview['truncated']);
        $this->assertCount(25, $preview['rows']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-SQL', $approval->toJson());
        $this->assertSame(0, $state->pushes);
    }

    public function test_unrecognized_query_data_never_becomes_a_preview_or_a_review(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->query_rows = [['unexpected' => 'missing numeric field']];

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable();

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_preparation_rejects_a_session_change_and_discards_no_project_files(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->on_lint = fn (Connection $connection) => $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'changed']]);

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable();

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_readers_guests_and_other_tenants_cannot_use_the_builder(): void
    {
        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertUnauthorized();
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', [])->assertUnauthorized();
        [$user, , $state] = $this->context('viewer');
        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertForbidden();
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', [])->assertForbidden();
        $user->forceFill(['role' => 'editor'])->save();

        $this->actingAs($user->fresh())->getJson('/api/catalog/200/apps/11/dashboard-builder')->assertForbidden();
        $this->postJson('/api/catalog/200/apps/11/dashboard-builder/actions', [])->assertForbidden();

        $this->assertSame([], $state->commands);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_field_metadata_of_another_tenant_is_not_exposed(): void
    {
        [$user, , $state] = $this->context();
        $state->fields_tenant = 200;

        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertUnprocessable();

        $this->assertSame(0, $state->pushes);
    }

    public function test_native_indexed_chart_options_may_use_lists_without_losing_their_values(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        $state->indexed_options_as_lists = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame('currency', $state->body['dashboards'][1]['body']['widgets'][2]['chart']['fieldOptions'][1]['numberFormatOption']);
        $this->assertTrue($result->payload['execution']['result']['dashboard_creation']['verified']);
    }

    public function test_the_last_available_slot_can_be_confirmed_without_confusing_it_with_an_over_limit_application(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body['dashboards'] = array_map(fn (int $id): array => ['header' => ['kind' => 'app-dashboard', 'lumo' => 'v2', 'id' => $id, 'tenantId' => 100, 'appId' => 11], 'body' => ['nome' => 'Painel '.$id, 'ordem' => 0, 'widgets' => [['id' => 700 + $id, 'type' => 'Kpi', 'chart' => []]]]], range(100, 198));
        $state->created_dashboard_id = 5000;
        $approval = $this->prepare($user, $this->input($user));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertCount(100, $state->body['dashboards']);
        $input = $this->input($user);
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable();
        $this->assertSame(1, $state->pushes);
    }
}
