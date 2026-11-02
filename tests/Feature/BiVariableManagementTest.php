<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiVariableDrafts;
use App\Services\LumoClient;
use App\Services\UserAccessRevocation;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiVariableManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/variables-'.Str::uuid());
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
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'variables-session', 'tenants' => [['id' => '100', 'name' => 'Teste']]]]);
        $state = (object) ['body' => (object) ['items' => [(object) ['key' => 'WINDOW_DAYS', 'is_secret' => false, 'value' => '31', 'description' => 'Janela de teste', 'agent_overrides' => [(object) ['tokenId' => 7, 'value' => '15']], 'extra' => (object) ['keep' => []]], (object) ['key' => 'SERVICE_TOKEN', 'is_secret' => true, 'description' => 'Segredo fictício', 'agent_overrides' => [(object) ['tokenId' => 7]]]]], 'pushes' => 0, 'commands' => []];

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
            $path = app(WorkspaceFiles::class)->path($context).'/variables/tenant.yaml';
            if ($arguments[0] === 'list') {
                if ($arguments[1] === 'agent') {
                    return ['data' => ['results' => [['id' => 7, 'nome' => 'Agente local'], ['id' => 9, 'nome' => 'Agente adicional']], 'matched' => 2]];
                }
                if ($state->denied ?? false) {
                    throw new RuntimeException('Sem acesso às variáveis.', 6);
                }

                return ['data' => ['variables' => array_fill_keys(array_map(fn ($item) => $item->key, $state->body->items), '[protegido]'), 'count' => count($state->body->items)]];
            }
            if ($arguments[0] === 'pull') {
                if (($state->readback_failed ?? false) && $state->pushes > 0) {
                    throw new RuntimeException('Leitura interrompida.');
                }
                File::ensureDirectoryExists(dirname($path), 0700, true);
                File::put($path, Yaml::dump(['kind' => 'variables', 'lumo' => 'v2', 'tenantId' => $state->wrong_tenant ?? 100])."---\n".Yaml::dump($state->body, 30, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

                return ['exit_code' => 0, 'data' => []];
            }
            if ($arguments[0] === 'status') {
                return ['data' => ['tenantId' => 100, 'clean' => ($state->masked ?? false) ? [] : ['variables:1  (variables/tenant.yaml)']]];
            }
            if ($arguments[0] === 'lint') {
                if (isset($state->on_lint)) {
                    ($state->on_lint)($state, $connection);
                }

                return ['exit_code' => ($state->invalid ?? false) ? 8 : 0, 'data' => ['valid' => ! ($state->invalid ?? false), 'errorCount' => ($state->invalid ?? false) ? 1 : 0, 'results' => []]];
            }
            if ($arguments[0] === 'push') {
                $this->assertSame(['push', 'variables'], $arguments);
                $state->pushes++;
                if ($state->push_denied ?? false) {
                    throw new RuntimeException('Permissão nativa recusada.', 6);
                }
                $state->pushed = Yaml::parse(preg_split('/^---\s*$/m', File::get($path), 2)[1], Yaml::PARSE_OBJECT_FOR_MAP);
                $state->body = json_decode(json_encode($state->pushed));
                foreach ($state->body->items as $item) {
                    if ($item->is_secret) {
                        unset($item->value);
                        foreach ($item->agent_overrides ?? [] as $override) {
                            unset($override->value);
                        }
                    }
                }
                if ($state->unexpected_change ?? false) {
                    $state->body->items[0]->description = 'Mudança concorrente';
                }
                if ($state->lost_response ?? false) {
                    throw new RuntimeException('Resposta perdida depois do envio.');
                }

                return ['exit_code' => 0, 'data' => ['pushed' => ['variables']]];
            }
            if ($arguments[0] === 'diff') {
                return ['data' => ['workingMissing' => false, 'workingVsState' => ['equal' => true], 'workingVsServer' => ['equal' => ! ($state->different_server ?? false)]]];
            }
            throw new RuntimeException('Comando inesperado: '.json_encode($arguments));
        });
    }

    private function input(User $user, array $changes = []): array
    {
        $version = $this->actingAs($user)->getJson('/api/catalog/100/variables/options')->assertOk()->json('version');

        return array_replace(['action' => 'create_variable', 'key' => 'QA_WINDOW', 'value' => '7', 'is_secret' => false, 'description' => 'Configuração fictícia', 'request_id' => (string) Str::uuid(), 'version' => $version], $changes);
    }

    private function prepare(User $user, array $input): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $input)->assertOk();

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): Approval
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        return $approval->fresh();
    }

    public function test_configuration_returns_metadata_without_stored_values(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->getJson('/api/catalog/100/variables/'.bin2hex('WINDOW_DAYS').'/configuration')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('variable.key', 'WINDOW_DAYS')->assertJsonPath('variable.is_secret', false)->assertJsonPath('variable.agent_overrides.0.agent_id', 7);
        $this->assertArrayNotHasKey('value', $response->json('variable'));
        $this->assertArrayNotHasKey('value', $response->json('variable.agent_overrides.0'));
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_reviewed_creation_preserves_other_variables_and_does_not_start_ai(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $original = json_encode($state->body->items);
        $approval = $this->prepare($user, $this->input($user));
        $this->assertSame(0, $state->pushes);

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame($original, json_encode(array_slice($state->body->items, 0, 2)));
        $this->assertSame('7', $state->body->items[2]->value);
        $this->assertTrue($result->payload['execution']['result']['configuration_verified']);
        $this->assertTrue($result->payload['execution']['result']['values_verified']);
        $this->assertSame(1, $state->pushes);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    private function updateInput(User $user, string $key, array $changes): array
    {
        $input = $this->input($user);
        unset($input['value'], $input['is_secret'], $input['description']);

        return [...$input, 'action' => 'update_variable', 'key' => $key, ...$changes];
    }

    public function test_secret_values_are_encrypted_and_absent_from_reviews_status_and_audit(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, ['is_secret' => true, 'value' => 'synthetic-global-secret', 'agent_overrides' => [['agent_id' => 7, 'value' => 'synthetic-agent-secret']]]);
        $approval = $this->prepare($user, $input);
        $encrypted = Cache::get(app(BiVariableDrafts::class)->key($approval));
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString('synthetic-global-secret', $encrypted);
        $this->assertStringNotContainsString('synthetic-agent-secret', json_encode($approval->toArray()));

        $result = $this->execute($user, $approval);

        $this->assertSame('completed', $result->status);
        $this->assertSame('synthetic-global-secret', $state->pushed->items[2]->value);
        $this->assertSame('synthetic-agent-secret', $state->pushed->items[2]->agent_overrides[0]->value);
        $this->assertFalse($result->payload['execution']['result']['values_verified']);
        $this->assertTrue($result->payload['execution']['result']['secret_values_write_acknowledged']);
        $this->assertFalse($result->payload['execution']['result']['secret_values_read_back']);
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
        $response = $this->getJson('/api/bi-actions/'.$approval->id)->assertOk();
        $this->assertStringNotContainsString('synthetic-global-secret', $response->getContent());
        $this->assertStringNotContainsString('synthetic-agent-secret', json_encode(DB::table('audit_events')->get()));
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_editing_a_description_preserves_values_and_agent_definitions(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->updateInput($user, 'WINDOW_DAYS', ['description' => 'Nova explicação']));
        $result = $this->execute($user, $approval);
        $this->assertSame('completed', $result->status);
        $this->assertSame('Nova explicação', $state->body->items[0]->description);
        $this->assertSame('31', $state->body->items[0]->value);
        $this->assertSame('15', $state->body->items[0]->agent_overrides[0]->value);
        $this->assertSame([], $state->body->items[0]->extra->keep);
    }

    public function test_multiline_values_keep_spaces_and_terminal_newlines(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $value = "  if enabled:\n    print('synthetic')\n\n";
        $approval = $this->prepare($user, $this->input($user, ['value' => $value]));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertSame($value, $state->body->items[2]->value);
    }

    public function test_secret_metadata_changes_do_not_resubmit_placeholders(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body->items[1]->value = '[masked-secret-placeholder]';
        $state->body->items[1]->agent_overrides[0]->value = '[masked-agent-placeholder]';
        $this->native($state);
        $approval = $this->prepare($user, $this->updateInput($user, 'SERVICE_TOKEN', ['description' => 'Descrição revisada']));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertObjectNotHasProperty('value', $state->pushed->items[1]);
        $this->assertObjectNotHasProperty('value', $state->pushed->items[1]->agent_overrides[0]);
    }

    public function test_override_edits_preserve_omitted_values_and_remove_only_selected_agents(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $state->body->items[0]->agent_overrides[] = (object) ['tokenId' => 9, 'value' => '20'];
        $this->native($state);
        $approval = $this->prepare($user, $this->updateInput($user, 'WINDOW_DAYS', ['agent_overrides' => [['agent_id' => 7]]]));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertCount(1, $state->body->items[0]->agent_overrides);
        $this->assertSame('15', $state->body->items[0]->agent_overrides[0]->value);
        $this->assertSame('31', $state->body->items[0]->value);
    }

    public function test_empty_override_list_explicitly_restores_the_default_for_all_agents(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->updateInput($user, 'WINDOW_DAYS', ['agent_overrides' => []]));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertSame([], $state->body->items[0]->agent_overrides);
        $this->assertSame('31', $state->body->items[0]->value);
    }

    public function test_plain_zero_and_explicit_empty_values_are_saved_as_text(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user, ['value' => '0', 'agent_overrides' => [['agent_id' => 7, 'value' => '']]]));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertSame('0', $state->body->items[2]->value);
        $this->assertSame('', $state->body->items[2]->agent_overrides[0]->value);
    }

    public function test_a_secret_cannot_be_made_readable_without_replacing_all_retained_values(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->updateInput($user, 'SERVICE_TOKEN', ['is_secret' => false, 'value' => 'new-global']);
        $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('variable');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_replacing_all_values_allows_explicit_removal_of_secret_protection(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->updateInput($user, 'SERVICE_TOKEN', ['is_secret' => false, 'value' => 'new-global', 'agent_overrides' => [['agent_id' => 7, 'value' => 'new-agent']]]));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertFalse($state->body->items[1]->is_secret);
        $this->assertSame('new-agent', $state->body->items[1]->agent_overrides[0]->value);
    }

    public function test_a_lost_plain_value_response_is_verified_without_another_push(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->lost_response = true;
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/variable-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.reconciliation.remote_read_only', true);
        $this->assertSame(1, $state->pushes);
    }

    public function test_a_lost_secret_response_is_not_claimed_as_verified_from_masked_metadata(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user, ['is_secret' => true, 'value' => 'synthetic-private']));
        $state->lost_response = true;
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/variable-verification')->assertUnprocessable()->assertJsonValidationErrors('variable');
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(1, $state->pushes);
    }

    public function test_an_acknowledged_secret_write_can_be_checked_after_a_readback_failure(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user, ['is_secret' => true, 'value' => 'synthetic-private']));
        $state->readback_failed = true;
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $state->readback_failed = false;
        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/variable-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.values_verified', false);
        $this->assertSame(1, $state->pushes);
    }

    public function test_an_identical_retry_recovers_the_same_review_but_different_values_conflict(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $approval = $this->prepare($user, $input);
        $this->postJson('/api/catalog/100/variables/actions', $input)->assertOk()->assertJsonPath('approval.id', $approval->id)->assertJsonPath('reused', true);
        $this->postJson('/api/catalog/100/variables/actions', [...$input, 'value' => 'different'])->assertConflict();
        $this->assertDatabaseCount('approvals', 1);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_changed_variable_map_prevents_execution_before_any_push(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->body->items[0]->value = '100';
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->assertSame(0, $state->pushes);
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
    }

    public function test_unexpected_remote_changes_prevent_a_success_receipt(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->unexpected_change = true;
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->assertSame(1, $state->pushes);
    }

    public function test_rejection_discards_the_private_input_without_writing(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'rejected', 'fingerprint' => $approval->fingerprint])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
        $this->assertSame(0, $state->pushes);
        Queue::assertNothingPushed();
    }

    public function test_a_missing_private_draft_fails_without_a_native_write(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        app(BiVariableDrafts::class)->forget($approval);
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_viewers_and_foreign_tenants_cannot_open_the_private_editor(): void
    {
        [$user] = $this->context('viewer');
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->getJson('/api/catalog/100/variables/options')->assertForbidden();
        $user->forceFill(['role' => 'editor'])->save();
        $this->getJson('/api/catalog/999/variables/options')->assertForbidden();
    }

    public function test_guests_cannot_prepare_variable_configuration(): void
    {
        $this->postJson('/api/catalog/100/variables/actions', [])->assertUnauthorized();
    }

    public function test_masked_reference_workspaces_cannot_prepare_writes(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        $state->masked = true;
        $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('variable');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_role_revocation_during_validation_prevents_the_push(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $state->on_lint = function () use ($user): void {
            $user->fresh()->forceFill(['role' => 'viewer'])->save();
        };
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->assertSame('viewer', $user->fresh()->role);
        $this->assertSame(0, $state->pushes);
    }

    public function test_other_users_cannot_read_or_reconcile_the_review(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/bi-actions/'.$approval->id)->assertNotFound();
        $this->postJson('/api/bi-actions/'.$approval->id.'/variable-verification')->assertNotFound();
    }

    public function test_nothing_can_be_reconciled_before_an_ended_attempt(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $this->actingAs($user)->postJson('/api/bi-actions/'.$approval->id.'/variable-verification')->assertConflict();
        $this->assertSame(0, $state->pushes);
    }

    public static function invalidInputs(): array
    {
        return [
            'nome minúsculo' => [['key' => 'lower_case'], 'key'],
            'nome inicia em número' => [['key' => '1_VAR'], 'key'],
            'nome com comando' => [['key' => 'NAME;COMMAND'], 'key'],
            'valor não textual' => [['value' => ['x']], 'value'],
            'valor com nulo' => [['value' => "synthetic\x00value"], 'value'],
            'valor acima do limite' => [['value' => str_repeat('x', 65537)], 'value'],
            'descrição estruturada' => [['description' => ['x']], 'description'],
            'campo não autorizado' => [['force' => true], 'variable'],
            'agente estrangeiro' => [['agent_overrides' => [['agent_id' => 999, 'value' => 'x']]], 'agent_overrides'],
            'agente duplicado em tipos diferentes' => [['agent_overrides' => [['agent_id' => 7, 'value' => 'x'], ['agent_id' => '7', 'value' => 'y']]], 'agent_overrides.0.agent_id'],
            'agente booleano' => [['agent_overrides' => [['agent_id' => true, 'value' => 'x']]], 'agent_overrides'],
            'novo agente sem valor' => [['agent_overrides' => [['agent_id' => 7]]], 'agent_overrides'],
            'campo de agente não autorizado' => [['agent_overrides' => [['agent_id' => 7, 'value' => 'x', 'tenant' => 999]]], 'agent_overrides.0'],
            'versão inválida' => [['version' => 'old'], 'version'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_configuration_returns_422_without_a_review_or_write(array $changes, string $field): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user, $changes);
        $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_read_only_tenants_refuse_variable_writes(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input($user);
        config(['horus.read_only_tenants' => ['100']]);
        $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $input)->assertUnprocessable()->assertJsonValidationErrors('variable');
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_changed_connection_generation_invalidates_the_review(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'new-session']]);
        $this->assertSame('failed', $this->execute($user, $approval)->status);
        $this->assertSame(0, $state->pushes);
    }

    public function test_an_existing_name_cannot_be_created_again(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/variables/actions', $this->input($user, ['key' => 'WINDOW_DAYS']))->assertConflict();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->pushes);
    }

    public function test_a_wrong_document_tenant_is_not_accepted_as_editable_configuration(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $state->wrong_tenant = 999;
        $this->actingAs($user)->getJson('/api/catalog/100/variables/options')->assertUnprocessable()->assertJsonValidationErrors('variable');
        $this->assertSame(0, $state->pushes);
    }

    public function test_the_same_job_cannot_send_private_values_twice(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->pushes);
    }

    public function test_bi_disconnection_discards_pending_private_values(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $this->actingAs($user)->deleteJson('/api/connections/horus')->assertOk();
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
        $this->assertSame(0, $state->pushes);
        Queue::assertNothingPushed();
    }

    public function test_access_revocation_discards_pending_private_values(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        app(UserAccessRevocation::class)->revoke($user);
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
        $this->assertSame(0, $state->pushes);
    }

    public function test_expired_reviews_are_closed_without_executing_the_variable_write(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $approval->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ganalise:expire-reviews')->assertSuccessful();
        $this->assertSame('expired', $approval->fresh()->status);
        $this->assertFalse(Cache::has(app(BiVariableDrafts::class)->key($approval)));
        $this->assertSame(0, $state->pushes);
    }

    public function test_updating_invalidates_only_the_same_connection_tenant_catalog(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, $this->input($user));
        $key = 'bi-catalog:v3:'.$connection->id.':variables-session:100';
        Cache::put($key, ['old' => true], 600);
        Cache::put($key.':section:variables', ['old' => true], 600);
        $other = 'bi-catalog:v3:'.$connection->id.':variables-session:999:section:variables';
        Cache::put($other, ['keep' => true], 600);
        $this->assertSame('completed', $this->execute($user, $approval)->status);
        $this->assertFalse(Cache::has($key));
        $this->assertFalse(Cache::has($key.':section:variables'));
        $this->assertSame(['keep' => true], Cache::get($other));
    }
}
