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
}
