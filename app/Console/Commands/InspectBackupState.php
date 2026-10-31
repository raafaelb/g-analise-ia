<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\BiLoadExecution;
use App\Models\HelpQuestion;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGuideProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InspectBackupState extends Command
{
    protected $signature = 'ganalise:backup-state';

    protected $description = 'Confere se há tarefas que impedem um snapshot consistente do portal.';

    public function handle(): int
    {
        $runs = AgentRun::whereIn('status', ['queued', 'running', 'cancel_requested'])->count();
        $help = Schema::hasTable('help_questions') ? HelpQuestion::whereIn('status', ['queued', 'running'])->count() : 0;
        $actions = Approval::whereIn('status', ['approved', 'executing'])->count();
        $documents = Artifact::where('kind', 'attachment')->whereIn('metadata->extraction->status', ['queued', 'processing'])->count();
        $this->line(json_encode(['ready' => $runs + $actions + $documents + $help === 0, 'maintenance' => app()->isDownForMaintenance(), 'active_runs' => $runs, 'active_help' => $help, 'active_actions' => $actions, 'pending_documents' => $documents, 'users' => User::count(), 'projects' => Project::count(), 'artifacts' => Artifact::count(), 'guide_progress' => Schema::hasTable('user_guide_progress') ? UserGuideProgress::count() : 0, 'help_questions' => Schema::hasTable('help_questions') ? HelpQuestion::count() : 0, 'bi_load_executions' => Schema::hasTable('bi_load_executions') ? BiLoadExecution::count() : 0], JSON_THROW_ON_ERROR));

        return $runs + $actions + $documents + $help === 0 ? self::SUCCESS : 2;
    }
}
