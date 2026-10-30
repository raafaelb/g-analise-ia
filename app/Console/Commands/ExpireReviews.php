<?php

namespace App\Console\Commands;

use App\Events\WorkspaceChanged;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Services\Audit;
use App\Services\BiCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpireReviews extends Command
{
    protected $signature = 'ganalise:expire-reviews {--dry-run : Apenas conferir revisões vencidas}';

    protected $description = 'Encerra revisões pendentes ou aprovadas cujo prazo terminou, sem executar operações no BI.';

    public function handle(): int
    {
        $candidates = 0;
        $expired = 0;
        foreach (Approval::whereIn('status', ['pending', 'approved'])->where('expires_at', '<', now())->cursor() as $approval) {
            $candidates++;
            if ($this->option('dry-run')) {
                continue;
            }
            $changed = DB::transaction(function () use ($approval): bool {
                $claimed = Approval::whereKey($approval->id)->whereIn('status', ['pending', 'approved'])->where('expires_at', '<', now())->update(['status' => 'expired']);
                if (! $claimed) {
                    return false;
                }
                if ($approval->agent_run_id !== null) {
                    AgentRun::whereKey($approval->agent_run_id)->where('status', 'awaiting_approval')->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'approval_expired', 'error_message' => 'O prazo da revisão terminou. Envie uma nova mensagem para continuar.']);
                }
                Audit::record($approval->user_id, 'approval.expired', 'O prazo de autorização desta revisão terminou.', $approval->project_id, ['approval_id' => $approval->id]);

                return true;
            });
            if ($changed) {
                $expired++;
                if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
                    app(BiCredentials::class)->cleanup($approval);
                }
                try {
                    event(new WorkspaceChanged($approval->user_id, $approval->agent_run_id));
                } catch (Throwable) {
                }
            }
        }
        $this->line(json_encode(['candidates' => $candidates, 'expired' => $expired, 'dry_run' => (bool) $this->option('dry-run')], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
