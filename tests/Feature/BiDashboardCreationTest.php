<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiDashboardBlueprint;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
        $this->mcp($user, $state);

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
                return ['data' => ['payload' => ['header' => ['kind' => 'app', 'id' => 11, 'tenantId' => $state->fields_tenant ?? 100], 'body' => [...$state->body, 'tables' => [['tableId' => 500, 'nome' => $state->table_name ?? 'Vendas de teste', 'columns' => $state->fields]]]]]];
            }
            if ($arguments[0] === 'lint') {
                if (isset($state->on_lint)) {
                    ($state->on_lint)($connection);
                }

                return ['exit_code' => ($state->invalid ?? false) ? 8 : 0, 'data' => ['valid' => ! ($state->invalid ?? false), 'errorCount' => ($state->invalid ?? false) ? 1 : 0]];
            }
            if ($arguments[0] === 'app' && $arguments[1] === 'query') {
                throw new RuntimeException('A consulta CLI suspensa nunca deve ser usada pelo assistente.');
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

    private function mcp(User $user, object $state): void
    {
        config(['horus.mcp.enabled' => true, 'horus.mcp.resource' => 'https://bi.example.test/mcp', 'horus.mcp.redirect_uri' => 'https://portal.example.test/auth/bi-mcp/callback']);
        $state->mcp = Connection::create(['user_id' => $user->id, 'provider' => 'horus_mcp:100', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-mcp-'.$user->id], 'scopes' => ['mcp:read'], 'metadata' => ['resource' => 'https://bi.example.test/mcp', 'tenant_id' => '100', 'lumo_generation' => 'session-a', 'session_generation' => 'mcp-generation', 'access_version' => $user->access_version, 'verified_app_id' => 11, 'available_tools' => ['get_app', 'query']], 'expires_at' => now()->addHour()]);
        $state->mcp_calls = [];
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/mcp' => function ($request) use ($state) {
            $rpc = $request->data();
            if ($request->method() === 'DELETE' || ($rpc['method'] ?? '') === 'notifications/initialized') {
                return Http::response('', 204);
            }
            $status = 200;
            if (($rpc['method'] ?? '') === 'initialize') {
                $result = ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []]];
            } else {
                $name = $rpc['params']['name'];
                $args = $rpc['params']['arguments'];
                $state->mcp_calls[] = ['tool' => $name, 'arguments' => $args];
                if ($name === 'get_app') {
                    $tableName = $state->table_name ?? 'Vendas de teste';
                    $schema = ['app' => ['id' => 11], 'tables' => [['name' => $tableName, 'columns' => array_map(fn (array $field): array => ['label' => $field['label'] ?? $field['column_name'], 'ref' => $tableName.'.'.($field['label'] ?? $field['column_name']), 'type' => $field['data_type']], $state->fields)]]];
                    $result = ($state->mcp_denied ?? false) ? ['isError' => true] : ['structuredContent' => isset($state->schema_transform) ? ($state->schema_transform)($schema) : $schema];
                } else {
                    $fields = $args['fields'];
                    $selected = array_map(function (array $field) use ($state): string {
                        $column = collect($state->fields)->first(fn (array $column): bool => ($state->table_name ?? 'Vendas de teste').'.'.($column['label'] ?? $column['column_name']) === $field['ref']);
                        $operation = isset($field['agg']) ? strtoupper($field['agg']) : (isset($field['bucket']) ? ['day' => 'DATE', 'month' => 'YEARMONTH', 'year' => 'YEAR'][$field['bucket']] : 'AGP');

                        return $column['id'].'_'.$operation;
                    }, $fields);
                    $last = $selected[count($selected) - 1];
                    $aggregation = substr($last, strrpos($last, '_') + 1);
                    $value = ['SUM' => 2100, 'COUNT' => 6, 'AVG' => 350, 'MIN' => 100, 'MAX' => 600][$aggregation];
                    $north = ['SUM' => 1500, 'COUNT' => 3, 'AVG' => 500, 'MIN' => 400, 'MAX' => 600][$aggregation];
                    $south = ['SUM' => 600, 'COUNT' => 3, 'AVG' => 200, 'MIN' => 100, 'MAX' => 300][$aggregation];
                    $rows = count($fields) === 1 ? [[$last => $value]] : [[$selected[0] => str_starts_with($selected[0], '503_') ? 'Nov 2026' : 'Norte', $last => $north], [$selected[0] => str_starts_with($selected[0], '503_') ? 'Dec 2026' : 'Sul', $last => $south]];
                    $rows = $state->query_rows ?? (count($fields) > 1 ? ($state->group_rows ?? $rows) : $rows);
                    $columns = array_keys($rows[0] ?? array_fill_keys($selected, null));
                    $data = ['appId' => 11, 'columns' => array_map(fn (string $key): array => ['key' => $key], $columns), 'rows' => array_map('array_values', $rows), 'rowCount' => count($rows), 'totalRows' => count($rows), 'truncated' => false, 'sql' => 'SYNTHETIC-PRIVATE-SQL', 'dataAsOf' => '2026-11-02T03:00:00-03:00'];
                    if (isset($state->result_transform)) {
                        $data = ($state->result_transform)($data, $args);
                    }
                    $result = ['structuredContent' => $data];
                    if ($state->query_denied ?? false) {
                        $status = 403;
                    } elseif ($state->query_failure ?? false) {
                        $status = 503;
                    }
                }
                if (isset($state->on_mcp)) {
                    ($state->on_mcp)($name, $args);
                }
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result], $status, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'synthetic-session']);
        }]);
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

        $response->assertJsonPath('resource', 'app:11')->assertJsonPath('fields.0.label', 'Valor')->assertJsonPath('fields.1.numeric', false)->assertJsonPath('fields.2.date', true)->assertJsonPath('dashboard_count', 1)->assertJsonPath('creation_available', true)->assertJsonPath('creation_unavailable_reason', null);
        $this->assertArrayNotHasKey('query_ref', $response->json('fields.0'));
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

    public function test_missing_personal_mcp_returns_422_without_a_review_or_a_native_write(): void
    {
        [$user, $connection, $state] = $this->context();
        $input = $this->input($user);
        $state->mcp->update(['status' => 'disconnected', 'credentials' => null]);

        $response = $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        $this->assertStringContainsString('Autorize as consultas deste ambiente', $response->json('errors.dashboard.0'));
        $this->assertStringNotContainsString('Conecte novamente', $response->json('errors.dashboard.0'));
        $this->assertSame(0, $state->pushes);
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_native_query_denial_is_actionable_and_never_creates_a_review_or_writes(): void
    {
        [$user, $connection, $state] = $this->context();
        $input = $this->input($user);
        $state->query_denied = true;

        $response = $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        $this->assertStringContainsString('autorização MCP', $response->json('errors.dashboard.0'));
        $this->assertStringNotContainsString('quando a consulta estiver disponível', $response->json('errors.dashboard.0'));
        $this->assertSame(0, $state->pushes);
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertDatabaseCount('approvals', 0);
        $this->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertOk();
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
        $state->group_rows = [['502_AGP' => 'Norte', '501_AVG' => 500], ['502_AGP' => 'Sul', '501_AVG' => 150]];
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

    public function test_missing_mcp_never_uses_another_users_or_another_tenants_grant(): void
    {
        [$user, , $state] = $this->context();
        $state->mcp->update(['status' => 'disconnected', 'credentials' => null]);
        $other = User::factory()->create(['role' => 'editor']);
        $foreign = $state->mcp->replicate();
        $foreign->forceFill(['user_id' => $other->id, 'status' => 'connected', 'credentials' => ['access_token' => 'foreign-user-token']])->save();
        $otherTenant = $state->mcp->replicate();
        $otherTenant->forceFill(['provider' => 'horus_mcp:200', 'status' => 'connected', 'credentials' => ['access_token' => 'other-tenant-token'], 'metadata' => [...$state->mcp->metadata, 'tenant_id' => '200']])->save();

        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboard-builder')->assertOk()->assertJsonPath('creation_available', false);
        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $this->input($user))->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        Http::assertNothingSent();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    #[DataProvider('changedMcpAuthorization')]
    public function test_changed_mcp_authorization_after_review_prevents_a_native_write(string $change): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        match ($change) {
            'generation' => $state->mcp->update(['metadata' => [...$state->mcp->metadata, 'session_generation' => 'another-grant']]),
            'disconnected' => $state->mcp->update(['status' => 'disconnected', 'credentials' => null]),
            'scope' => $state->mcp->update(['scopes' => []]),
            'tool' => $state->mcp->update(['metadata' => [...$state->mcp->metadata, 'available_tools' => ['get_app']]]),
            'remote denial' => $state->mcp_denied = true,
        };

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertArrayNotHasKey('dashboard_creation_attempt', $result->payload);
        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public static function changedMcpAuthorization(): array
    {
        return array_map(fn (string $change): array => [$change], ['generation', 'disconnected', 'scope', 'tool', 'remote denial']);
    }

    public function test_mcp_grant_changed_during_preparation_discards_the_sample(): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->on_mcp = function (string $tool) use ($state): void {
            if ($tool === 'query') {
                $state->mcp->update(['metadata' => [...$state->mcp->metadata, 'session_generation' => 'changed-during-query']]);
            }
        };

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard')->assertDontSee('2100');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
        $this->assertCount(1, array_filter($state->mcp_calls, fn (array $call): bool => $call['tool'] === 'query'));
    }

    #[DataProvider('invalidMcpSchemas')]
    public function test_ambiguous_missing_or_different_mcp_fields_never_query_values(string $change): void
    {
        [$user, , $state] = $this->context();
        $input = $this->input($user);
        $state->schema_transform = function (array $schema) use ($change): array {
            switch ($change) {
                case 'duplicate':
                    $schema['tables'][] = $schema['tables'][0];
                    break;
                case 'missing':
                    array_shift($schema['tables'][0]['columns']);
                    break;
                case 'type':
                    $schema['tables'][0]['columns'][0]['type'] = 'String';
                    break;
                case 'reference':
                    $schema['tables'][0]['columns'][0]['ref'] = 'Outra tabela.Valor';
                    break;
                case 'malformed':
                    $schema['tables'] = 'unexpected';
                    break;
            }

            return $schema;
        };

        $this->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        $this->assertSame([], array_values(array_filter($state->mcp_calls, fn (array $call): bool => $call['tool'] === 'query')));
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public static function invalidMcpSchemas(): array
    {
        return array_map(fn (string $change): array => [$change], ['duplicate', 'missing', 'type', 'reference', 'malformed']);
    }

    #[DataProvider('invalidMcpResults')]
    public function test_incompatible_or_incomplete_mcp_results_never_become_a_review(string $change): void
    {
        [$user, , $state] = $this->context();
        $state->result_transform = function (array $data, array $args) use ($change): array {
            switch ($change) {
                case 'different physical column':
                    $data['columns'][0]['key'] = '999_SUM';
                    break;
                case 'duplicate physical column':
                    if (count($args['fields']) > 1) {
                        $data['columns'][1]['key'] = $data['columns'][0]['key'];
                    }
                    break;
                case 'row width':
                    $data['rows'][0][] = 'unexpected';
                    break;
                case 'truncated total':
                    if (count($args['fields']) === 1) {
                        $data['truncated'] = true;
                    }
                    break;
                case 'load changed':
                    if (count($args['fields']) > 1) {
                        $data['dataAsOf'] = '2026-11-02T04:00:00-03:00';
                    }
                    break;
                case 'nonnumeric indicator':
                    if (count($args['fields']) === 1) {
                        $data['rows'][0][0] = 'not-a-number';
                    }
                    break;
            }

            return $data;
        };

        $this->actingAs($user)->postJson('/api/catalog/100/apps/11/dashboard-builder/actions', $this->input($user))->assertUnprocessable()->assertJsonValidationErrors('dashboard');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public static function invalidMcpResults(): array
    {
        return array_map(fn (string $change): array => [$change], ['different physical column', 'duplicate physical column', 'row width', 'truncated total', 'load changed', 'nonnumeric indicator']);
    }

    public function test_mcp_columns_can_change_order_without_swapping_labels_and_values(): void
    {
        [$user, , $state] = $this->context();
        $state->result_transform = function (array $data): array {
            $data['columns'] = array_reverse($data['columns']);
            $data['rows'] = array_map('array_reverse', $data['rows']);

            return $data;
        };

        $approval = $this->prepare($user, $this->input($user));

        $preview = $approval->payload['revision']['dashboard_creation']['preview'];
        $this->assertSame(2100, $preview['value']);
        $this->assertSame([['label' => 'Norte', 'value' => 1500], ['label' => 'Sul', 'value' => 600]], $preview['rows']);
        $this->assertSame('mcp', $preview['query_method']);
        $this->assertSame('2026-11-02T03:00:00-03:00', $preview['data_as_of']);
    }

    public function test_a_short_group_result_marked_partial_is_not_reported_complete(): void
    {
        [$user, , $state] = $this->context();
        $state->result_transform = function (array $data, array $args): array {
            if (count($args['fields']) > 1) {
                $data['truncated'] = true;
                $data['totalRows'] = 100;
            }

            return $data;
        };

        $approval = $this->prepare($user, $this->input($user));

        $preview = $approval->payload['revision']['dashboard_creation']['preview'];
        $this->assertTrue($preview['truncated']);
        $this->assertCount(2, $preview['rows']);
    }

    #[DataProvider('longNativeNames')]
    public function test_query_uses_full_native_names_even_when_the_form_labels_are_shortened(string $field): void
    {
        [$user, , $state] = $this->context();
        $state->table_name = $field === 'table' ? str_repeat('Tabela ', 25) : 'Vendas de teste';
        $state->fields[0]['label'] = $field === 'column' ? str_repeat('Valor ', 28) : 'Valor';

        $approval = $this->prepare($user, $this->input($user));

        $this->assertSame(2100, $approval->payload['revision']['dashboard_creation']['preview']['value']);
        Http::assertSent(fn ($request): bool => ($request['method'] ?? '') === 'tools/call' && ($request['params']['name'] ?? '') === 'query' && $request['params']['arguments']['fields'][0]['ref'] === $state->table_name.'.'.$state->fields[0]['label']);
        $this->assertFalse(collect($state->commands)->contains(fn (array $command): bool => array_slice($command, 0, 2) === ['app', 'query']));
    }

    public static function longNativeNames(): array
    {
        return ['table name' => ['table'], 'column label' => ['column']];
    }

    public function test_an_authorized_review_survives_a_different_json_key_order_without_changing_the_grant(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        $payload = $approval->payload;
        $payload['revision']['query_authorization'] = array_reverse($payload['revision']['query_authorization'], true);
        $approval->update(['payload' => $payload]);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame(1, $state->pushes);
        $this->assertTrue($result->payload['execution']['result']['configuration_verified']);
    }

    #[DataProvider('legacyCreationStates')]
    public function test_legacy_reviews_can_be_read_but_cannot_authorize_a_new_creation(bool $alreadySent): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $approval = $this->prepare($user, $this->input($user));
        if ($alreadySent) {
            $state->lost_response = true;
            $approval = $this->execute($user, $approval);
            $this->assertSame('failed', $approval->status);
        }
        $payload = $approval->payload;
        unset($payload['revision']['query_authorization']);
        $selection = $payload['revision']['dashboard_creation']['selection'];
        unset($selection['metric']['query_ref'], $selection['group']['query_ref']);
        $payload['revision']['dashboard_creation']['selection'] = $selection;
        $blueprint = app(BiDashboardBlueprint::class);
        $payload['revision']['selection_digest'] = $blueprint->digest($selection);
        $approval->update(['payload' => $payload, 'fingerprint' => $blueprint->digest(['dashboard-create-review-v1', $user->id, '100', $payload['action'], $payload['revision']])]);
        $state->mcp->update(['status' => 'disconnected', 'credentials' => null]);
        $queriesBefore = count($state->mcp_calls);

        if ($alreadySent) {
            $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/dashboard-creation-verification')->assertOk()->assertJsonPath('approval.status', 'completed');
        } else {
            $result = $this->execute($user, $approval);
            $this->assertSame('failed', $result->status);
            $this->assertArrayNotHasKey('dashboard_creation_attempt', $result->payload);
        }

        $this->assertSame($alreadySent ? 1 : 0, $state->pushes);
        $this->assertCount($queriesBefore, $state->mcp_calls);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public static function legacyCreationStates(): array
    {
        return ['not sent' => [false], 'saved but response lost' => [true]];
    }
}
