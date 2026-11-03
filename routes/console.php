<?php

use App\Jobs\ObserveBiLoadExecution;
use App\Jobs\OperationsHeartbeat;
use App\Models\AgentRun;
use App\Models\BiLoadExecution;
use App\Models\HelpQuestion;
use App\Models\User;
use App\Services\Audit;
use App\Services\BiScheduleRuns;
use App\Services\BiWritebackExports;
use App\Services\UserAccessRevocation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('horus:user {email} {--name=} {--role=editor}', function () {
    $role = $this->option('role') === 'author' ? 'editor' : $this->option('role');
    if (! in_array($role, ['owner', 'admin', 'editor', 'viewer'], true)) {
        $this->error('Perfil inválido.');

        return 1;
    }
    $email = strtolower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('E-mail inválido.');

        return 1;
    }
    $matches = User::whereRaw('LOWER(email) = ?', [$email])->get();
    if ($matches->count() > 1 || $matches->contains(fn (User $user) => $user->suite_subject !== null)) {
        $this->error('Este cadastro deve ser gerenciado pela Suíte ou conferido pelo administrador.');

        return 1;
    }
    $password = $this->secret('Senha (mínimo de 12 caracteres)');
    if (! is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 64 || strlen($password) > 72 || str_contains($password, "\0") || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
        $this->error('Use de 12 a 64 caracteres com maiúsculas, minúsculas e números; reduza a senha se contiver muitos caracteres especiais.');

        return 1;
    }
    $saved = DB::transaction(function () use ($email, $role, $password): bool {
        $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
        if ($user?->suite_subject !== null) {
            return false;
        }
        $user ??= new User(['email' => $email]);
        if ($user->exists) {
            $user->access_version++;
            $user->remember_token = Str::random(60);
            app(UserAccessRevocation::class)->revoke($user);
        }
        $user->name = $this->option('name') ?: $email;
        $user->password = Hash::make($password);
        $user->role = $role;
        $user->local_login_enabled = true;
        $user->must_change_password = true;
        $user->access_revision++;
        $user->save();

        return true;
    });
    if (! $saved) {
        $this->error('Este cadastro deve ser gerenciado pela Suíte.');

        return 1;
    }
    $this->info('Usuário configurado. A senha deverá ser trocada no próximo acesso.');

    return 0;
})->purpose('Cria ou redefine um acesso local sem expor a senha nos argumentos.');
Schedule::call(function () {
    Artisan::call('ganalise:expire-reviews');
    if (Schema::hasTable('bi_load_executions')) {
        BiLoadExecution::whereIn('status', BiScheduleRuns::OBSERVING)->where('updated_at', '<', now()->subMinutes(5))->limit(50)->get()->each(fn (BiLoadExecution $execution) => ObserveBiLoadExecution::dispatch($execution->id)->onQueue('agents'));
    }
    HelpQuestion::interrupted()->update(['status' => 'failed', 'error_message' => 'O atendimento foi interrompido. Envie uma nova pergunta para continuar.', 'finished_at' => now()]);
    AgentRun::whereIn('status', ['running'])->where('heartbeat_at', '<', now()->subMinutes(5))->each(function ($run) {
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'worker_lost', 'error_message' => 'O worker perdeu a conexão. Sua conversa foi preservada.']);
        Audit::record($run->user_id, 'agent.interrupted', 'Worker interrompido; tarefa requer retomada.', $run->conversation->project_id);
    });
})->everyMinute()->name('workspace-maintenance')->withoutOverlapping();

Schedule::call(function () {
    Cache::put('operations:scheduler', now()->timestamp, 300);
    OperationsHeartbeat::dispatch('actions');
    OperationsHeartbeat::dispatch('agents');
    OperationsHeartbeat::dispatch('documents');
    OperationsHeartbeat::dispatch('help');
})->everyMinute()->name('operations-heartbeat')->withoutOverlapping();
Schedule::command('ganalise:retention --apply')->dailyAt('03:40')->timezone('America/Sao_Paulo')->withoutOverlapping();

Schedule::call(fn () => app(BiWritebackExports::class)->purgeStale())->hourly()->name('ganalise.downloads.cleanup')->withoutOverlapping();
