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
use App\Services\BiPublications;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BiPublicationsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:2', 'name' => 'Versão em revisão']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a', 'tenants' => [['id' => '100', 'name' => 'Teste autorizado']]]]);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2', 'origin' => 'clone_app', 'source_ref' => 'app:1', 'receipt' => []]);
        $original = ['nome' => 'Aplicação compartilhada', '_state' => ['deskId' => 10], 'dashboards' => [['id' => 11]], 'descricao' => 'Versão existente'];
        $draft = ['nome' => 'Nova visão', '_state' => ['deskId' => null], 'cloned_from' => 1, 'dashboards' => [['id' => 22]], 'private_reference' => 'synthetic-private-source'];
        $state = (object) ['bodies' => [1 => $original, 2 => $draft], 'desks' => [['id' => 10, 'nome' => 'Comercial']], 'tenant_id' => 100, 'clean' => true, 'refresh_verified' => true, 'refresh_calls' => 0, 'publishes' => 0, 'commands' => [], 'apply' => true];

        return [$user, $project, $connection, $state];
    }

    private function native(object $state): void
    {
        $this->mock(RefreshCoverage::class)->shouldReceive('inspect')->andReturnUsing(function () use ($state): array {
            $state->refresh_calls++;

            return ['verified' => $state->refresh_verified];
        });
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args) use ($state): array {
            $state->commands[] = $args;
            if ($args[0] === 'list') {
                $rows = $args[1] === 'app' ? array_map(fn (int $id, array $body): array => ['id' => $id, 'nome' => $body['nome']], array_keys($state->bodies), array_values($state->bodies)) : $state->desks;

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'fetch') {
                return ['data' => []];
            }
            if ($args[0] === 'info') {
                $id = (int) substr($args[1], 4);

                return ['data' => ['payload' => ['header' => ['id' => $id, 'tenantId' => $state->tenant_id], 'body' => $state->bodies[$id]]]];
            }
            if ($args[0] === 'diff') {
                return ['data' => ['workingVsServer' => ['equal' => $state->clean]]];
            }
            if ($args[0] === 'lint') {
                return ['data' => ['valid' => true]];
            }
            if (array_slice($args, 0, 2) === ['app', 'publish']) {
                $state->publishes++;
                $this->assertGreaterThanOrEqual(2, $state->refresh_calls, 'A cobertura deve ser conferida antes da revisão e novamente antes da publicação.');
                if ($state->apply) {
                    $target = in_array('--new', $args, true) ? 2 : 1;
                    $state->bodies[$target] = [...$state->bodies[2], '_state' => ['deskId' => 10]];
                    if ($target === 1) {
                        unset($state->bodies[2]);
                    }
                }

                return ['exit_code' => 0, 'data' => ['accepted' => true]];
            }
            throw new RuntimeException('Comando inesperado no teste de publicação: '.json_encode($args));
        });
    }

    private function prepare(User $user, string $mode = 'new'): Approval
    {
        $response = $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'publish_app', 'desk' => 10, 'publish_mode' => $mode, 'request_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('approval.status', 'pending');

        return Approval::findOrFail($response->json('approval.id'));
    }

    private function execute(User $user, Approval $approval): void
    {
        $this->actingAs($user)->postJson('/api/approvals/'.$approval->id.'/decide', ['decision' => 'approved', 'fingerprint' => $approval->fingerprint])->assertOk();
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
    }

    public function test_new_publication_has_a_named_destination_and_preserves_the_existing_original(): void
    {
        Queue::fake();
        [$user, $project, , $state] = $this->context();
        $before = $state->bodies[1];
        $this->native($state);
        $approval = $this->prepare($user);
        $this->assertSame('new', $approval->payload['revision']['publication']['mode']);
        $this->assertSame('Comercial', $approval->payload['revision']['publication']['destination']['name']);
        $this->assertSame('app:2', $approval->payload['revision']['publication']['target']['resource']);
        $this->assertSame(0, $state->publishes);
        $this->assertStringNotContainsString('synthetic-private-source', json_encode($approval->payload['revision']['publication']));
        $this->execute($user, $approval);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->payload['execution']['result']['publication']['verified']);
        $this->assertSame($before, $state->bodies[1]);
        $this->assertSame('app:2', $project->fresh()->source_ref);
        $this->assertContains(['app', 'publish', 'app:2', '--desk', '10', '--new'], $state->commands);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'published']);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_explicit_replacement_identifies_the_original_and_updates_the_project_to_the_surviving_resource(): void
    {
        Queue::fake();
        [$user, $project, , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, 'replace');
        $this->assertSame('Aplicação compartilhada', $approval->payload['revision']['publication']['target']['name']);
        $this->assertSame('app:1', $approval->payload['revision']['publication']['target']['resource']);
        $this->assertSame(0, $state->publishes);
        $this->execute($user, $approval);
        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertSame('app:1', $approval->fresh()->payload['execution']['result']['published_resource']);
        $this->assertSame('app:1', $project->fresh()->source_ref);
        $this->assertArrayNotHasKey(2, $state->bodies);
        $this->assertContains(['app', 'publish', 'app:2', '--desk', '10', '--force'], $state->commands);
        $this->assertSame('app:1', ManagedBiResource::first()->receipt['publication']['resource']);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_a_change_to_the_replacement_target_invalidates_the_review_before_any_publish(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user, 'replace');
        $state->bodies[1]['descricao'] = 'Alteração de outro usuário';
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('mudou após a revisão', $approval->fresh()->payload['execution']['message']);
        $this->assertSame(0, $state->publishes);
    }

    public function test_a_successful_cli_exit_without_confirmed_publication_is_not_retried_or_marked_published(): void
    {
        Queue::fake();
        [$user, $project, , $state] = $this->context();
        $state->apply = false;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));
        $this->assertSame(1, $state->publishes);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('confirmação final ficou pendente', $approval->fresh()->payload['execution']['message']);
        $this->assertSame('app:2', $project->fresh()->source_ref);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_dirty_local_authorship_requires_synchronization_before_a_publication_review(): void
    {
        [$user, , , $state] = $this->context();
        $state->clean = false;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'publish_app', 'desk' => 10, 'publish_mode' => 'new', 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('errors.operation.0', 'Sincronize e confira as alterações do rascunho antes de preparar a publicação.');
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_unverified_daily_refresh_blocks_preparation_without_creating_an_approval(): void
    {
        [$user, , , $state] = $this->context();
        $state->refresh_verified = false;
        $this->native($state);
        $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'publish_app', 'desk' => 10, 'publish_mode' => 'new', 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertSame(0, $state->publishes);
        $this->assertDatabaseCount('approvals', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidPlans(): array
    {
        return [
            'foreign destination' => ['foreign_desk', 'mesa de aplicações não está disponível'],
            'already published' => ['published', 'já foi publicada'],
            'unknown state' => ['unknown_state', 'estado atual'],
            'foreign payload' => ['foreign_payload', 'fora do contexto'],
            'unavailable source' => ['foreign_source', 'não está disponível neste tenant'],
            'original on another desk' => ['wrong_original_desk', 'mesma mesa'],
            'unknown replacement origin' => ['unknown_origin', 'aplicação original autorizada'],
        ];
    }

    #[DataProvider('invalidPlans')]
    public function test_unproven_sources_destinations_and_replacement_targets_are_rejected(string $case, string $message): void
    {
        [, $project, $connection, $state] = $this->context();
        $mode = 'new';
        match ($case) {
            'foreign_desk' => $state->desks = [],
            'published' => $state->bodies[2]['_state']['deskId'] = 10,
            'unknown_state' => $state->bodies[2]['_state'] = [],
            'foreign_payload' => $state->tenant_id = 999,
            'foreign_source' => $state->bodies = [1 => $state->bodies[1]],
            'wrong_original_desk' => $state->bodies[1]['_state']['deskId'] = 11,
            'unknown_origin' => $state->bodies[2]['cloned_from'] = 999,
        };
        if (in_array($case, ['wrong_original_desk', 'unknown_origin'], true)) {
            $mode = 'replace';
        }
        $this->native($state);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        try {
            app(BiPublications::class)->plan($connection, $project, ['resource' => 'app:2', 'desk' => 10, 'publish_mode' => $mode]);
        } finally {
            $this->assertSame(0, $state->publishes);
        }
    }

    public function test_a_viewer_is_rejected_before_native_reads(): void
    {
        [$user, $project, $connection] = $this->context();
        $user->forceFill(['role' => 'viewer'])->save();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permissão ou a sessão');
        app(BiPublications::class)->plan($connection, $project, ['resource' => 'app:2', 'desk' => 10, 'publish_mode' => 'new']);
    }

    public function test_a_connection_cannot_publish_a_project_owned_by_another_user(): void
    {
        [, $project, $connection] = $this->context();
        $other = User::factory()->create();
        $project->update(['user_id' => $other->id]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permissão ou a sessão');
        app(BiPublications::class)->plan($connection, $project, ['resource' => 'app:2', 'desk' => 10, 'publish_mode' => 'new']);
    }
}
