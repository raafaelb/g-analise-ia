<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Services\LumoClient;
use App\Services\TenantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenantPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_tenant_rejects_every_remote_mutation(): void
    {
        config(['horus.read_only_tenants' => ['1234']]);
        $policy = app(TenantPolicy::class);
        foreach ([['new', 'app'], ['push', 'app:1'], ['app', 'publish', 'app:1'], ['flow', 'run', 'flow:1'], ['schedule', 'resume', 'schedule:1'], ['schedule', 'run', 'schedule:1'], ['table', 'truncate', 'table:1'], ['rm', 'app:1'], ['query', 'DROP TABLE example'], ['writeback', 'import', 'writeback:1', 'input.xlsx'], ['writeback', 'rollback-import', 'writeback:1', 'batch'], ['writeback', 'publish', 'writeback:1', '--desk', '2']] as $command) {
            try {
                $policy->assertCommandAllowed('1234', $command);
                $this->fail('Mutação no tenant protegido foi aceita.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('somente leitura', $e->getMessage());
            }
        }
    }

    public function test_protected_tenant_can_be_inspected(): void
    {
        config(['horus.read_only_tenants' => ['1234']]);
        $policy = app(TenantPolicy::class);
        foreach ([['list', 'app', '--remote'], ['info', 'app:1', '--full'], ['app', 'dashboard-preview', 'app:1'], ['schedule', 'logs', 'schedule:1'], ['table', 'preview', 'table:1'], ['writeback', 'preview', 'writeback:1'], ['writeback', 'imports', 'writeback:1'], ['writeback', 'export', 'writeback:1', '--output', '/tmp/test.xlsx']] as $command) {
            $policy->assertCommandAllowed('1234', $command);
            $this->addToAssertionCount(1);
        }
    }

    public function test_tenant_names_never_impose_a_product_restriction(): void
    {
        config(['horus.read_only_tenants' => []]);
        $connection = new Connection(['metadata' => ['tenants' => [['id' => '987', 'name' => 'Cliente Exemplo']]]]);
        $this->assertFalse(app(TenantPolicy::class)->isReadOnly('987', $connection));
    }

    public function test_cli_never_starts_mutation_for_protected_tenant(): void
    {
        config(['horus.read_only_tenants' => ['1234'], 'horus.lumo.binary' => '/does-not-exist']);
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']]]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Somente leitura', 'tenant_id' => '1234']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('somente leitura');
        app(LumoClient::class)->run($connection, ['push', 'app:1'], $project);
    }
}
