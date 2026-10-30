<?php

use App\Jobs\OperationsHeartbeat;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('horus:user {email} {--name=} {--role=author}', function () {
    $role = $this->option('role');
    if (! in_array($role, ['owner', 'author', 'viewer'], true)) {
        $this->error('Perfil inválido.');

        return 1;
    }
    if (! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)) {
        $this->error('E-mail inválido.');

        return 1;
    }
    $password = $this->secret('Senha (mínimo de 12 caracteres)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('A senha precisa de pelo menos 12 caracteres.');

        return 1;
    }
    $user = User::firstOrNew(['email' => $this->argument('email')]);
    $user->name = $this->option('name') ?: $this->argument('email');
    $user->password = Hash::make($password);
    $user->role = $role;
    $user->save();
    $this->info('Usuário configurado.');

    return 0;
})->purpose('Cria ou atualiza um acesso local sem expor a senha nos argumentos.');
Schedule::call(function () {
    Approval::where('status', 'pending')->where('expires_at', '<', now())->each(function ($approval) {
        $approval->update(['status' => 'expired']);
        AgentRun::whereKey($approval->agent_run_id)->where('status', 'awaiting_approval')->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'approval_expired', 'error_message' => 'O prazo da revisão terminou. Envie uma nova mensagem para continuar.']);
    });
    AgentRun::whereIn('status', ['running'])->where('heartbeat_at', '<', now()->subMinutes(5))->each(function ($run) {
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'worker_lost', 'error_message' => 'O worker perdeu a conexão. Sua conversa foi preservada.']);
        Audit::record($run->user_id, 'agent.interrupted', 'Worker interrompido; tarefa requer retomada.', $run->conversation->project_id);
    });
})->everyMinute()->name('workspace-maintenance')->withoutOverlapping();

Schedule::call(function () {
    Cache::put('operations:scheduler', now()->timestamp, 300);
    OperationsHeartbeat::dispatch('agents');
    OperationsHeartbeat::dispatch('documents');
})->everyMinute()->name('operations-heartbeat')->withoutOverlapping();
Schedule::command('ganalise:retention --apply')->dailyAt('03:40')->timezone('America/Sao_Paulo')->withoutOverlapping();
