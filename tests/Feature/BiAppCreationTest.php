<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BiAppCreationTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'editor'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
        $state = (object) ['creations' => 0, 'commands' => [], 'tables' => [['id' => 500, 'nome' => 'Vendas de teste']], 'response' => ['id' => 12], 'body' => ['nome' => 'Minha análise', '_state' => ['deskId' => null, 'state' => 'draft'], 'tables' => [['tableId' => 500]], 'dashboards' => []]];

        return [$user, $connection, $state];
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments, $project) use ($state): array {
            $this->assertSame('100', (string) $project->tenant_id);
            $state->commands[] = $arguments;
            if ($arguments[0] === 'list') {
                if ($state->denied ?? false) {
                    throw new RuntimeException('Sua sessão não tem acesso a este recurso.', 6);
                }

                return ['data' => ['results' => $arguments[1] === 'table' ? $state->tables : [['id' => 11, 'nome' => 'Aplicação existente']], 'matched' => $arguments[1] === 'table' ? count($state->tables) : 1]];
            }
            if ($arguments[0] === 'new') {
                $state->creations++;
                $this->assertSame($state->expected_command ?? ['new', 'app', '--name', 'Minha análise', '--table', 'table:500'], $arguments);
                if ($state->send_timeout ?? false) {
                    throw new RuntimeException('Resposta perdida após envio.');
                }

                return ['data' => $state->response];
            }
            if ($arguments[0] === 'info') {
                if ($state->readback_failed ?? false) {
                    throw new RuntimeException('Conferência indisponível.');
                }

                return ['data' => ['payload' => ['header' => $state->header ?? ['kind' => 'app', 'id' => 12, 'tenantId' => 100], 'body' => $state->body]]];
            }
            throw new RuntimeException('Unexpected native command.');
        });
    }

    private function input(array $changes = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'name' => 'Minha análise', 'objective' => 'Conferir receitas por região.', 'tables' => ['table:500']], ...$changes];
    }

    private function prepare(User $user, ?array $input = null): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/actions', $input ?? $this->input())->assertOk()->assertJsonPath('approval.status', 'pending');

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_guest_and_read_only_profile_cannot_prepare_an_application(): void
    {
        $this->postJson('/api/catalog/100/apps/actions', $this->input())->assertUnauthorized();
        [$user] = $this->context('viewer');
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/apps/actions', $this->input())->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_unauthorized_tenant_and_unknown_table_do_not_create_a_project_or_review(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/200/apps/actions', $this->input())->assertForbidden();
        $this->postJson('/api/catalog/100/apps/actions', $this->input(['tables' => ['table:501']]))->assertNotFound();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->creations);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_refused_without_native_writes(array $changes): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/apps/actions', $this->input($changes))->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->creations);
    }

    public static function invalidInput(): array
    {
        return [
            'empty name' => [['name' => '  ']],
            'name too long' => [['name' => str_repeat('x', 101)]],
            'control characters' => [['name' => "App\ncomando"]],
            'objective too long' => [['objective' => str_repeat('x', 2001)]],
            'foreign resource kind' => [['tables' => ['flow:500']]],
            'invalid table' => [['tables' => ['table:0']]],
            'duplicate table' => [['tables' => ['table:500', 'table:500']]],
            'too many tables' => [['tables' => array_map(fn ($i) => 'table:'.$i, range(1, 9))]],
            'unexpected fields' => [['desk' => 999]],
            'invalid request id' => [['request_id' => 'same-request']],
        ];
    }

    public function test_preparation_is_idempotent_and_does_not_create_remote_resources_or_start_ai(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input();
        $approval = $this->prepare($user, $input);
        $this->postJson('/api/catalog/100/apps/actions', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('approval.id', $approval->id);
        $this->postJson('/api/catalog/100/apps/actions', [...$input, 'name' => 'Outra análise'])->assertConflict();
        $this->assertSame([['resource' => 'table:500', 'name' => 'Vendas de teste']], $approval->payload['revision']['app_creation']['tables']);
        $this->assertSame('Conferir receitas por região.', Project::findOrFail($approval->project_id)->description);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertSame(0, $state->creations);
        Queue::assertNothingPushed();
    }

    public function test_creation_runs_once_after_authorization_and_binds_only_the_confirmed_private_app(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->creations);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->payload['execution']['result']['app_creation']['verified']);
        $this->assertSame('app:12', Project::findOrFail($approval->project_id)->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['user_id' => $user->id, 'project_id' => $approval->project_id, 'resource' => 'app:12', 'origin' => 'manual_app_creation', 'status' => 'draft']);
        $this->assertDatabaseMissing('managed_bi_resources', ['resource' => 'app:11']);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    #[DataProvider('changedAuthorization')]
    public function test_changed_access_or_context_prevents_creation(string $change): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        match ($change) {
            'session' => $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]),
            'role' => $user->forceFill(['role' => 'viewer'])->save(),
            'version' => $user->forceFill(['access_version' => 1])->save(),
            'archived' => Project::findOrFail($approval->project_id)->update(['status' => 'archived']),
            'bound' => Project::findOrFail($approval->project_id)->update(['source_ref' => 'app:11']),
            'tables' => $state->tables = [],
            'review' => $approval->update(['payload' => [...$approval->payload, 'action' => [...$approval->payload['action'], 'name' => 'Alterado após autorização']]]),
        };
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->creations);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public static function changedAuthorization(): array
    {
        return array_map(fn ($value) => [$value], ['session', 'role', 'version', 'archived', 'bound', 'tables', 'review']);
    }

    public function test_a_readback_failure_can_be_reconciled_without_creating_again(): void
    {
        Queue::fake();
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->readback_failed = true;
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame('app:12', $approval->fresh()->payload['app_creation_attempt']['resource']);
        $this->assertNull(Project::findOrFail($approval->project_id)->source_ref);
        $state->readback_failed = false;
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-creation-verification')->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.reconciliation.remote_read_only', true)->assertJsonPath('project.source_ref', 'app:12');
        $this->assertSame(1, $state->creations);
        $this->assertDatabaseCount('managed_bi_resources', 1);
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/bi-actions/'.$approval->id.'/app-creation-verification')->assertNotFound();
    }

    #[DataProvider('unconfirmedReceipt')]
    public function test_an_uncertain_creation_is_never_retried_or_adopted_by_name(array $response, bool $timeout): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $state->response = $response;
        $state->send_timeout = $timeout;
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-creation-verification')->assertConflict();
        $this->assertSame(1, $state->creations);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        $this->assertNull(Project::findOrFail($approval->project_id)->source_ref);
    }

    public static function unconfirmedReceipt(): array
    {
        return [
            'preexisting id' => [['id' => 11], false],
            'boolean id' => [['id' => true], false],
            'ambiguous ids' => [['id' => 12, 'payload' => ['id' => 13]], false],
            'missing receipt' => [[], false],
            'response timeout' => [[], true],
        ];
    }

    #[DataProvider('mismatchedReadback')]
    public function test_mismatched_readback_cannot_confirm_or_manage_an_application(array $changes): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        if (isset($changes['header'])) {
            $state->header = $changes['header'];
        }
        $state->body = [...$state->body, ...($changes['body'] ?? [])];
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-creation-verification')->assertUnprocessable();
        $this->assertSame(1, $state->creations);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public static function mismatchedReadback(): array
    {
        return [
            'foreign tenant' => [['header' => ['kind' => 'app', 'id' => 12, 'tenantId' => 200]]],
            'foreign id' => [['header' => ['kind' => 'app', 'id' => 13, 'tenantId' => 100]]],
            'published app' => [['body' => ['_state' => ['deskId' => 5, 'state' => 'published']]]],
            'wrong tables' => [['body' => ['tables' => [['tableId' => 501]]]]],
            'unexpected dashboards' => [['body' => ['dashboards' => [['id' => 1]]]]],
        ];
    }

    public function test_creation_without_selected_tables_does_not_require_dw_listing(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $state->body['tables'] = [];
        $state->expected_command = ['new', 'app', '--name', 'Minha análise'];
        $approval = $this->prepare($user, $this->input(['tables' => []]));
        $this->execute($user, $approval);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame(0, $approval->fresh()->payload['execution']['result']['app_creation']['table_count']);
        $this->assertSame([], array_values(array_filter($state->commands, fn ($command) => ($command[0] ?? null) === 'list' && ($command[1] ?? null) === 'table')));
    }

    public function test_a_read_only_tenant_cannot_prepare_the_creation(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        config(['horus.read_only_tenants' => ['100']]);
        $this->actingAs($user)->postJson('/api/catalog/100/apps/actions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('application');
        $this->assertSame(0, $state->creations);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_an_expired_authorization_cannot_execute_a_creation(): void
    {
        Queue::fake();
        $this->freezeTime();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        $this->travel(13)->minutes();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertSame(0, $state->creations);
    }

    public function test_chat_waits_for_the_creation_decision_and_confirmation(): void
    {
        Queue::fake();
        [$user, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $approval->project_id, 'title' => 'Nova conversa']);
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Monte os indicadores.'])->assertConflict();
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNothingPushed();
        $state->readback_failed = true;
        $this->execute($user, $approval);
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Crie outra aplicação.'])->assertConflict();
        $state->readback_failed = false;
        $this->postJson('/api/bi-actions/'.$approval->id.'/app-creation-verification')->assertOk();
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Monte os indicadores nesta aplicação.'])->assertAccepted();
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }
}
