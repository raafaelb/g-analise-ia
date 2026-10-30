<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantVariablesTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $u = User::factory()->create();
        $p = Project::create(['user_id' => $u->id, 'tenant_id' => '321', 'name' => 'Variáveis']);
        $c = Connection::create(['user_id' => $u->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'test']], 'metadata' => ['tenants' => [['id' => '321', 'name' => 'BI']]]]);

        return [$u, $p, $c];
    }

    private function native(array $data): void
    {
        $mock = $this->mock(LumoClient::class);
        $mock->shouldReceive('initialize')->andReturnNull();
        $mock->shouldReceive('run')->withArgs(fn ($c, $args) => $args === ['list', 'variables', '--remote'])->andReturn(['data' => $data]);
    }

    public function test_keys_and_references_are_available_without_any_variable_values(): void
    {
        [$u,$p,$c] = $this->context();
        $this->native(['count' => 2, 'variables' => ['SERVICE_PASS' => 'private-password-value', 'BASE_URL' => 'https://secret.example.invalid']]);
        $rows = app(BiCatalog::class)->rows($c, $p, 'variables');
        $encoded = json_encode($rows);
        $this->assertCount(2, $rows);
        $this->assertSame('{{SERVICE_PASS}}', $rows[0]['reference']);
        $this->assertStringNotContainsString('private-password-value', $encoded);
        $this->assertStringNotContainsString('secret.example.invalid', $encoded);
        $response = $this->actingAs($u)->getJson('/api/catalog/321/variables/'.bin2hex('SERVICE_PASS'))->assertOk()->assertJsonPath('details.value', '[protegido]');
        $this->assertStringNotContainsString('private-password-value', $response->getContent());
    }

    public function test_user_cannot_read_variable_metadata_from_another_tenant(): void
    {
        [$u] = $this->context();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->actingAs($u)->getJson('/api/catalog/999/variables/'.bin2hex('PASS'))->assertForbidden();
    }

    public function test_truncated_or_unrecognized_native_variable_response_is_not_empty_success(): void
    {
        [$u,$p,$c] = $this->context();
        $this->native(['count' => 2, 'variables' => ['PASS' => 'hidden']]);
        $this->expectException(\RuntimeException::class);
        app(BiCatalog::class)->rows($c, $p, 'variables');
    }

    public function test_ai_tool_receives_only_references_not_values(): void
    {
        [$u,$p] = $this->context();
        $this->native(['count' => 1, 'variables' => ['SERVICE_PASS' => 'do-not-send-to-ai']]);
        $chat = Conversation::create(['user_id' => $u->id, 'project_id' => $p->id]);
        $run = AgentRun::create(['user_id' => $u->id, 'conversation_id' => $chat->id, 'status' => 'running']);
        $result = app(ActionBroker::class)->execute($run, 'tenant_variables', []);
        $this->assertTrue($result['values_protected']);
        $this->assertSame('{{SERVICE_PASS}}', $result['variables'][0]['reference']);
        $this->assertStringNotContainsString('do-not-send-to-ai', json_encode($result));
    }
}
