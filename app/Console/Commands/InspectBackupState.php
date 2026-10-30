<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;

class InspectBackupState extends Command
{
    protected $signature = 'ganalise:backup-state';

    protected $description = 'Confere se há tarefas que impedem um snapshot consistente do portal.';

    public function handle(): int
    {
        $runs = AgentRun::whereIn('status', ['queued', 'running', 'cancel_requested'])->count();
        $actions = Approval::whereIn('status', ['approved', 'executing'])->count();
        $documents = Artifact::where('kind', 'attachment')->whereIn('metadata->extraction->status', ['queued', 'processing'])->count();
        $this->line(json_encode(['ready' => $runs + $actions + $documents === 0, 'maintenance' => app()->isDownForMaintenance(), 'active_runs' => $runs, 'active_actions' => $actions, 'pending_documents' => $documents, 'users' => User::count(), 'projects' => Project::count(), 'artifacts' => Artifact::count()], JSON_THROW_ON_ERROR));

        return $runs + $actions + $documents === 0 ? self::SUCCESS : 2;
    }
}
