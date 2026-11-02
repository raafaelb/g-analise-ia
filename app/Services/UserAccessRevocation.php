<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\BiLoadExecution;
use App\Models\HelpQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAccessRevocation
{
    public function revoke(User $user, ?string $exceptSession = null): void
    {
        $sessions = DB::table('sessions')->where('user_id', $user->id);
        if ($exceptSession !== null) {
            $sessions->where('id', '!=', $exceptSession);
        }
        $sessions->delete();
        if (Schema::hasTable('help_questions')) {
            HelpQuestion::where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
        if (Schema::hasTable('bi_load_executions')) {
            BiLoadExecution::where('user_id', $user->id)->whereIn('status', BiScheduleRuns::OBSERVING)->update(['status' => 'access_changed']);
        }
        foreach (Approval::where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])->lockForUpdate()->get() as $approval) {
            $approval->update(['status' => 'cancelled', 'decided_at' => now()]);
            if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
                app(BiCredentials::class)->cleanup($approval);
            }
            if (in_array($approval->payload['action']['action'] ?? '', BiVariables::ACTIONS, true)) {
                app(BiVariables::class)->cleanup($approval);
            }
        }
        AgentRun::where('user_id', $user->id)->whereIn('status', ['queued', 'awaiting_approval'])->update(['status' => 'cancelled', 'finished_at' => now()]);
        AgentRun::where('user_id', $user->id)->whereIn('status', ['running', 'cancel_requested'])->update(['status' => 'cancel_requested']);
    }
}
