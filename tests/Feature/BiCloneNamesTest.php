<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\BiCloneNames;
use App\Services\LumoClient;
use App\Services\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BiCloneNamesTest extends TestCase
{
    use RefreshDatabase;

    private function copy(): array
    {
        $root = storage_path('framework/testing/clone-name-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'source_ref' => 'app:2', 'name' => 'Cópia']);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic-only']], 'metadata' => ['session_generation' => 'session-a']]);
        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2', 'origin' => 'clone_app', 'source_ref' => 'app:1', 'receipt' => []]);
        $folder = app(WorkspaceFiles::class)->path($project).'/apps';
        File::ensureDirectoryExists($folder, 0700, true);
        $text = "# Canonical test header\nkind: app\nid: 2\ntenantId: 100\nlumo: v2\n---\n_state:\n  deskId: null\ncloned_from: 1\nconfig:\n  empty: {}\n  script: |-\n    const example = 'unchanged';\n    return { example };\ndashboards: []\nnome: Original\ntables: []\n";
        $path = $folder.'/copy--2.yaml';
        File::put($path, $text);
        File::put($folder.'/source--1.yaml', 'Original application sentinel');
        $state = (object) ['body' => Yaml::parse(explode("---\n", $text, 2)[1]), 'pushes' => 0, 'pulls' => 0, 'header_tenant' => 100, 'lint_fails' => false, 'ignore_name' => false, 'change_structure' => false, 'on_pull' => null, 'on_push' => null];

        return [$root, $project, $connection, $path, $text, $state];
    }

    private function native(object $state, string $path): void
    {
        $this->mock(LumoClient::class)->shouldReceive('run')->andReturnUsing(function (Connection $connection, array $args) use ($state, $path): array {
            if ($args === ['info', 'app:2', '--full']) {
                return ['data' => ['payload' => ['header' => ['id' => 2, 'tenantId' => $state->header_tenant], 'body' => $state->body]]];
            }
            if ($args === ['pull', 'app:2']) {
                $state->pulls++;
                if ($state->on_pull !== null) {
                    ($state->on_pull)($connection);
                }

                return ['data' => []];
            }
            if ($args === ['lint', $path]) {
                if ($state->lint_fails) {
                    throw new RuntimeException('Falha sintética de lint.');
                }

                return ['data' => ['valid' => true]];
            }
            if ($args === ['push', 'app:2']) {
                $state->pushes++;
                $before = $state->body;
                $state->body = Yaml::parse(explode("---\n", File::get($path), 2)[1]);
                $state->body['_state']['checked_at'] = 'synthetic-updated-metadata';
                if ($state->ignore_name) {
                    $state->body['nome'] = $before['nome'];
                }
                if ($state->change_structure) {
                    $state->body['tables'] = [999];
                    File::put($path, str_replace('tables: []', 'tables: [999]', File::get($path)));
                }

                if ($state->on_push !== null) {
                    ($state->on_push)($path, $state);
                }

                return ['exit_code' => 0, 'data' => ['saved' => true]];
            }
            throw new RuntimeException('Comando inesperado no teste de nome: '.json_encode($args));
        });
    }

    public function test_a_name_already_applied_by_the_provider_needs_no_second_write(): void
    {
        [$root, $project, $connection, $path, $text, $state] = $this->copy();
        $state->body['nome'] = 'Nome confirmado';
        $this->native($state, $path);
        try {
            $result = app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome confirmado');
            $this->assertSame(['verified' => true, 'name' => 'Nome confirmado', 'renamed' => false], $result);
            $this->assertSame(0, $state->pulls);
            $this->assertSame(0, $state->pushes);
            $this->assertSame($text, File::get($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_only_the_new_owned_draft_name_is_changed_and_empty_maps_scripts_and_source_files_are_preserved(): void
    {
        [$root, $project, $connection, $path, $text, $state] = $this->copy();
        $this->native($state, $path);
        try {
            $result = app(BiCloneNames::class)->ensure($connection, $project, 'app:2', "Gestão d'água: resumo");
            $this->assertTrue($result['verified']);
            $this->assertTrue($result['renamed']);
            $this->assertSame("Gestão d'água: resumo", $state->body['nome']);
            $this->assertSame(1, $state->pushes);
            $after = File::get($path);
            $this->assertStringStartsWith(explode("---\n", $text, 2)[0]."---\n", $after);
            $this->assertStringContainsString('empty: {}', $after);
            $this->assertStringContainsString("    const example = 'unchanged';", $after);
            $this->assertStringContainsString('tables: []', $after);
            $this->assertSame('Original application sentinel', File::get(dirname($path).'/source--1.yaml'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_expanded_info_metadata_is_compared_separately_from_canonical_authoring_references(): void
    {
        [$root, $project, $connection, $path, , $state] = $this->copy();
        File::put($path, str_replace('tables: []', 'tables: [123]', File::get($path))."expressions: [8]\n");
        $state->body['tables'] = [['id' => 123, 'columns' => [['id' => 9, 'label' => 'Expanded metadata']]]];
        $state->body['expressions'] = [['id' => 8, 'label' => 'Expanded expression metadata']];
        $this->native($state, $path);
        try {
            $result = app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome confirmado');
            $this->assertTrue($result['verified']);
            $this->assertSame(1, $state->pushes);
            $this->assertStringContainsString('tables: [123]', File::get($path));
            $this->assertStringContainsString('expressions: [8]', File::get($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_server_expression_audit_updates_do_not_hide_a_verified_name_only_change(): void
    {
        [$root, $project, $connection, $path, , $state] = $this->copy();
        File::append($path, "expressions:\n  - id: 8\n    expression: 'SUM(123)'\n    atualizado_em: null\n    atualizado_por: null\n");
        $state->on_push = static function (string $file, object $native): void {
            $native->body['expressions'][0]['atualizado_em'] = '2026-10-30T12:00:00.000Z';
            $native->body['expressions'][0]['atualizado_por'] = 77;
            $header = explode("---\n", File::get($file), 2)[0];
            File::put($file, $header."---\n".Yaml::dump($native->body, 10, 2));
        };
        $this->native($state, $path);
        try {
            $result = app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome confirmado');
            $this->assertTrue($result['verified']);
            $this->assertSame('SUM(123)', $state->body['expressions'][0]['expression']);
            $this->assertSame(8, $state->body['expressions'][0]['id']);
            $this->assertSame(77, $state->body['expressions'][0]['atualizado_por']);
            $this->assertSame(1, $state->pushes);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_an_expression_formula_change_is_rejected_even_when_audit_fields_also_change(): void
    {
        [$root, $project, $connection, $path, , $state] = $this->copy();
        File::append($path, "expressions:\n  - id: 8\n    expression: 'SUM(123)'\n");
        $state->on_push = static function (string $file, object $native): void {
            $native->body['expressions'][0]['expression'] = 'COUNT(123)';
            $native->body['expressions'][0]['atualizado_em'] = '2026-10-30T12:00:00.000Z';
            $native->body['expressions'][0]['atualizado_por'] = 77;
            $header = explode("---\n", File::get($file), 2)[0];
            File::put($file, $header."---\n".Yaml::dump($native->body, 10, 2));
        };
        $this->native($state, $path);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Outras configurações');
            app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome proposto');
        } finally {
            $this->assertSame(1, $state->pushes);
            File::deleteDirectory($root);
        }
    }

    public function test_a_published_copy_is_never_renamed_by_the_clone_completion_path(): void
    {
        [$root, $project, $connection, $path, $text, $state] = $this->copy();
        $state->body['_state']['deskId'] = 77;
        $this->native($state, $path);
        try {
            try {
                app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Novo nome');
                $this->fail('Um recurso já publicado foi alterado.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('rascunho', $e->getMessage());
            }
            $this->assertSame(0, $state->pushes);
            $this->assertSame($text, File::get($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_the_original_or_an_unrecorded_resource_is_rejected_before_any_remote_call(): void
    {
        [$root, $project, $connection] = $this->copy();
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cópia criada e registrada');
            app(BiCloneNames::class)->ensure($connection, $project, 'app:1', 'Nome indevido');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_a_lint_failure_restores_the_local_file_and_does_not_push(): void
    {
        [$root, $project, $connection, $path, $text, $state] = $this->copy();
        $state->lint_fails = true;
        $this->native($state, $path);
        try {
            try {
                app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome proposto');
                $this->fail('O ajuste continuou após uma falha de validação.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('lint', $e->getMessage());
            }
            $this->assertSame(0, $state->pushes);
            $this->assertSame($text, File::get($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_partial_success_with_an_ignored_name_is_not_retried_or_reported_as_verified(): void
    {
        [$root, $project, $connection, $path, , $state] = $this->copy();
        $state->ignore_name = true;
        $this->native($state, $path);
        try {
            try {
                app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome desejado');
                $this->fail('Um nome ignorado pelo servidor foi tratado como confirmado.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('outro nome', $e->getMessage());
            }
            $this->assertSame(1, $state->pushes);
            $this->assertSame('Original', $state->body['nome']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_session_rotation_after_pull_prevents_any_name_write(): void
    {
        [$root, $project, $connection, $path, $text, $state] = $this->copy();
        $state->on_pull = static fn (Connection $current) => $current->update(['metadata' => ['session_generation' => 'session-b']]);
        $this->native($state, $path);
        try {
            try {
                app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome proposto');
                $this->fail('A sessão alterada continuou a operação.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('sessão mudou', $e->getMessage());
            }
            $this->assertSame(0, $state->pushes);
            $this->assertSame($text, File::get($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_unexpected_structure_changes_returned_by_the_provider_are_not_hidden_by_a_successful_exit_code(): void
    {
        [$root, $project, $connection, $path, , $state] = $this->copy();
        $state->change_structure = true;
        $this->native($state, $path);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Outras configurações');
            app(BiCloneNames::class)->ensure($connection, $project, 'app:2', 'Nome proposto');
        } finally {
            $this->assertSame(1, $state->pushes);
            File::deleteDirectory($root);
        }
    }
}
