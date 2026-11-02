<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
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

class BiAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/app-settings-'.Str::uuid());
        config(['horus.workspace_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function context(string $role = 'editor', ?int $desk = null): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste']]]]);
        $state = (object) ['body' => ['nome' => 'Aplicação atual', 'descricao' => 'Descrição atual', '_state' => ['deskId' => $desk, 'state' => $desk === null ? 'draft' : 'published'], 'dashboards' => [['nome' => 'Indicadores', 'widgets' => [['id' => 7, 'title' => 'Total', 'code' => 'SYNTHETIC-WIDGET-CONTENT']]]], 'tables' => [['tableId' => 500]], 'expressions' => [['name' => 'Valor', 'expression' => 'SUM(VALOR)']], 'config' => ['show_last_loadedat' => true], 'unknown_field' => ['preserve' => true]], 'commands' => [], 'pushes' => 0, 'lint' => 0];

        return [$user, $connection, $state];
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnUsing(function ($connection, $context, bool $readOnly): void {
            $this->assertTrue($readOnly);
            $connection->operationAccessVersion ??= User::findOrFail($connection->user_id)->access_version;
            app(WorkspaceFiles::class)->prepare($context);
        });
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments, $context) use ($state): array {
            $state->commands[] = $arguments;
            $folder = app(WorkspaceFiles::class)->path($context).'/apps';
            $path = $folder.'/application--11.yaml';
            if ($arguments[0] === 'list') {
                if ($state->denied ?? false) {
                    throw new RuntimeException('Sua sessão não tem acesso a este recurso.', 6);
                }
                $rows = $state->missing ?? false ? [] : [['id' => 11, 'nome' => $state->body['nome'], 'deskId' => $state->body['_state']['deskId']]];

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($arguments[0] === 'pull') {
                if (($state->readback_failed ?? false) && $state->pushes > 0) {
                    throw new RuntimeException('Leitura de conferência indisponível.');
                }
                File::ensureDirectoryExists($folder, 0700, true);
                File::put($path, Yaml::dump(['id' => $state->wrong_id ?? 11, 'kind' => $state->wrong_kind ?? 'app', 'tenantId' => $state->wrong_tenant ?? 100, 'lumo' => 'v2'])."---\n".Yaml::dump($state->body, 30, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP));

                return ['data' => []];
            }
            if ($arguments[0] === 'lint') {
                $state->lint++;
                if (isset($state->on_lint)) {
                    ($state->on_lint)($state, $connection);
                }

                return ['exit_code' => ($state->invalid ?? false) ? 8 : 0, 'data' => ['valid' => ! ($state->invalid ?? false), 'errorCount' => ($state->invalid ?? false) ? 1 : 0, 'results' => []]];
            }
            if ($arguments[0] === 'diff') {
                return ['data' => ['workingMissing' => false, 'workingVsState' => ['equal' => true], 'workingVsServer' => ['equal' => ! ($state->different_server ?? false)]]];
            }
            if ($arguments[0] === 'push') {
                $this->assertSame(['push', 'app:11'], $arguments);
                $state->pushes++;
                if ($state->push_denied ?? false) {
                    throw new RuntimeException('O BI recusou a edição desta aplicação.', 6);
                }
                $state->pushed_shape = Yaml::parse(preg_split('/^---\s*$/m', File::get($path), 2)[1], Yaml::PARSE_OBJECT_FOR_MAP);
                $native = function (mixed $value) use (&$native): mixed {
                    if ($value instanceof \stdClass) {
                        $fields = get_object_vars($value);

                        return $fields === [] ? new \stdClass : array_map($native, $fields);
                    }

                    return is_array($value) ? array_map($native, $value) : $value;
                };
                $state->body = $native($state->pushed_shape);
                if (array_key_exists('descricao', $state->body) && $state->body['descricao'] === null) {
                    $state->body['descricao'] = '';
                }
                if ($state->unexpected_change ?? false) {
                    $state->body['dashboards'][0]['nome'] = 'Alterado por outro processo';
                }
                if ($state->zero_read_order ?? false) {
                    foreach ($state->body['dashboards'] as &$dashboard) {
                        $dashboard['body']['ordem'] = 0;
                    }
                    unset($dashboard);
                }
                if ($state->wrong_tab_order ?? false) {
                    $state->body['dashboards'] = array_reverse($state->body['dashboards']);
                }
                if ($state->unexpected_widget_change ?? false) {
                    $state->body['dashboards'][0]['body']['widgets'][0]['type'] = 'Table';
                }
                if ($state->lost_response ?? false) {
                    throw new RuntimeException('Resposta interrompida depois do envio.');
                }

                return ['exit_code' => 0, 'data' => []];
            }
            throw new RuntimeException('Comando inesperado no ensaio: '.json_encode($arguments));
        });
    }

    private function read(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/catalog/100/apps/11/settings')->assertOk()->json();
    }

    private function input(User $user, array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'version' => $this->read($user)['version'], 'name' => 'Visão financeira'], $overrides);
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->fresh();
    }

    public function test_configuration_exposes_names_and_version_without_dashboard_authorship(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);

        $data = $this->read($user);

        $this->assertSame('app:11', $data['resource']);
        $this->assertSame('Aplicação atual', $data['name']);
        $this->assertSame('Descrição atual', $data['description']);
        $this->assertSame('draft', $data['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $data['version']);
        $this->assertArrayNotHasKey('dashboards', $data);
        $this->assertStringNotContainsString('SYNTHETIC-WIDGET-CONTENT', json_encode($data));
        $this->assertSame(0, $state->pushes);
    }

    public static function publicationStates(): array
    {
        return ['rascunho' => [null], 'publicada' => [45]];
    }

    #[DataProvider('publicationStates')]
    public function test_review_and_execution_change_only_the_chosen_details_without_starting_ai(?int $desk): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, $connection, $state] = $this->context(desk: $desk);
        $this->native($state);
        $original = $state->body;

        $approval = $this->prepare($user, $this->input($user, ['description' => 'Indicadores para a equipe']));

        $this->assertSame('pending', $approval->status);
        $this->assertSame(0, $state->pushes);
        $this->assertSame($original, $state->body);
        $this->assertCount(2, $approval->payload['revision']['changes']);
        $this->assertSame($desk === null ? 'draft' : 'published', $approval->payload['revision']['app_details']['state']);
        Queue::assertNotPushed(ExecuteApprovedAction::class);
        $key = 'bi-catalog:v3:'.$connection->id.':session-a:100:section:app';
        Cache::put($key, ['outdated' => true], 90);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status, $result->payload['execution']['message'] ?? '');
        $expected = $original;
        $expected['nome'] = 'Visão financeira';
        $expected['descricao'] = 'Indicadores para a equipe';
        $this->assertSame($expected, $state->body);
        $this->assertSame(1, $state->pushes);
        $this->assertTrue($result->payload['execution']['result']['configuration_verified']);
        $this->assertFalse(Cache::has($key));
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->pushes);
    }

    public function test_description_can_be_cleared_without_resending_other_application_fields(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, ['description' => null]);
        unset($input['name']);
        $approval = $this->prepare($user, $input);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame('Aplicação atual', $state->body['nome']);
        $this->assertSame('', $state->body['descricao']);
        $this->assertSame('', $state->pushed_shape->descricao);
        $this->assertSame('', $result->payload['execution']['result']['confirmed_description']);
        $this->assertSame(1, $result->payload['execution']['result']['changed_fields']);
    }

    public function test_an_already_empty_description_is_not_a_new_change(): void
    {
        [$user, , $state] = $this->context();
        $state->body['descricao'] = '';
        $this->native($state);
        $input = $this->input($user, ['description' => null]);
        unset($input['name']);

        $this->actingAs($user)->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('application');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_cleared_description_can_be_confirmed_after_a_lost_response_without_resending(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, ['description' => null]);
        unset($input['name']);
        $approval = $this->prepare($user, $input);
        $state->lost_response = true;

        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.confirmed_description', '')->assertJsonPath('approval.payload.reconciliation.remote_read_only', true);

        $this->assertSame(1, $state->pushes);
        $this->assertSame('', $state->body['descricao']);
    }

    public function test_repeating_a_request_recovers_the_same_review_and_refuses_different_input(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $first = $this->prepare($user, $input);

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertOk()->assertJsonPath('approval.id', $first->id)->assertJsonPath('reused', true);
        $this->postJson('/api/catalog/100/apps/11/settings/actions', [...$input, 'name' => 'Outra proposta'])->assertConflict();

        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->pushes);
    }

    public static function invalidInputs(): array
    {
        return ['nome vazio' => [['name' => ' '], 'name'], 'nome grande' => [['name' => str_repeat('A', 101)], 'name'], 'nome como lista' => [['name' => ['x']], 'name'], 'controle no nome' => [['name' => "Te\x00ste"], 'name'], 'descrição grande' => [['description' => str_repeat('A', 4001)], 'description'], 'controle na descrição' => [['description' => "Texto\x01"], 'description'], 'versão inválida' => [['version' => 'old'], 'version'], 'identificador inválido' => [['request_id' => 'invalid'], 'request_id'], 'campo estrutural' => [['dashboards' => []], 'application']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_changes_return_422_without_creating_a_review(array $override, string $field): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, $override);

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_an_unchanged_proposal_returns_422(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $this->input($user, ['name' => 'Aplicação atual']))->assertUnprocessable()->assertJsonValidationErrors('application');

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_a_source_changed_after_opening_returns_409_before_review(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $state->body['dashboards'][0]['nome'] = 'Nova revisão';

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertConflict();

        $this->assertDatabaseCount('approvals', 0);
    }

    public static function rejectedHeaders(): array
    {
        return ['outro tenant' => ['wrong_tenant', 200], 'outro recurso' => ['wrong_id', 12], 'outro tipo' => ['wrong_kind', 'table'], 'tenant estruturado' => ['wrong_tenant', [100]], 'id estruturado' => ['wrong_id', [11]]];
    }

    #[DataProvider('rejectedHeaders')]
    public function test_a_document_of_another_identity_is_not_used_for_editing(string $property, mixed $value): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $state->{$property} = $value;

        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/settings')->assertUnprocessable();

        $this->assertSame(0, $state->pushes);
    }

    public function test_missing_resource_returns_404_and_native_refusal_is_not_a_successful_read(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $state->missing = true;
        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/settings')->assertNotFound();
        $state->missing = false;
        $state->denied = true;

        $this->getJson('/api/catalog/100/apps/11/settings')->assertUnprocessable();

        $this->assertSame(0, $state->pushes);
    }

    public function test_other_tenants_and_readers_cannot_prepare_changes(): void
    {
        [$user, , $state] = $this->context('viewer');
        $this->native($state);
        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/settings')->assertForbidden();
        $this->postJson('/api/catalog/100/apps/11/settings/actions', [])->assertForbidden();
        $user->forceFill(['role' => 'editor'])->save();
        $this->assertSame('editor', $user->fresh()->role);

        $this->actingAs($user->fresh())->getJson('/api/catalog/200/apps/11/settings')->assertForbidden();
        $this->postJson('/api/catalog/200/apps/11/settings/actions', [])->assertForbidden();

        $this->assertSame([], $state->commands);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_guest_requests_require_authentication(): void
    {
        $this->getJson('/api/catalog/100/apps/11/settings')->assertUnauthorized();
        $this->postJson('/api/catalog/100/apps/11/settings/actions', [])->assertUnauthorized();
    }

    public function test_source_drift_after_review_prevents_the_native_write(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->body['config']['show_last_loadedat'] = false;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
        $this->assertSame('Aplicação atual', $state->body['nome']);
    }

    public function test_pending_deletion_prevents_review_and_also_blocks_an_already_approved_edit(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $approval = $this->prepare($user, $input);
        ManagedBiResource::create(['user_id' => $user->id, 'tenant_id' => '100', 'resource' => 'app:11', 'origin' => 'manual', 'status' => 'deletion_pending', 'receipt' => []]);

        $this->postJson('/api/catalog/100/apps/11/settings/actions', [...$input, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_lost_response_is_verified_by_reading_without_a_second_push_or_ai_task(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->lost_response = true;
        $failed = $this->execute($user, $approval);
        $this->assertSame('failed', $failed->status);

        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.configuration_verified', true)->assertJsonPath('approval.payload.reconciliation.remote_read_only', true);

        $this->assertSame(1, $state->pushes);
        $this->assertSame('Visão financeira', $state->body['nome']);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function unconfirmedOutcomes(): array
    {
        return ['conferência indisponível' => ['readback_failed', 'Não foi possível confirmar esta alteração'], 'outro conteúdo mudou' => ['unexpected_change', 'Não foi possível confirmar esta alteração'], 'diferença com servidor' => ['different_server', 'Não foi possível confirmar esta alteração'], 'recusa nativa' => ['push_denied', 'com o acesso atual']];
    }

    #[DataProvider('unconfirmedOutcomes')]
    public function test_an_unconfirmed_result_is_not_reported_as_success_or_retried(string $property, string $message): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->{$property} = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString($message, $result->payload['execution']['message']);
        $this->assertSame(1, $state->pushes);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertUnprocessable();
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(1, $state->pushes);
    }

    public function test_other_users_cannot_verify_an_operation_and_reconnection_does_not_replay_it(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->lost_response = true;
        $this->execute($user, $approval);
        $other = User::factory()->create(['role' => 'editor']);
        $this->actingAs($other)->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertNotFound();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);

        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertOk()->assertJsonPath('approval.status', 'completed');

        $this->assertSame(1, $state->pushes);
    }

    public function test_a_zero_description_is_preserved_as_text(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, ['description' => '0']);
        unset($input['name']);
        $approval = $this->prepare($user, $input);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame('0', $state->body['descricao']);
    }

    public function test_read_only_tenant_refuses_preparation_without_a_native_write(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        config(['horus.read_only_tenants' => ['100']]);

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertUnprocessable();

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_schema_refusal_prevents_review_and_preserves_the_application(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $original = $state->body;
        $state->invalid = true;

        $this->postJson('/api/catalog/100/apps/11/settings/actions', $input)->assertUnprocessable();

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame($original, $state->body);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_changed_review_is_refused_by_the_worker_before_writing(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $payload = $approval->payload;
        $payload['action']['app_changes']['nome'] = 'Alteração fora da revisão';
        $approval->update(['payload' => $payload]);

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_reconnecting_before_execution_requires_a_new_review(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_access_revoked_during_validation_prevents_the_native_push(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->on_lint = function (object $current, Connection $connection): void {
            if ($current->lint === 2) {
                User::findOrFail($connection->user_id)->forceFill(['role' => 'viewer'])->save();
            }
        };

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_expiry_after_approval_prevents_a_delayed_worker_from_writing(): void
    {
        Queue::fake();
        $this->freezeTime();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $this->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        $this->travel(13)->minutes();

        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_verification_requires_an_ended_attempt_and_never_starts_one(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));

        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertConflict();
        $approval->update(['status' => 'failed']);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertConflict();

        $this->assertSame(0, $state->pushes);
    }

    public function test_expiry_during_validation_prevents_the_native_push(): void
    {
        Queue::fake();
        $this->freezeTime();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->on_lint = function (object $current): void {
            if ($current->lint === 2) {
                $this->travel(13)->minutes();
            }
        };

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_editing_identification_preserves_empty_sequences_and_empty_mappings(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body['expressions'] = [];
        $state->body['reports'] = [];
        $state->body['config']['show_last_loadedat_tables'] = [];
        $state->body['config']['empty_options'] = new \stdClass;
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame([], $state->pushed_shape->expressions);
        $this->assertSame([], $state->pushed_shape->reports);
        $this->assertSame([], $state->pushed_shape->config->show_last_loadedat_tables);
        $this->assertInstanceOf(\stdClass::class, $state->pushed_shape->config->empty_options);
    }

    private function dashboardContext(?int $desk = null): array
    {
        [$user, $connection, $state] = $this->context(desk: $desk);
        $state->body['dashboards'] = [
            ['header' => ['kind' => 'app-dashboard', 'lumo' => 'v2', 'id' => 21, 'tenantId' => 100, 'appId' => 11], 'body' => ['nome' => 'Resumo', 'ordem' => 0, 'filters' => [['id' => 77]], 'widgets' => [['id' => 7, 'type' => 'Svelte', 'code' => 'SYNTHETIC-WIDGET-CONTENT']], 'future_setting' => (object) []]],
            ['header' => ['kind' => 'app-dashboard', 'lumo' => 'v2', 'id' => 22, 'tenantId' => 100, 'appId' => 11], 'body' => ['nome' => 'Detalhes', 'ordem' => 1, 'filters' => [], 'widgets' => [['id' => 8, 'type' => 'Kpi', 'chart' => ['dataSource' => '123:SUM']]], 'future_setting' => ['keep' => true]]],
        ];
        $this->native($state);

        return [$user, $connection, $state];
    }

    private function dashboardInput(User $user): array
    {
        $version = $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboards')->assertOk()->json('version');

        return ['request_id' => (string) Str::uuid(), 'version' => $version, 'dashboards' => [['id' => 22, 'name' => 'Detalhes financeiros'], ['id' => 21, 'name' => 'Resumo']]];
    }

    private function prepareDashboards(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/11/dashboards/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    public function test_dashboard_organization_reads_only_navigation_metadata_in_current_display_order(): void
    {
        [$user, , $state] = $this->dashboardContext();
        $state->body['dashboards'] = array_reverse($state->body['dashboards']);

        $response = $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboards')->assertOk();

        $response->assertJsonPath('dashboards', [['id' => 21, 'name' => 'Resumo', 'order' => 0, 'widget_count' => 1], ['id' => 22, 'name' => 'Detalhes', 'order' => 1, 'widget_count' => 1]]);
        $this->assertStringNotContainsString('SYNTHETIC-WIDGET-CONTENT', $response->getContent());
        $this->assertSame(0, $state->pushes);
    }

    public function test_authorized_dashboard_organization_preserves_every_other_setting_and_identifier(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $original = $state->body;
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $this->assertSame(0, $state->pushes);
        $this->assertSame($original, $state->body);
        $this->assertSame('Organizar dashboards da aplicação', $approval->title);
        $this->assertSame('Resumo', $approval->payload['revision']['dashboard_edit']['before'][0]['name']);
        $this->assertSame('Detalhes financeiros', $approval->payload['revision']['dashboard_edit']['after'][0]['name']);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertTrue($result->payload['execution']['result']['dashboards_verified']);
        $this->assertTrue($result->payload['execution']['result']['configuration_verified']);
        $this->assertSame(1, $state->pushes);
        $expected = $original;
        $expected['dashboards'] = array_reverse($expected['dashboards']);
        $expected['dashboards'][0]['body']['nome'] = 'Detalhes financeiros';
        $expected['dashboards'][0]['body']['ordem'] = 0;
        $expected['dashboards'][1]['body']['ordem'] = 1;
        $this->assertSame(json_encode($expected), json_encode($state->body));
        Queue::assertNotPushed(RunAgentTurn::class);
        $this->assertSame([], File::directories($this->root.'/'.$user->id));
    }

    public function test_published_dashboard_organization_explains_duplication_and_never_writes(): void
    {
        [$user, , $state] = $this->dashboardContext(desk: 45);
        $input = $this->dashboardInput($user);

        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', $input)->assertConflict()->assertJsonPath('message', 'Esta aplicação está publicada. Duplique para sua mesa antes de organizar seus dashboards.');

        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidDashboardInputs(): array
    {
        return [
            'remove a tab' => ['dashboards', [['id' => 21, 'name' => 'Resumo']], 409],
            'adds another tab' => ['dashboards', [['id' => 21, 'name' => 'Resumo'], ['id' => 23, 'name' => 'Outro']], 409],
            'duplicate identity' => ['dashboards', [['id' => 21, 'name' => 'Resumo'], ['id' => 21, 'name' => 'Outro']], 422],
            'empty name' => ['dashboards', [['id' => 21, 'name' => '   '], ['id' => 22, 'name' => 'Outro']], 422],
            'hidden widget modification' => ['dashboards', [['id' => 21, 'name' => 'Resumo', 'widgets' => []], ['id' => 22, 'name' => 'Outro']], 422],
            'empty list' => ['dashboards', [], 422],
            'name too long' => ['dashboards', [['id' => 21, 'name' => str_repeat('a', 101)], ['id' => 22, 'name' => 'Outro']], 422],
            'control character' => ['dashboards', [['id' => 21, 'name' => "Resumo\nlinha"], ['id' => 22, 'name' => 'Outro']], 422],
            'foreign app changes' => ['name', 'Outro app', 422],
            'source version' => ['version', str_repeat('f', 64), 409],
        ];
    }

    #[DataProvider('invalidDashboardInputs')]
    public function test_invalid_dashboard_proposals_do_not_create_reviews_or_change_native_content(string $key, mixed $value, int $status): void
    {
        [$user, , $state] = $this->dashboardContext();
        $input = $this->dashboardInput($user);
        $original = $state->body;

        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', [...$input, $key => $value])->assertStatus($status);

        $this->assertSame(0, $state->pushes);
        $this->assertSame($original, $state->body);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_dashboard_organization_requires_a_change_and_reuses_only_the_same_proposal(): void
    {
        [$user] = $this->dashboardContext();
        $input = $this->dashboardInput($user);
        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', [...$input, 'dashboards' => [['id' => 21, 'name' => 'Resumo'], ['id' => 22, 'name' => 'Detalhes']]])->assertUnprocessable()->assertJsonValidationErrors('application');
        $first = $this->prepareDashboards($user, $input);
        $second = $this->prepareDashboards($user, $input);
        $this->assertSame($first->id, $second->id);

        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', [...$input, 'dashboards' => [['id' => 22, 'name' => 'Mudança posterior'], ['id' => 21, 'name' => 'Resumo']]])->assertConflict();

        $this->assertDatabaseCount('approvals', 1);
    }

    public function test_dashboard_drift_and_a_new_publication_after_review_prevent_writes(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $state->body['_state'] = ['deskId' => 45, 'state' => 'published'];

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $state->pushes);
        $this->assertSame('Resumo', $state->body['dashboards'][0]['body']['nome']);
    }

    public function test_lost_dashboard_edit_response_is_confirmed_without_resending_the_edit(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $state->lost_response = true;
        $result = $this->execute($user, $approval);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $state->pushes);
        $connection = Connection::where('user_id', $user->id)->firstOrFail();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'renewed']]);

        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.dashboards_verified', true);

        $this->assertSame(1, $state->pushes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_an_unexpected_widget_change_prevents_a_verified_dashboard_receipt(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $state->unexpected_widget_change = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertArrayNotHasKey('result', $result->payload['execution']);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertUnprocessable();
        $this->assertSame(1, $state->pushes);
    }

    public function test_dashboard_organization_respects_authentication_roles_and_tenant_scope(): void
    {
        $this->getJson('/api/catalog/100/apps/11/dashboards')->assertUnauthorized();
        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', [])->assertUnauthorized();
        [$user, , $state] = $this->dashboardContext();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->actingAs($user->fresh())->getJson('/api/catalog/100/apps/11/dashboards')->assertForbidden();
        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', [])->assertForbidden();
        $user->forceFill(['role' => 'editor'])->save();

        $this->actingAs($user->fresh())->getJson('/api/catalog/200/apps/11/dashboards')->assertForbidden();
        $this->postJson('/api/catalog/200/apps/11/dashboards/actions', [])->assertForbidden();

        $this->assertSame([], $state->commands);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_incomplete_dashboard_identity_is_rejected_before_showing_an_editable_list(): void
    {
        [$user, , $state] = $this->dashboardContext();
        $state->body['dashboards'][0]['header']['appId'] = 12;

        $this->actingAs($user)->getJson('/api/catalog/100/apps/11/dashboards')->assertUnprocessable();

        $this->assertSame(0, $state->pushes);
    }

    public function test_a_published_desk_with_a_contradictory_draft_label_cannot_be_organized(): void
    {
        [$user, , $state] = $this->dashboardContext(desk: 45);
        $state->body['_state']['state'] = 'draft';
        $input = $this->dashboardInput($user);

        $this->postJson('/api/catalog/100/apps/11/dashboards/actions', $input)->assertConflict();

        $this->assertSame(0, $state->pushes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_native_zero_position_projection_is_verified_only_with_the_exact_ordered_id_sequence(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $state->zero_read_order = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertTrue($result->payload['execution']['result']['dashboards_verified']);
        $this->assertSame([22, 21], array_column(array_column($state->body['dashboards'], 'header'), 'id'));
        $this->assertSame(1, $state->pushes);
    }

    public function test_ignored_tab_reordering_cannot_be_presented_as_a_verified_save(): void
    {
        Queue::fake();
        [$user, , $state] = $this->dashboardContext();
        $approval = $this->prepareDashboards($user, $this->dashboardInput($user));
        $state->zero_read_order = true;
        $state->wrong_tab_order = true;

        $result = $this->execute($user, $approval);

        $this->assertSame('failed', $result->status);
        $this->assertArrayNotHasKey('result', $result->payload['execution']);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-settings-verification')->assertUnprocessable();
        $this->assertSame(1, $state->pushes);
    }
}
