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
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiDesksTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/desks-'.Str::uuid());
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
        $state = (object) ['bodies' => ['bi-desk:11' => ['nome' => 'Mesa existente', 'descricao' => 'Descrição atual', 'fechada' => true, 'type' => 'normal', 'version' => 3], 'dw-desk:12' => ['nome' => 'Dados existentes', 'descricao' => null, 'type' => 'normal']], 'commands' => [], 'new' => 0, 'push' => 0];

        return [$user, $connection, $state];
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['action' => 'create_desk', 'name' => 'Nova mesa de teste', 'description' => 'Organização por área', 'request_id' => (string) Str::uuid()], $overrides);
    }

    private function native(object $state): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->withArgs(fn ($connection, $context, bool $readOnly): bool => $readOnly)->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function ($connection, array $arguments, $context) use ($state): array {
            $state->commands[] = $arguments;
            $write = function (string $ref) use ($context, $state): string {
                [$kind, $id] = explode(':', $ref);
                $folder = app(WorkspaceFiles::class)->path($context).'/'.($kind === 'bi-desk' ? 'bi-desks' : 'dw-desks');
                File::ensureDirectoryExists($folder, 0700, true);
                $path = $folder.'/desk--'.$id.'.yaml';
                File::put($path, Yaml::dump(['id' => (int) $id, 'kind' => $kind, 'tenantId' => $state->wrong_tenant ?? (int) $context->tenant_id, 'lumo' => 'v2'])."---\n".Yaml::dump($state->bodies[$ref], 15));

                return $path;
            };
            if ($arguments[0] === 'list') {
                $rows = [];
                foreach ($state->bodies as $ref => $body) {
                    [$kind, $id] = explode(':', $ref);
                    if ($kind === $arguments[1]) {
                        $rows[] = ['id' => (int) $id, 'nome' => $body['nome']];
                    }
                }

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($arguments[0] === 'pull') {
                if (($state->fail_confirmation ?? false) && $state->push > 0) {
                    throw new RuntimeException('Synthetic readback failure');
                }
                $write($arguments[1]);

                return ['data' => []];
            }
            if ($arguments[0] === 'new') {
                $state->new++;
                $ref = $arguments[1].':20';
                $state->bodies[$ref] = ['nome' => $arguments[3], 'descricao' => null, 'type' => 'normal', 'fechada' => true];
                $write($ref);

                return ['data' => ['resource' => $ref]];
            }
            if ($arguments[0] === 'lint') {
                $this->assertCount(2, $arguments);
                $this->assertFileExists($arguments[1]);
                if ($state->downgrade ?? false) {
                    User::findOrFail($connection->user_id)->forceFill(['role' => 'viewer'])->save();
                }
                if ($state->fail_lint ?? false) {
                    throw new RuntimeException('Synthetic lint failure');
                }

                return ['data' => ['valid' => true]];
            }
            if ($arguments[0] === 'push') {
                $this->assertCount(2, $arguments);
                $state->push++;
                [$kind, $id] = explode(':', $arguments[1]);
                $path = app(WorkspaceFiles::class)->path($context).'/'.($kind === 'bi-desk' ? 'bi-desks' : 'dw-desks').'/desk--'.$id.'.yaml';
                $parts = preg_split('/^---\s*$/m', File::get($path), 2);
                $state->bodies[$arguments[1]] = Yaml::parse($parts[1]);

                return ['data' => []];
            }
            throw new RuntimeException('Unexpected native operation');
        });
    }

    private function execute(string $id): Approval
    {
        $approval = Approval::findOrFail($id);
        $this->postJson('/api/approvals/'.$id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($id))->handle(app(ActionBroker::class));

        return $approval->fresh();
    }

    public function test_guest_and_viewer_cannot_prepare_a_desk(): void
    {
        $this->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertUnauthorized();
        [$user] = $this->context('viewer');
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertForbidden();
    }

    public function test_a_user_cannot_use_another_accounts_connection_or_an_unavailable_tenant(): void
    {
        [$owner] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs(User::factory()->create())->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/catalog/200/desks/bi-desk/actions', $this->input())->assertForbidden();
    }

    public function test_preparation_is_read_only_and_the_same_request_reuses_its_review(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $input = $this->input();
        $first = $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $input)->assertOk()->assertJsonPath('approval.payload.revision.desk.label', 'Mesa de aplicações')->assertJsonPath('approval.project_id', null)->assertJsonPath('approval.status', 'pending');
        $this->postJson('/api/catalog/100/desks/bi-desk/actions', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('approval.id', $first->json('approval.id'));
        $this->postJson('/api/catalog/100/desks/bi-desk/actions', [...$input, 'name' => 'Outro nome'])->assertConflict();
        $this->assertSame(0, $state->new);
        $this->assertSame(0, $state->push);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('kinds')]
    public function test_creation_is_confirmed_once_after_approval_without_starting_ai(string $kind): void
    {
        Queue::fake([ExecuteApprovedAction::class, RunAgentTurn::class]);
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/'.$kind.'/actions', $this->input())->assertOk();
        $approval = $this->execute($response->json('approval.id'));

        $this->assertSame('completed', $approval->status);
        $this->assertSame($kind.':20', $approval->payload['execution']['result']['resource']);
        $this->assertTrue($approval->payload['execution']['result']['configuration_verified']);
        $this->assertSame('Organização por área', $state->bodies[$kind.':20']['descricao']);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->new);
        $this->assertSame(1, $state->push);
        $this->assertDatabaseHas('managed_bi_resources', ['user_id' => $user->id, 'resource' => $kind.':20', 'status' => 'configured']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
        Queue::assertNotPushed(RunAgentTurn::class);
        $this->assertDatabaseCount('projects', 0);
    }

    public static function kinds(): array
    {
        return [['bi-desk'], ['dw-desk']];
    }

    public function test_editing_name_and_description_preserves_access_and_other_native_fields(): void
    {
        Queue::fake([ExecuteApprovedAction::class]);
        [$user, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->getJson('/api/catalog/100/desks/bi-desk/11/configuration')->assertOk()->assertExactJson(['resource' => 'bi-desk:11', 'name' => 'Mesa existente', 'description' => 'Descrição atual']);
        $response = $this->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input(['action' => 'update_desk', 'resource' => 'bi-desk:11']))->assertOk()->assertJsonPath('approval.payload.revision.desk.previous.name', 'Mesa existente');

        $approval = $this->execute($response->json('approval.id'));

        $this->assertSame('completed', $approval->status);
        $this->assertSame(['nome' => 'Nova mesa de teste', 'descricao' => 'Organização por área', 'fechada' => true, 'type' => 'normal', 'version' => 3], $state->bodies['bi-desk:11']);
        $this->assertSame(0, $state->new);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_remote_changes_after_review_prevent_overwriting_a_desk(): void
    {
        Queue::fake([ExecuteApprovedAction::class]);
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input(['action' => 'update_desk', 'resource' => 'bi-desk:11']))->assertOk();
        $state->bodies['bi-desk:11']['descricao'] = 'Edição simultânea';

        $approval = $this->execute($response->json('approval.id'));

        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->push);
        $this->assertSame('Edição simultânea', $state->bodies['bi-desk:11']['descricao']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_a_session_change_or_role_downgrade_prevents_native_writes(): void
    {
        Queue::fake([ExecuteApprovedAction::class]);
        [$user, $connection, $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertOk();
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        $approval = $this->execute($response->json('approval.id'));
        $this->assertSame('failed', $approval->status);
        $this->assertSame(0, $state->new);
        $edit = $this->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input(['action' => 'update_desk', 'resource' => 'bi-desk:11']))->assertOk();
        $state->downgrade = true;
        $edited = $this->execute($edit->json('approval.id'));
        $this->assertSame('failed', $edited->status);
        $this->assertSame(0, $state->push);
        Queue::assertPushed(ExecuteApprovedAction::class, 2);
    }

    public function test_a_partially_created_desk_is_recorded_and_not_automatically_created_again(): void
    {
        Queue::fake([ExecuteApprovedAction::class]);
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/dw-desk/actions', $this->input())->assertOk();
        $state->fail_lint = true;
        $approval = $this->execute($response->json('approval.id'));

        $this->assertSame('failed', $approval->status);
        $this->assertSame('dw-desk:20', $approval->payload['desk_attempt']['created_resource']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'dw-desk:20', 'status' => 'config_pending']);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->new);
        $this->assertSame(0, $state->push);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_accepted_push_without_readback_confirmation_is_not_reported_as_completed(): void
    {
        Queue::fake([ExecuteApprovedAction::class]);
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertOk();
        $state->fail_confirmation = true;
        $approval = $this->execute($response->json('approval.id'));

        $this->assertSame('failed', $approval->status);
        $this->assertSame(1, $state->new);
        $this->assertSame(1, $state->push);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'bi-desk:20', 'status' => 'config_pending']);
        Queue::assertPushed(ExecuteApprovedAction::class, 1);
    }

    public function test_duplicate_name_wrong_tenant_document_and_unlisted_reference_are_refused(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input(['name' => 'mesa existente']))->assertUnprocessable();
        $this->getJson('/api/catalog/100/desks/bi-desk/999/configuration')->assertNotFound();
        $state->wrong_tenant = 200;
        $this->getJson('/api/catalog/100/desks/bi-desk/11/configuration')->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->new);
    }

    public function test_an_interrupted_worker_marks_the_review_failed_and_removes_its_temporary_workspace(): void
    {
        [$user, , $state] = $this->context();
        $this->native($state);
        $response = $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertOk();
        $approval = Approval::findOrFail($response->json('approval.id'));
        $approval->update(['status' => 'executing', 'decided_at' => now()]);
        $context = new Project(['user_id' => $user->id, 'tenant_id' => '100']);
        $context->id = 'desk-operation-'.$approval->id;
        $path = app(WorkspaceFiles::class)->path($context);
        File::ensureDirectoryExists($path);
        File::put($path.'/synthetic-private-metadata', 'discard after interruption');

        (new ExecuteApprovedAction($approval->id))->failed(new RuntimeException('Synthetic worker interruption'));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertSame(0, $state->new);
    }

    public function test_a_configured_read_only_tenant_cannot_prepare_writes(): void
    {
        [$user] = $this->context();
        config(['horus.read_only_tenants' => ['100']]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_privileged_fields_return_422_before_any_native_call(array $input): void
    {
        [$user] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($user)->postJson('/api/catalog/100/desks/bi-desk/actions', $this->input($input))->assertUnprocessable();
        $this->assertDatabaseCount('approvals', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'access flag' => [['fechada' => false]],
            'members' => [['members' => [1]]],
            'other desk kind' => [['action' => 'update_desk', 'resource' => 'dw-desk:11']],
            'create with existing ref' => [['resource' => 'bi-desk:11']],
            'empty name' => [['name' => '   ']],
            'control in name' => [['name' => "Mesa\nextra"]],
            'description limit' => [['description' => str_repeat('a', 2001)]],
        ];
    }
}
