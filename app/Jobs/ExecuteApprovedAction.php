<?php

namespace App\Jobs;

use App\Events\WorkspaceChanged;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Message;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\Audit;
use App\Services\BiBusy;
use App\Services\BiCredentials;
use App\Services\BiDesks;
use App\Services\BiTablePresentation;
use App\Services\BiValidationFailure;
use App\Services\ProjectPreviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ExecuteApprovedAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public string $approvalId) {}

    public function handle(ActionBroker $broker): void
    {
        $approval = Approval::find($this->approvalId);
        if (! $approval || $approval->status !== 'approved') {
            return;
        }
        $lock = Cache::lock($approval->project_id === null ? 'bi-operation:'.$approval->id : 'project:'.$approval->project_id, 630);
        if (! $lock->get()) {
            $this->release(3);

            return;
        }
        $nextRun = null;
        try {
            $claimed = Approval::whereKey($approval->id)->where('status', 'approved')->update(['status' => 'executing']);
            if (! $claimed) {
                return;
            }
            $approval->refresh();
            if ($approval->type === 'manual' && $approval->agent_run_id === null && ($approval->payload['origin'] ?? '') === 'manual') {
                $result = $broker->performApproved($approval);
                if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 60000) {
                    $result = array_intersect_key($result, array_flip(['managed_resource', 'management_warning', 'publication', 'published_resource', 'confirmed_name'])) + ['summary' => 'Operação concluída; confira o recurso no catálogo.'];
                }
                DB::transaction(function () use ($approval, $result): void {
                    $approval->update(['status' => 'completed', 'payload' => [...$approval->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result]]]);
                    app(ProjectPreviews::class)->recordCompletedChange($approval);
                });
                Audit::record($approval->user_id, 'bi.manual_action.completed', 'Operação de BI concluída pela interface.', $approval->project_id, ['approval_id' => $approval->id, 'resource' => $approval->payload['action']['resource'] ?? null]);

                return;
            }
            $run = AgentRun::find($approval->agent_run_id);
            if (! $run || $run->user_id !== $approval->user_id || in_array($run->status, ['cancel_requested', 'cancelled', 'failed'], true)) {
                throw new RuntimeException('A tarefa foi interrompida antes da execução autorizada.');
            }
            $run->update(['status' => 'running', 'heartbeat_at' => now()]);
            $result = $broker->performApproved($approval);
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 60000) {
                $result = array_intersect_key($result, array_flip(['publication', 'published_resource', 'confirmed_name'])) + ['resource' => $approval->payload['action']['resource'], 'summary' => 'A operação foi concluída; inspecione o recurso para obter sua configuração canônica.'];
            }
            $nextRun = DB::transaction(function () use ($approval, $run, $result) {
                $run->conversation()->lockForUpdate()->firstOrFail();
                $approval->update(['status' => 'completed', 'payload' => [...$approval->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result]]]);
                app(ProjectPreviews::class)->recordCompletedChange($approval);
                $cancelled = $run->fresh()->status === 'cancel_requested';
                $run->update(['status' => $cancelled ? 'cancelled' : 'completed', 'finished_at' => now(), 'heartbeat_at' => now()]);
                $message = Message::create(['conversation_id' => $run->conversation_id, 'role' => 'system', 'content' => ($approval->payload['action']['action'] === 'run_schedule' ? 'Solicitação de carga enviada ao BI; o resultado final ainda precisa ser correlacionado ao run_id. ' : 'Ação autorizada concluída: ').$approval->title.' ('.($approval->payload['action']['resource'] ?? 'recurso').').', 'status' => 'completed', 'metadata' => ['approval_id' => $approval->id, 'continuation' => true, 'tool_result' => $result, 'action' => $approval->payload['action']]]);

                if ($cancelled) {
                    return null;
                }

                return AgentRun::create(['conversation_id' => $run->conversation_id, 'user_id' => $run->user_id, 'input_message_id' => $message->id]);
            });
            Audit::record($approval->user_id, 'approval.executed', 'Ação aprovada concluída; IA retomada para validação.', $approval->project_id, ['approval_id' => $approval->id]);
        } catch (Throwable $e) {
            $interrupted = AgentRun::whereKey($approval->agent_run_id)->whereIn('status', ['cancelled', 'cancel_requested'])->exists();
            if ($e instanceof BiBusy && ! $interrupted && in_array($approval->payload['action']['action'] ?? '', ['push', 'run_flow_node'], true) && $approval->expires_at->isFuture()) {
                $approval->update(['status' => 'approved']);
                AgentRun::whereKey($approval->agent_run_id)->where('status', 'running')->update(['heartbeat_at' => now()]);
                $this->release(5);

                return;
            }
            $this->failAction($approval, $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível confirmar o resultado da ação. Confira o recurso no BI antes de repetir.');
            if ($e instanceof BiValidationFailure && ! $interrupted) {
                $nextRun = $this->resumeValidation($approval, $e);
            }
        } finally {
            if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
                app(BiCredentials::class)->cleanup($approval);
            }
            if (in_array($approval->payload['action']['action'] ?? '', BiTablePresentation::ACTIONS, true)) {
                app(BiTablePresentation::class)->cleanup($approval);
            }
            if (in_array($approval->payload['action']['action'] ?? '', BiDesks::ACTIONS, true)) {
                app(BiDesks::class)->cleanup($approval);
            }
            $lock->release();
            try {
                event(new WorkspaceChanged($approval->user_id, $approval->agent_run_id));
            } catch (Throwable) {
            }
        }
        if ($nextRun) {
            RunAgentTurn::dispatch($nextRun->id)->onQueue('agents');
        }
    }

    private function resumeValidation(Approval $approval, BiValidationFailure $failure): ?AgentRun
    {
        $run = AgentRun::find($approval->agent_run_id);
        $user = User::find($approval->user_id);
        $attempt = (int) ($run?->input_message_id ? Message::find($run->input_message_id)?->metadata['validation_repair_attempt'] ?? 0 : 0) + 1;
        if (! $run || ! $user?->canStartWork() || $user->role === 'viewer' || ! $approval->permitsUserAccess($user) || $attempt > 2 || ($failure->diagnostics['no_remote_write'] ?? false) !== true || in_array($run->fresh()->status, ['cancelled', 'cancel_requested'], true) || Connection::where('user_id', $user->id)->whereIn('provider', ['horus', 'openai'])->where('status', 'connected')->count() !== 2) {
            return null;
        }

        return DB::transaction(function () use ($run, $approval, $failure, $attempt): ?AgentRun {
            $run->conversation()->lockForUpdate()->firstOrFail();
            if (AgentRun::where('conversation_id', $run->conversation_id)->whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested'])->exists()) {
                return null;
            }
            $message = Message::create(['conversation_id' => $run->conversation_id, 'role' => 'system', 'content' => 'A validação local falhou antes do envio ao BI. A IA retomará a correção do arquivo e preparará uma nova revisão se necessário.', 'status' => 'completed', 'metadata' => ['approval_id' => $approval->id, 'continuation' => true, 'validation_repair' => true, 'validation_repair_attempt' => $attempt, 'tool_result' => ['status' => 'validation_failed', 'validation' => $failure->diagnostics], 'action' => $approval->payload['action']]]);
            Audit::record($approval->user_id, 'agent.validation_repair.queued', 'IA retomada para corrigir uma validação local, sem repetir alterações no BI.', $approval->project_id, ['approval_id' => $approval->id, 'attempt' => $attempt]);

            return AgentRun::create(['conversation_id' => $run->conversation_id, 'user_id' => $run->user_id, 'input_message_id' => $message->id]);
        });
    }

    private function failAction(Approval $approval, string $message): void
    {
        $approval->update(['status' => 'failed', 'payload' => [...$approval->payload, 'execution' => ['status' => 'failed', 'message' => $message, 'finished_at' => now()->toIso8601String()]]]);
        $run = AgentRun::find($approval->agent_run_id);
        if ($run) {
            $run->update(['status' => 'failed', 'error_code' => 'approved_action_failed', 'error_message' => $message, 'finished_at' => now()]);
            Message::create(['conversation_id' => $run->conversation_id, 'role' => 'system', 'content' => $message, 'status' => 'completed']);
        }
        Audit::record($approval->user_id, 'approval.failed', 'A execução autorizada requer atenção.', $approval->project_id, ['approval_id' => $approval->id]);
    }

    public function failed(?Throwable $exception): void
    {
        $approval = Approval::find($this->approvalId);
        if ($approval && in_array($approval->status, ['approved', 'executing'], true)) {
            $this->failAction($approval, 'O worker foi interrompido. Confira o estado do recurso no BI antes de repetir a ação; ela não será executada novamente automaticamente.');
            if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
                app(BiCredentials::class)->cleanup($approval);
            }
            if (in_array($approval->payload['action']['action'] ?? '', BiTablePresentation::ACTIONS, true)) {
                app(BiTablePresentation::class)->cleanup($approval);
            }
            if (in_array($approval->payload['action']['action'] ?? '', BiDesks::ACTIONS, true)) {
                app(BiDesks::class)->cleanup($approval);
            }
        }
    }
}
