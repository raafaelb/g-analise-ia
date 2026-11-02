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
use App\Services\BiResourceValidation;
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
        $this->mock(BiResourceValidation::class)->shouldReceive('inspect')->andReturn(['passed' => true]);
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args) use ($state): array {
            $state->commands[] = $args;
            if ($args[0] === 'list') {
                $rows = $args[1] === 'app' ? array_map(fn (int $id, array $body): array => ['id' => $id, 'nome' => $body['nome']], array_keys($state->bodies), array_values($state->bodies)) : $state->desks;

                return ['data' => ['results' => $rows, 'matched' => count($rows)]];
            }
            if ($args[0] === 'fetch') {
                if ($state->switch_session_during_read ?? false) {
                    $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
                    $state->switch_session_during_read = false;
                }
                if ($state->publishes > 0 && ($state->readback_unavailable ?? false)) {
                    throw new RuntimeException('Consulta temporariamente indisponível.');
                }

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
                    if (isset($state->after_publish)) {
                        $state->bodies[$target] = ($state->after_publish)($state->bodies[$target]);
                    }
                    if ($target === 1) {
                        unset($state->bodies[2]);
                    }
                }

                if ($state->throw_after_publish ?? false) {
                    throw new RuntimeException('A resposta nativa foi interrompida.');
                }

                return ['exit_code' => 0, 'data' => ['accepted' => true, 'detail' => str_repeat('x', $state->large_result ?? 0)]];
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
        $this->assertSame($approval->id, $project->fresh()->preview_revision);
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

    /** @return array<string, array{string}> */
    public static function publishedContentChanges(): array
    {
        return [
            'dashboard content changed' => ['dashboards.0.nome'],
            'widget content changed' => ['dashboards.0.widgets.0.config.title'],
            'table changed' => ['tables.0.tableId'],
            'expression changed' => ['expressions.0.formula'],
            'filter changed' => ['filtros.0.value'],
            'future content field changed' => ['private_reference'],
        ];
    }

    #[DataProvider('publishedContentChanges')]
    public function test_published_content_different_from_the_review_is_not_confirmed_or_sent_again(string $path): void
    {
        Queue::fake();
        [$user, $project, , $state] = $this->context();
        $state->bodies[2] = [...$state->bodies[2],
            'dashboards' => [['id' => 22, 'nome' => 'Resumo', 'widgets' => [['id' => 33, 'config' => ['title' => 'Receita']]]]],
            'tables' => [['tableId' => 7, 'tipo' => 'dimension']],
            'expressions' => [['id' => 80, 'formula' => 'SUM(VALOR)']],
            'filtros' => [['column' => ['column_id' => 9, 'expression' => 'AGP'], 'operator' => '=', 'value' => 'Sul']],
        ];
        $state->after_publish = function (array $body) use ($path): array {
            data_set($body, $path, $path === 'tables.0.tableId' ? 8 : 'Conteúdo diferente');

            return $body;
        };
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);
        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertStringContainsString('confirmação final ficou pendente', $approval->fresh()->payload['execution']['message']);
        $this->assertNull($project->fresh()->preview_revision);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_read_only_reconciliation_rejects_different_content_until_the_reviewed_content_is_returned(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->throw_after_publish = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $reviewed = $state->bodies[2];
        $state->bodies[2]['private_reference'] = 'Outra configuração';
        $endpoint = '/api/approvals/'.$approval->id.'/publication/verify';

        $this->postJson($endpoint)->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'draft']);
        $state->bodies[2] = $reviewed;
        $this->postJson($endpoint)->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.publication.content_verified', true);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'published']);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_publication_audit_metadata_can_change_without_invalidating_reviewed_content(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->after_publish = fn (array $body): array => [...$body, 'deskId' => 10, 'version' => 2, 'criado_em' => '2026-11-01T10:00:00-03:00', 'criado_por' => 5, 'publicado_em' => '2026-11-02T03:00:00-03:00', 'publicado_por' => 6];
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->payload['execution']['result']['publication']['content_verified']);
        $this->assertStringNotContainsString('synthetic-private-source', json_encode($approval->payload['revision']['publication']));
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_legacy_publication_receipts_do_not_claim_content_verification(): void
    {
        [, $project, $connection, $state] = $this->context();
        $this->native($state);
        $publications = app(BiPublications::class);
        $plan = $publications->plan($connection, $project, ['resource' => 'app:2', 'desk' => 10]);
        unset($plan['source']['content_sha256']);
        $state->bodies[2]['_state']['deskId'] = 10;

        $result = $publications->verify($connection, $project, $plan);

        $this->assertTrue($result['verified']);
        $this->assertFalse($result['content_verified']);
        $this->assertSame(0, $state->publishes);
    }

    private function wrappedDashboard(): array
    {
        return ['header' => ['id' => 22, 'kind' => 'app-dashboard', 'lumo' => 'v2', 'tenantId' => 100, 'appId' => 2], 'body' => ['nome' => 'Resumo', 'ordem' => 0, 'version' => 1, 'widgets' => [['id' => 33, 'type' => 'Kpi', 'x' => 0, 'y' => 0, 'chart' => ['version' => 1, 'options' => ['title' => 'Receita']]]]]];
    }

    /** @return array<string, array{string, int}> */
    public static function wrappedPublicationModes(): array
    {
        return ['new application' => ['new', 2], 'replace original' => ['replace', 1]];
    }

    #[DataProvider('wrappedPublicationModes')]
    public function test_native_dashboard_envelopes_confirm_current_application_binding_and_allow_audit_changes(string $mode, int $appId): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->bodies[2]['dashboards'] = [$this->wrappedDashboard()];
        $state->after_publish = function (array $body) use ($appId): array {
            $body['dashboards'][0]['header']['appId'] = $appId;
            $body['dashboards'][0]['body']['version'] = 2;
            $body['dashboards'][0]['body']['publicado_em'] = '2026-11-02T03:00:00-03:00';
            $body['dashboards'][0]['body']['publicado_por'] = 6;

            return $body;
        };
        $this->native($state);
        $approval = $this->prepare($user, $mode);

        $this->execute($user, $approval);

        $this->assertSame('completed', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->payload['execution']['result']['publication']['content_verified']);
        $this->assertSame('app:'.$appId, $approval->fresh()->payload['execution']['result']['published_resource']);
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidWrappedDashboardChanges(): array
    {
        return [
            'another application' => ['header.appId', 999],
            'another tenant' => ['header.tenantId', 999],
            'another resource kind' => ['header.kind', 'app'],
            'unknown document version' => ['header.lumo', 'v3'],
            'missing body' => ['body', null],
            'changed chart version is content' => ['body.widgets.0.chart.version', 2],
        ];
    }

    #[DataProvider('invalidWrappedDashboardChanges')]
    public function test_foreign_or_changed_native_dashboard_content_is_not_confirmed(string $path, mixed $value): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->bodies[2]['dashboards'] = [$this->wrappedDashboard()];
        $state->after_publish = function (array $body) use ($path, $value): array {
            data_set($body['dashboards'][0], $path, $value);

            return $body;
        };
        $this->native($state);
        $approval = $this->prepare($user);

        $this->execute($user, $approval);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_returns_422_before_a_review_for_a_dashboard_bound_to_another_application(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->bodies[2]['dashboards'] = [$this->wrappedDashboard()];
        $state->bodies[2]['dashboards'][0]['header']['appId'] = 999;
        $this->native($state);

        $this->actingAs($user)->postJson('/api/catalog/100/apps/2/actions', ['action' => 'publish_app', 'desk' => 10, 'publish_mode' => 'new', 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('errors.operation.0', 'O dashboard retornou fora do contexto da aplicação revisada.');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(0, $state->publishes);
        Queue::assertNothingPushed();
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

    public function test_read_only_reconciliation_confirms_a_delayed_publication_without_sending_it_again(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->apply = false;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $endpoint = '/api/approvals/'.$approval->id.'/publication/verify';
        $this->postJson($endpoint)->assertUnprocessable()->assertJsonPath('errors.publication.0', 'O destino ou o conteúdo resumido retornado pelo BI não corresponde à revisão autorizada.');
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertNull(Project::findOrFail($approval->project_id)->preview_revision);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
        $state->bodies[2]['_state']['deskId'] = 10;
        $this->postJson($endpoint)->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.publication.verified', true)->assertJsonPath('approval.payload.reconciliation.remote_read_only', true);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'published']);
        $this->assertDatabaseHas('audit_events', ['action' => 'bi.publication.reconciled', 'resource_id' => $approval->project_id]);
        $this->assertSame($approval->id, Project::findOrFail($approval->project_id)->preview_revision);
        $commands = count($state->commands);
        $this->postJson($endpoint)->assertOk()->assertJsonPath('approval.status', 'completed');
        $this->assertSame($commands, count($state->commands), 'Conferir uma operação já confirmada é idempotente.');
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_reconciliation_after_reconnecting_uses_current_access_and_updates_the_surviving_replacement(): void
    {
        Queue::fake();
        [$user, $project, $connection, $state] = $this->context();
        $state->readback_unavailable = true;
        $this->native($state);
        $approval = $this->prepare($user, 'replace');
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertArrayNotHasKey(2, $state->bodies);
        $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => 'session-b']]);
        $state->readback_unavailable = false;
        $this->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertOk()->assertJsonPath('project.source_ref', 'app:1')->assertJsonPath('approval.payload.execution.result.published_resource', 'app:1');
        $this->assertSame('app:1', $project->fresh()->source_ref);
        $this->assertSame('app:1', ManagedBiResource::first()->receipt['publication']['resource']);
        $this->assertSame(1, $state->publishes);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_reconciliation_can_confirm_a_write_whose_native_response_was_lost(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->throw_after_publish = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertArrayNotHasKey('native_accepted_at', $approval->fresh()->payload['publication_attempt']);
        $this->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertOk()->assertJsonPath('approval.payload.execution.result.publication.verified', true);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'published']);
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_reconciliation_requires_authentication(): void
    {
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->postJson('/api/approvals/'.Str::uuid().'/publication/verify')->assertUnauthorized();
    }

    public function test_another_user_cannot_learn_or_reconcile_a_publication(): void
    {
        [$owner, , , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($owner);
        $this->actingAs(User::factory()->create())->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertNotFound();
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertSame(0, $state->publishes);
    }

    public function test_a_viewer_cannot_reconcile_a_publication(): void
    {
        [$user, , , $state] = $this->context();
        $this->native($state);
        $approval = $this->prepare($user);
        $user->forceFill(['role' => 'viewer'])->save();
        $this->actingAs($user->fresh())->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertForbidden()->assertJsonPath('message', 'Seu perfil permite somente leitura.');
        $this->assertSame(0, $state->publishes);
    }

    /** @return array<string, array{string, int}> */
    public static function invalidReconciliations(): array
    {
        return [
            'changed signed plan' => ['plan', 409],
            'changed project tenant' => ['tenant', 409],
            'changed attempt receipt' => ['receipt', 422],
            'no native attempt' => ['no_attempt', 422],
            'still executing' => ['executing', 409],
            'tenant access revoked' => ['access', 403],
        ];
    }

    #[DataProvider('invalidReconciliations')]
    public function test_reconciliation_refuses_invalid_context_without_native_calls(string $case, int $status): void
    {
        Queue::fake();
        [$user, $project, $connection, $state] = $this->context();
        $state->apply = false;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $payload = $approval->fresh()->payload;
        if ($case === 'plan') {
            $payload['revision']['publication']['destination']['id'] = 999;
        } elseif ($case === 'tenant') {
            $project->update(['tenant_id' => '999']);
        } elseif ($case === 'receipt') {
            $payload['publication_attempt']['plan_sha256'] = str_repeat('0', 64);
        } elseif ($case === 'no_attempt') {
            unset($payload['publication_attempt']);
        } elseif ($case === 'executing') {
            $approval->update(['status' => 'executing']);
        } elseif ($case === 'access') {
            $connection->update(['metadata' => [...$connection->metadata, 'tenants' => []]]);
        }
        $approval->update(['payload' => $payload]);
        $commands = count($state->commands);
        $this->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertStatus($status);
        $this->assertSame($commands, count($state->commands));
        $this->assertSame(1, $state->publishes);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
    }

    public function test_a_large_native_result_preserves_the_publication_confirmation_in_the_interface(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->large_result = 65000;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $this->getJson('/api/bi-actions/'.$approval->id)->assertOk()->assertJsonPath('approval.status', 'completed')->assertJsonPath('approval.payload.execution.result.publication.verified', true)->assertJsonPath('approval.payload.execution.result.published_resource', 'app:2');
        $this->assertSame(1, $state->publishes);
    }

    public function test_a_session_change_during_reconciliation_cannot_confirm_an_earlier_read(): void
    {
        Queue::fake();
        [$user, , , $state] = $this->context();
        $state->readback_unavailable = true;
        $this->native($state);
        $approval = $this->prepare($user);
        $this->execute($user, $approval);
        $state->readback_unavailable = false;
        $state->switch_session_during_read = true;
        $this->postJson('/api/approvals/'.$approval->id.'/publication/verify')->assertUnprocessable()->assertJsonPath('errors.publication.0', 'A sessão mudou durante a consulta de publicação. Confira seus acessos e tente a conferência novamente.');
        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2', 'status' => 'publication_pending']);
        $this->assertSame(1, $state->publishes);
    }
}
