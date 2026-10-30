<?php

namespace App\Jobs;

use App\Events\WorkspaceChanged;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Message;
use App\Models\User;
use App\Services\Agent\CodexRuntime;
use App\Services\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunAgentTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 950;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId) {}

    public function handle(CodexRuntime $runtime): void
    {
        $run = AgentRun::find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'cancel_requested'], true)) {
            return;
        }
        if ($run->status === 'cancel_requested') {
            AgentRun::whereKey($run->id)->where('status', 'cancel_requested')->update(['status' => 'cancelled', 'finished_at' => now()]);

            return;
        }
        $claimed = AgentRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now()]);
        if (! $claimed) {
            AgentRun::whereKey($run->id)->where('status', 'cancel_requested')->update(['status' => 'cancelled', 'finished_at' => now()]);

            return;
        }
        $run->refresh();
        $conversation = $run->conversation;
        $project = $conversation?->project;
        if (! $conversation || ! $project || $conversation->user_id !== $run->user_id || $project->user_id !== $run->user_id) {
            $run->update(['status' => 'failed', 'error_code' => 'invalid_project_context', 'error_message' => 'O contexto desta tarefa não está mais disponível.', 'finished_at' => now()]);
            Audit::record($run->user_id, 'agent.attention', 'Tarefa recusada por contexto de projeto inválido.');

            return;
        }
        if ($project->status === 'archived') {
            $this->failRun($run, 'Este projeto está arquivado. Reabra-o antes de iniciar outra tarefa.', 'project_archived');

            return;
        }
        $lock = Cache::lock('conversation:'.$run->conversation_id, 960);
        if (! $lock->get()) {
            $this->failRun($run, 'A conversa já está em execução. Tente novamente após a conclusão.', 'concurrent_execution');

            return;
        }
        $projectLock = Cache::lock('project:'.$run->conversation->project_id, 960);
        if (! $projectLock->get()) {
            $lock->release();
            $this->failRun($run, 'Outra tarefa está usando este projeto. Aguarde a conclusão antes de iniciar outra conversa nele.', 'concurrent_project_execution');

            return;
        }
        try {
            $run->refresh();
            $project->refresh();
            if (in_array($run->status, ['cancel_requested', 'cancelled'], true)) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);

                return;
            }
            if ($project->status === 'archived') {
                $this->failRun($run, 'Este projeto está arquivado. Reabra-o antes de iniciar outra tarefa.', 'project_archived');

                return;
            }
            if (! User::find($run->user_id)?->canStartWork() || User::find($run->user_id)?->role === 'viewer') {
                throw new RuntimeException('Seu perfil atual permite somente leitura.');
            }
            $runtime->run($run);
            $run->refresh();
            if ($run->status === 'awaiting_approval') {
                Audit::record($run->user_id, 'agent.awaiting_approval', 'Alteração preparada para revisão.', $run->conversation->project_id);
            } else {
                $cancelled = in_array($run->status, ['cancel_requested', 'cancelled'], true);
                $run->update(['status' => $cancelled ? 'cancelled' : 'completed', 'finished_at' => now()]);
                Audit::record($run->user_id, $cancelled ? 'agent.cancelled' : 'agent.completed', $cancelled ? 'Tarefa interrompida pelo usuário.' : 'Tarefa do agente concluída.', $run->conversation->project_id);
            }
        } catch (Throwable $e) {
            $run->refresh();
            if (in_array($run->status, ['cancel_requested', 'cancelled'], true)) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);
            } else {
                $this->failRun($run, $e instanceof RuntimeException ? $e->getMessage() : 'Ocorreu uma falha na execução. Sua conversa e seus arquivos foram preservados.', 'execution_failed');
            }Log::warning('G-Analise IA: agent run ended', ['run_id' => $run->id, 'exception_type' => get_class($e)]);
        } finally {
            if ($run->fresh()->status !== 'awaiting_approval') {
                Approval::where('agent_run_id', $run->id)->where('status', 'pending')->update(['status' => 'expired']);
            }
            $projectLock->release();
            $lock->release();
            try {
                event(new WorkspaceChanged($run->user_id, $run->id));
            } catch (Throwable) {
            }
        }
    }

    private function failRun(AgentRun $run, string $message, string $code): void
    {
        $run->update(['status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'finished_at' => now()]);
        Message::create(['conversation_id' => $run->conversation_id, 'role' => 'system', 'content' => $message, 'status' => 'completed', 'metadata' => ['run_id' => $run->id, 'error_code' => $code]]);
        Audit::record($run->user_id, 'agent.attention', 'Tarefa requer atenção: '.$message, $run->conversation->project_id);
    }

    public function failed(?Throwable $exception): void
    {
        $run = AgentRun::find($this->runId);
        if ($run && in_array($run->status, ['queued', 'running', 'awaiting_approval'], true)) {
            $this->failRun($run, 'A execução foi interrompida pelo worker. Envie uma nova mensagem para continuar.', 'worker_interrupted');
        }
    }
}
