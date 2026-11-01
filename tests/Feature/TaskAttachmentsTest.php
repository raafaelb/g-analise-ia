<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\AttachmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TaskAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Referências privadas']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);

        return [$user, $project, $conversation];
    }

    private function attachment(Project $project, string $name): Artifact
    {
        return Artifact::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'name' => $name, 'kind' => 'attachment', 'mime' => 'text/plain', 'path' => 'uploads/'.$project->user_id.'/'.$project->id.'/'.Str::uuid(), 'metadata' => ['extraction' => ['status' => 'queued']]]);
    }

    public function test_selected_files_are_saved_in_order_with_the_user_message_and_queued_run(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $conversation] = $this->context();
        $first = $this->attachment($project, 'primeiro.csv');
        $second = $this->attachment($project, '<referência>.txt');

        $response = $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Compare estas referências.', 'attachment_ids' => [$second->id, $first->id]])->assertAccepted();

        $run = AgentRun::findOrFail($response->json('run.id'));
        $this->assertSame([$second->id, $first->id], $run->attachment_ids);
        $message = Message::findOrFail($run->input_message_id);
        $this->assertSame([['id' => $second->id, 'name' => '<referência>.txt'], ['id' => $first->id, 'name' => 'primeiro.csv']], $message->metadata['attachments']);
        Queue::assertPushed(RunAgentTurn::class, fn ($job): bool => $job->runId === $run->id);
    }

    public function test_omitting_the_selection_snapshots_the_first_twelve_files_at_send_time(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $conversation] = $this->context();
        $expected = [];
        for ($index = 1; $index <= 13; $index++) {
            $artifact = $this->attachment($project, 'referencia-'.$index.'.txt');
            if ($index <= 12) {
                $expected[] = $artifact->id;
            }
        }

        $response = $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Use as referências do projeto.'])->assertAccepted();

        $run = AgentRun::findOrFail($response->json('run.id'));
        $this->assertSame($expected, $run->attachment_ids);
        $this->assertCount(12, Message::findOrFail($run->input_message_id)->metadata['attachments']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public function test_an_empty_selection_stays_empty_after_another_file_is_uploaded(): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $conversation] = $this->context();
        $this->attachment($project, 'nao-enviar.csv');
        $response = $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Explique o projeto sem usar anexos novos.', 'attachment_ids' => []])->assertAccepted();
        $this->attachment($project, 'enviado-depois.txt');
        $run = AgentRun::findOrFail($response->json('run.id'));

        $this->assertSame([], app(AttachmentContext::class)->idsForRun($run));
        $this->assertSame([], Message::findOrFail($run->input_message_id)->metadata['attachments']);
        Queue::assertPushed(RunAgentTurn::class, 1);
    }

    public static function unavailableFiles(): array
    {
        return ['another user' => ['user'], 'another project of the same user' => ['project'], 'a dashboard export' => ['kind'], 'missing file' => ['missing']];
    }

    #[DataProvider('unavailableFiles')]
    public function test_unavailable_files_return_422_without_saving_or_queuing_a_task(string $type): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, $project, $conversation] = $this->context();
        $other = Project::create(['user_id' => $type === 'user' ? User::factory()->create()->id : $user->id, 'name' => 'Outro projeto']);
        $artifact = $this->attachment(in_array($type, ['user', 'project'], true) ? $other : $project, 'arquivo-indisponivel.txt');
        if ($type === 'kind') {
            $artifact->update(['kind' => 'dashboard_export']);
        }
        $id = $type === 'missing' ? (string) Str::uuid() : $artifact->id;

        $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Analise.', 'attachment_ids' => [$id]])->assertUnprocessable()->assertJsonPath('errors.attachment_ids.0', 'Um dos anexos não está disponível neste projeto. Atualize a lista e escolha novamente.');

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public static function invalidSelections(): array
    {
        $id = '019a1234-1234-7123-8123-123456789012';

        return ['not a list' => [['file' => $id], 'attachment_ids'], 'duplicate file' => [[$id, $id], 'attachment_ids.0'], 'invalid identifier' => [['invalid'], 'attachment_ids.0'], 'too many files' => [array_map(fn (int $index): string => sprintf('019a1234-1234-7123-8123-%012d', $index), range(1, 13)), 'attachment_ids'], 'null selection' => [null, 'attachment_ids']];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selections_return_422_without_creating_a_task(mixed $ids, string $errorField): void
    {
        Queue::fake([RunAgentTurn::class]);
        [$user, , $conversation] = $this->context();

        $this->actingAs($user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['content' => 'Analise.', 'attachment_ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors($errorField);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgentTurn::class);
    }

    public function test_preparation_includes_the_selected_thirteenth_file_and_keeps_the_chosen_order(): void
    {
        Storage::fake('local');
        [, $project] = $this->context();
        $root = storage_path('framework/testing/task-attachments-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $artifacts = [];
        for ($index = 1; $index <= 13; $index++) {
            $artifact = $this->attachment($project, 'referencia-'.$index.'.txt');
            Storage::disk('local')->put($artifact->path, 'CONTEUDO-ARQUIVO-'.$index);
            $artifacts[] = $artifact;
        }
        try {
            $inputs = app(AttachmentContext::class)->inputs($project, [$artifacts[12]->id, $artifacts[0]->id]);

            $this->assertStringContainsString('CONTEUDO-ARQUIVO-13', $inputs[0]['text']);
            $this->assertStringNotContainsString('CONTEUDO-ARQUIVO-2', $inputs[0]['text']);
            $manifest = json_decode(substr($inputs[0]['text'], strpos($inputs[0]['text'], '{')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([$artifacts[12]->id, $artifacts[0]->id], array_column($manifest['attachments'], 'id'));
            $this->assertSame(11, $manifest['omitted']);
            $this->assertNull($artifacts[1]->fresh()->metadata['extraction']['sha256'] ?? null);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_a_legacy_run_snapshots_once_and_does_not_adopt_later_uploads(): void
    {
        [$user, $project, $conversation] = $this->context();
        $first = $this->attachment($project, 'original.txt');
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id]);
        $context = app(AttachmentContext::class);
        $this->assertSame([$first->id], $context->idsForRun($run));
        $this->attachment($project, 'posterior.txt');

        $this->assertSame([$first->id], $context->idsForRun($run->fresh()));
    }

    public function test_a_file_removed_after_send_does_not_silently_replace_the_selection(): void
    {
        [$user, $project, $conversation] = $this->context();
        $first = $this->attachment($project, 'original.txt');
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'attachment_ids' => [$first->id]]);
        $first->delete();
        $this->attachment($project, 'substituto.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Um dos anexos não está disponível neste projeto. Atualize a lista e escolha novamente.');
        app(AttachmentContext::class)->idsForRun($run);
    }

    public function test_broker_lists_only_selected_extracted_files_and_refuses_a_new_read_of_an_excluded_file(): void
    {
        Storage::fake('local');
        [$user, $project, $conversation] = $this->context();
        $root = storage_path('framework/testing/task-file-access-'.Str::uuid());
        config(['horus.workspace_root' => $root]);
        $chosen = $this->attachment($project, 'escolhido.txt');
        $excluded = $this->attachment($project, 'outro.txt');
        Storage::disk('local')->put($chosen->path, 'ARQUIVO-ESCOLHIDO');
        Storage::disk('local')->put($excluded->path, 'ARQUIVO-EXCLUIDO-DA-SELECAO');
        $context = app(AttachmentContext::class);
        $context->extract($chosen);
        $context->extract($excluded);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'attachment_ids' => [$chosen->id], 'status' => 'running']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => []]);
        try {
            $broker = app(ActionBroker::class);
            $files = $broker->execute($run, 'workspace_file', ['operation' => 'list'])['files'];
            $this->assertContains('attachments/'.$chosen->id.'.txt', array_column($files, 'path'));
            $this->assertNotContains('attachments/'.$excluded->id.'.txt', array_column($files, 'path'));
            $read = $broker->execute($run, 'workspace_file', ['operation' => 'read', 'path' => 'attachments/'.$chosen->id.'.txt']);
            $this->assertSame('ARQUIVO-ESCOLHIDO', $read['content']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Este anexo não foi selecionado para a tarefa atual.');
            $broker->execute($run, 'workspace_file', ['operation' => 'read', 'path' => 'attachments/'.$excluded->id.'.txt']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_mismatched_run_ownership_cannot_snapshot_a_different_users_attachments(): void
    {
        [$user] = $this->context();
        [, $foreignProject, $foreignConversation] = $this->context();
        $this->attachment($foreignProject, 'referencia-privada.txt');
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $foreignConversation->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Os anexos não pertencem ao contexto desta tarefa.');
        app(AttachmentContext::class)->idsForRun($run);
    }
}
