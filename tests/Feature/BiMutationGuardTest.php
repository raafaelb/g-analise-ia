<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\BiMutationGuard;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BiMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Teste', 'source_ref' => 'flow:2']);
        $plan = ['kind' => 'flow', 'resource' => 'flow:2', 'tables' => [['resource' => 'table:600']]];
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'type' => 'manual', 'title' => 'Excluir teste', 'description' => 'Teste sintético', 'status' => 'failed', 'payload' => ['action' => ['action' => 'delete_draft', 'resource' => 'flow:2'], 'revision' => ['flow_deletion' => $plan]], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(12)]);
        $managed = ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'flow:2', 'origin' => 'new', 'status' => 'deletion_pending', 'receipt' => ['deletion_attempt' => ['approval_id' => $approval->id, 'plan_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))]]]);

        return [$user, $project, $managed, $approval];
    }

    #[DataProvider('conflictingCommands')]
    public function test_pending_deletion_blocks_conflicting_native_commands(array $command): void
    {
        [, $project] = $this->pending();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exclusão em conferência');

        app(BiMutationGuard::class)->command($project, $command);
    }

    public static function conflictingCommands(): array
    {
        return [
            'flow push' => [['push', 'flow:2']],
            'table push' => [['push', 'table:600']],
            'flow clone' => [['flow', 'clone', 'flow:2']],
            'flow publication' => [['flow', 'publish', 'flow:2', '--desk', '3', '--new']],
            'node execution' => [['flow', 'run-node', 'flow:2', '--node', 'load']],
            'flow execution' => [['flow', 'run', 'flow:2']],
            'table sync' => [['flow', 'sync-table', 'flow:2', '--node', 'load']],
            'table creation' => [['new', 'table', '--from-flow', 'flow:2', '--node', 'load']],
            'table truncation' => [['table', 'truncate', 'table:600']],
            'table substitution from pending' => [['app', 'change-table', 'app:9', '--from-table', '600', '--to-table', '500']],
            'table substitution' => [['app', 'change-table', 'app:9', '--from-table', '500', '--to-table', '600']],
        ];
    }

    #[DataProvider('availableCommands')]
    public function test_reading_and_the_recorded_removal_are_not_blocked(array $command): void
    {
        [$user, $project, $managed] = $this->pending();
        [$connection, $marker] = $this->nativeFixture($user);

        $result = app(LumoClient::class)->run($connection, $command, $project);

        $this->assertFileExists($marker);
        $this->assertTrue($result['data']['verified']);
        $this->assertSame('deletion_pending', $managed->fresh()->status);
    }

    public static function availableCommands(): array
    {
        return [
            'resource info' => [['info', 'flow:2']],
            'table info' => [['info', 'table:600']],
            'flow history' => [['flow', 'logs', 'flow:2']],
            'normal pull' => [['pull', 'flow:2']],
            'comparison' => [['diff', 'flow:2', '--include-remote']],
            'recorded delete' => [['rm', 'flow:2', '--force']],
            'unrelated table' => [['push', 'table:500']],
        ];
    }

    public function test_a_write_without_an_account_context_is_refused_with_a_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('projeto vinculado a um usuário e tenant válidos');

        app(BiMutationGuard::class)->command(new Project(['tenant_id' => '100']), ['push', 'flow:2']);
    }

    public function test_another_user_or_tenant_does_not_inherit_the_first_accounts_pending_state(): void
    {
        [$user, $project] = $this->pending();
        $other = User::factory()->create();
        $guard = app(BiMutationGuard::class);

        $this->assertNull($guard->reason($other->id, '100', 'table:600'));
        $this->assertNull($guard->reason($user->id, '200', 'flow:2'));
        $this->assertNull($guard->reason($user->id, '200', 'table:600'));
        $this->assertNotNull($guard->reason($project->user_id, '100', 'table:600'));
    }

    public function test_a_changed_pending_plan_cannot_hide_one_of_its_targets(): void
    {
        [$user,,, $approval] = $this->pending();
        $payload = $approval->payload;
        $payload['revision']['flow_deletion']['tables'] = [];
        $approval->update(['payload' => $payload]);

        $reason = app(BiMutationGuard::class)->reason($user->id, '100', 'table:600');

        $this->assertStringContainsString('lista de tabelas não pôde ser conferida', $reason);
    }

    public function test_a_known_refusal_releases_the_flow_and_its_targets_for_new_reviews(): void
    {
        [$user,, $managed] = $this->pending();
        $managed->update(['status' => 'draft']);
        $guard = app(BiMutationGuard::class);

        $this->assertNull($guard->reason($user->id, '100', 'flow:2'));
        $this->assertNull($guard->reason($user->id, '100', 'table:600'));
    }

    private function nativeFixture(User $user): array
    {
        $root = storage_path('framework/testing/mutation-guard-'.Str::uuid());
        File::ensureDirectoryExists($root, 0700, true);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $marker = $root.'/called';
        $binary = $root.'/lumo';
        File::put($binary, '#!'.PHP_BINARY."\n<?php\n".'file_put_contents('.var_export($marker, true).', "called"); echo '.var_export('{"verified":true}', true).';');
        chmod($binary, 0700);
        config(['horus.lumo.binary' => $binary, 'horus.workspace_root' => $root.'/workspaces']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'a']]);

        return [$connection, $marker];
    }

    public function test_native_adapter_blocks_a_pending_table_before_starting_the_cli(): void
    {
        [$user, $project] = $this->pending();
        [$connection, $marker] = $this->nativeFixture($user);
        try {
            app(LumoClient::class)->run($connection, ['push', 'table:600'], $project);
            $this->fail('A tabela pendente foi enviada ao CLI.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('exclusão em conferência', $error->getMessage());
        }
        $this->assertFileDoesNotExist($marker);
    }

    public function test_native_adapter_refuses_a_write_without_a_tenant_before_starting_the_cli(): void
    {
        [$user] = $this->pending();
        [$connection, $marker] = $this->nativeFixture($user);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Sem ambiente selecionado']);

        try {
            app(LumoClient::class)->run($connection, ['push', 'flow:2'], $project);
            $this->fail('Uma escrita sem tenant foi enviada ao CLI.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('projeto vinculado a um usuário e tenant válidos', $error->getMessage());
        }

        $this->assertFileDoesNotExist($marker);
    }

    public function test_native_adapter_can_still_read_a_target_to_verify_the_result(): void
    {
        [$user, $project] = $this->pending();
        [$connection, $marker] = $this->nativeFixture($user);

        $result = app(LumoClient::class)->run($connection, ['info', 'table:600'], $project);

        $this->assertFileExists($marker);
        $this->assertTrue($result['data']['verified']);
    }
}
