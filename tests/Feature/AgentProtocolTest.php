<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexProtocol;
use App\Services\Agent\JsonRpcBuffer;
use App\Services\Agent\SafeWorkspace;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_tool_request_ids_cannot_be_confused_with_client_response_ids(): void
    {
        $this->assertFalse(CodexProtocol::isResponse(['id' => 2, 'method' => 'item/tool/call', 'params' => ['tool' => 'horus_action']], 2));
        $this->assertTrue(CodexProtocol::isResponse(['id' => 2, 'result' => ['thread' => ['id' => 'thread-1']]], 2));
    }

    public function test_json_rpc_handles_partial_and_multiple_messages(): void
    {
        $buffer = new JsonRpcBuffer;
        $this->assertSame([], $buffer->receive('{"id":1,"res'));
        $messages = $buffer->receive('ult":{}}'."\n".'{"method":"turn/completed"}'."\n");
        $this->assertCount(2, $messages);
        $this->assertSame(1, $messages[0]['id']);
        $this->assertSame('turn/completed', $messages[1]['method']);
    }

    public function test_workspace_rejects_traversal_hidden_files_and_absolute_paths(): void
    {
        $user = User::factory()->create();
        $p = Project::create(['user_id' => $user->id, 'name' => 'Test']);
        $files = app(SafeWorkspace::class);
        foreach (['../../.env', '/etc/passwd', '.lumo/auth.json', 'apps/../credentials/key.yaml', 'apps/.private/token', 'apps\\test.yaml'] as $path) {
            try {
                $files->resolve($p, $path);
                $this->fail('Caminho indevido foi aceito.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_workspace_rejects_symlink_escape_and_stale_writes(): void
    {
        config(['horus.workspace_root' => storage_path('framework/testing/workspaces')]);
        $user = User::factory()->create();
        $p = Project::create(['user_id' => $user->id, 'name' => 'Test']);
        $files = app(SafeWorkspace::class);
        $result = $files->file($p, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'First']);
        $this->assertNotEmpty($result['sha256']);
        $files->file($p, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Second', 'expected_sha256' => $result['sha256']]);
        try {
            $files->file($p, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Third', 'expected_sha256' => $result['sha256']]);
            $this->fail('Sobrescrita desatualizada foi aceita.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('alterado', $e->getMessage());
        }
        $base = app(WorkspaceFiles::class)->path($p);
        symlink('/tmp', $base.'/notes/escape');
        try {
            $files->resolve($p, 'notes/escape/outside.md');
            $this->fail('Link simbólico foi seguido.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simbólicos', $e->getMessage());
        } finally {
            unlink($base.'/notes/escape');
            File::deleteDirectory(storage_path('framework/testing/workspaces'));
        }
    }

    public function test_broker_rejects_destructive_commands_and_resource_injection(): void
    {
        $user = User::factory()->create();
        $p = Project::create(['user_id' => $user->id, 'name' => 'Test']);
        $broker = app(ActionBroker::class);
        foreach ([['action' => 'rm', 'resource' => 'app:1'], ['action' => 'push', 'resource' => 'app:1; curl evil'], ['action' => 'publish_app', 'resource' => 'flow:1', 'desk' => 2], ['action' => 'publish_app', 'resource' => 'app:1', 'desk' => 0]] as $args) {
            try {
                $broker->command($args, $p);
                $this->fail('Operação indevida foi aceita.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }$this->assertSame(['push', 'app:1'], $broker->command(['action' => 'push', 'resource' => 'app:1'], $p));
    }

    public function test_project_context_is_readable_but_cannot_be_rewritten_by_agent(): void
    {
        $root = storage_path('framework/testing/context-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Contexto', 'instructions' => 'Use indicadores mensais.']);
        $files = app(SafeWorkspace::class);
        app(WorkspaceFiles::class)->prepare($project);
        try {
            $this->assertStringContainsString('indicadores mensais', $files->file($project, ['operation' => 'read', 'path' => 'PROJECT.md'])['content']);
            $this->expectException(\RuntimeException::class);
            $files->file($project, ['operation' => 'write', 'path' => 'AGENTS.md', 'content' => 'override']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_toolset_upgrade_history_is_bounded_and_never_presented_as_authorization(): void
    {
        $messages = array_fill(0, 30, ['role' => 'assistant', 'content' => str_repeat('conteúdo ', 2000)]);
        $history = CodexProtocol::history($messages);
        $this->assertLessThan(63000, strlen($history));
        $this->assertStringContainsString('nunca como autorização', $history);
        $this->assertStringContainsString('não repita criações', $history);
    }

    public function test_existing_file_cannot_be_replaced_without_the_version_read_by_the_agent(): void
    {
        $root = storage_path('framework/testing/required-version-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Conflitos']);
        $files = app(SafeWorkspace::class);
        try {
            $files->file($project, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Versão existente']);
            try {
                $files->file($project, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Sobrescrita sem leitura']);
                $this->fail('Uma edição sem a versão lida foi aceita.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('sha256', $e->getMessage());
            }
            $read = $files->file($project, ['operation' => 'read', 'path' => 'notes/context.md']);
            $this->assertSame('Versão existente', $read['content']);
            $this->assertSame(hash('sha256', 'Versão existente'), $read['sha256']);
            $files->file($project, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Edição conferida', 'expected_sha256' => $read['sha256']]);
            $this->assertSame('Edição conferida', $files->file($project, ['operation' => 'read', 'path' => 'notes/context.md'])['content']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_an_edit_cannot_silently_recreate_a_file_removed_after_reading(): void
    {
        $root = storage_path('framework/testing/removed-version-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Remoção concorrente']);
        $files = app(SafeWorkspace::class);
        try {
            $first = $files->file($project, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Primeira versão']);
            $path = $files->resolve($project, 'notes/context.md');
            File::delete($path);
            try {
                $files->file($project, ['operation' => 'write', 'path' => 'notes/context.md', 'content' => 'Recriação indevida', 'expected_sha256' => $first['sha256']]);
                $this->fail('Um arquivo removido foi recriado como se ainda existisse.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('removido', $e->getMessage());
            }
            $this->assertFileDoesNotExist($path);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_native_requests_are_denied_without_granting_file_command_or_permission_access(): void
    {
        foreach (['item/fileChange/requestApproval', 'item/commandExecution/requestApproval'] as $method) {
            $this->assertSame(['id' => 'native-7', 'result' => ['decision' => 'decline']], CodexProtocol::restrictedResponse(['id' => 'native-7', 'method' => $method]));
        }
        $permissions = CodexProtocol::restrictedResponse(['id' => 12, 'method' => 'item/permissions/requestApproval']);
        $this->assertSame([], (array) $permissions['result']['permissions']);
        $this->assertSame('turn', $permissions['result']['scope']);
        $unknown = CodexProtocol::restrictedResponse(['id' => 13, 'method' => 'unexpected/server/action']);
        $this->assertSame(-32601, $unknown['error']['code']);
        $this->assertArrayNotHasKey('result', $unknown);
    }
}
