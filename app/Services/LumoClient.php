<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class LumoClient
{
    private function sessionHome(int $userId): string
    {
        $path = storage_path('app/private/runtime-sessions/'.$userId.'/'.Str::uuid());
        File::ensureDirectoryExists($path.'/.lumo', 0700, true);

        return $path;
    }

    private function environment(string $home, array $extra = []): array
    {
        return array_merge(ProcessEnvironment::isolated(), ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home, 'LUMO_NO_UPDATE_NOTIFIER' => '1', 'APP_KEY' => false, 'OPENAI_CLIENT_SECRET' => false, 'DB_PASSWORD' => false, 'ACCESS_TOKEN' => false], $extra);
    }

    public function login(int $userId, string $email, string $password, ?string $otp): Connection
    {
        if (! config('horus.lumo.login_enabled')) {
            throw new RuntimeException('O administrador ainda não habilitou a autenticação G-Analise nesta instalação.');
        }
        $actor = User::find($userId);
        if (! $actor?->canStartWork()) {
            throw new RuntimeException('O acesso do usuário está inativo ou requer atualização da senha.');
        }
        $accessVersion = $actor->access_version;
        $home = $this->sessionHome($userId);
        try {
            $command = [config('horus.lumo.binary'), 'auth', 'login', '--server', config('horus.lumo.server'), '--no-input'];
            $p = new Process($command, $home, $this->environment($home, ['LUMO_EMAIL' => $email, 'LUMO_PASSWORD' => $password, 'LUMO_OTP' => $otp ?? '']));
            $p->setTimeout(35);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new RuntimeException('O G-Analise não concluiu o login. Confira e-mail, senha, 2FA e a permissão Uso do Lumo CLI.');
            }
            $currentActor = $actor->fresh();
            if (! $currentActor?->canStartWork() || $currentActor->access_version !== $accessVersion) {
                throw new RuntimeException('Seu acesso mudou durante o login. Entre novamente no portal antes de conectar o BI.');
            }
            $authPath = $home.'/.lumo/auth.json';
            if (! is_file($authPath)) {
                throw new RuntimeException('O Lumo não retornou uma sessão válida.');
            }
            $auth = json_decode(File::get($authPath), true, 512, JSON_THROW_ON_ERROR);
            $config = is_file($home.'/.lumo/config.json') ? json_decode(File::get($home.'/.lumo/config.json'), true) : [];
            $connection = Connection::updateOrCreate(['user_id' => $userId, 'provider' => 'horus'], ['status' => 'connected', 'account_label' => $email, 'credentials' => ['auth' => $auth, 'config' => $config], 'metadata' => ['session_generation' => (string) Str::uuid()]]);
            $this->refreshTenants($connection);

            return $connection->refresh();
        } finally {
            File::deleteDirectory($home);
        }
    }

    public function run(Connection $connection, array $arguments, ?Project $project = null, int $timeout = 45, bool $captureValidation = false, ?callable $heartbeat = null): array
    {
        $entered = false;
        try {
            return Cache::lock('lumo-session:'.$connection->id, $timeout + 20)->block(10, function () use ($connection, $arguments, $project, $timeout, $captureValidation, $heartbeat, &$entered) {
                $entered = true;
                $user = User::find($connection->user_id);
                if (! $user?->canStartWork()) {
                    throw new RuntimeException('O acesso do usuário está inativo ou requer atualização da senha.');
                }
                $connection->operationAccessVersion ??= $user->access_version;
                if ($connection->operationAccessVersion !== $user->access_version) {
                    throw new RuntimeException('Seu acesso ao portal foi alterado. Confira o recurso antes de iniciar outra operação.');
                }
                $connection->refresh();
                $generation = $connection->metadata['session_generation'] ?? null;
                $result = $this->execute($connection, $arguments, $project, $timeout, $captureValidation, $heartbeat);
                $currentUser = $user->fresh();
                if (! $currentUser?->canStartWork() || $currentUser->access_version !== $connection->operationAccessVersion) {
                    throw new RuntimeException('Seu acesso ao portal mudou durante a operação. Confira o resultado antes de repetir.');
                }
                $latest = $connection->fresh();
                if (! $latest || $latest->status !== 'connected' || ($latest->metadata['session_generation'] ?? null) !== $generation) {
                    throw new RuntimeException('A sessão G-Analise mudou durante a operação. Confira o recurso antes de repetir.');
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            if (! $entered) {
                throw new BiBusy;
            }
            throw new RuntimeException('Outra operação do BI está em andamento nesta conta. Aguarde a conclusão e tente novamente.');
        }
    }

    private function execute(Connection $connection, array $arguments, ?Project $project, int $timeout, bool $captureValidation, ?callable $heartbeat): array
    {
        if ($captureValidation && ($arguments[0] ?? null) !== 'lint') {
            throw new RuntimeException('Somente a validação local pode retornar diagnósticos de falha.');
        }
        if ($connection->provider !== 'horus' || $connection->status !== 'connected') {
            throw new RuntimeException('Conecte sua conta G-Analise BI.');
        }
        if ($project && $project->user_id !== $connection->user_id) {
            throw new RuntimeException('Contexto de usuário inválido.');
        }
        if ($project?->tenant_id) {
            app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $arguments, $connection);
        }
        if ($project) {
            app(BiMutationGuard::class)->command($project, $arguments);
        }
        $home = $this->sessionHome($connection->user_id);
        $credentials = $connection->credentials;
        $generation = $connection->metadata['session_generation'] ?? null;
        try {
            File::put($home.'/.lumo/auth.json', json_encode($credentials['auth'], JSON_THROW_ON_ERROR));
            chmod($home.'/.lumo/auth.json', 0600);
            File::put($home.'/.lumo/config.json', json_encode($credentials['config'] ?? [], JSON_THROW_ON_ERROR));
            $command = [config('horus.lumo.binary'), ...$arguments, '--format', 'json', '--no-input', '--no-color', '--server', config('horus.lumo.server')];
            if ($project) {
                $command = [...$command, '--workspace', app(WorkspaceFiles::class)->path($project)];
            }
            $process = new Process($command, $home, $this->environment($home));
            $process->setTimeout($timeout);
            if ($heartbeat === null) {
                $process->run();
            } else {
                $process->start();
                $lastHeartbeat = 0;
                while ($process->isRunning()) {
                    $process->checkTimeout();
                    if (microtime(true) - $lastHeartbeat >= 2) {
                        $heartbeat();
                        $lastHeartbeat = microtime(true);
                    }
                    usleep(100000);
                }
            }
            $output = $process->getOutput();
            if (strlen($output) > 2_000_000) {
                throw new RuntimeException('O resultado do G-Analise excedeu o limite. Reduza o escopo da consulta.');
            }
            if (! $process->isSuccessful() && ! ($arguments[0] === 'diff' && $process->getExitCode() === 1) && ! ($captureValidation && $arguments[0] === 'lint' && $process->getExitCode() === 8)) {
                if ($process->getExitCode() === 6) {
                    $failure = $output.' '.$process->getErrorOutput();
                    $session = json_decode(File::get($home.'/.lumo/auth.json'), true);
                    $expired = false;
                    try {
                        $expired = ! empty($session['expires_at']) && Carbon::parse($session['expires_at'])->isPast();
                    } catch (\Throwable) {
                    }
                    if ($expired || preg_match('/\b(401|TOKEN_EXPIRED|INVALID_TOKEN|SESSION_EXPIRED|SESSION_INVALID|REFRESH_TOKEN_EXPIRED|INVALID_REFRESH_TOKEN)\b/i', $failure)) {
                        $this->updateCurrentSession($connection, $credentials, $generation, fn (Connection $latest) => ['status' => 'reauthentication_required']);
                        throw new RuntimeException('O acesso ao G-Analise expirou. Conecte novamente.', 6);
                    }
                    $classified = BiNativeFailure::detect($arguments, 6, $output, $process->getErrorOutput());
                    if ($classified !== null) {
                        throw $classified;
                    }
                    throw new RuntimeException('Sua sessão não tem acesso a este recurso. Confira as permissões no G-Analise BI.', 6);
                }
                $classified = BiNativeFailure::detect($arguments, $process->getExitCode(), $output, $process->getErrorOutput());
                if ($classified !== null) {
                    throw $classified;
                }
                throw new RuntimeException('O Lumo não concluiu a operação (código '.$process->getExitCode().'). Confira permissões, estado do recurso e parâmetros.', $process->getExitCode());
            }
            $decoded = json_decode($output, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $output;
            if (is_array($data) && isset($data['schemaVersion'], $data['command']) && array_key_exists('data', $data)) {
                $data = $data['data'];
            }

            return ['exit_code' => $process->getExitCode(), 'data' => $data];
        } finally {
            try {
                $authPath = $home.'/.lumo/auth.json';
                if (is_file($authPath)) {
                    $renewed = json_decode(File::get($authPath), true);
                    if (is_array($renewed) && isset($renewed['access_token'],$renewed['refresh_token']) && $renewed !== ($credentials['auth'] ?? null)) {
                        $this->updateCurrentSession($connection, $credentials, $generation, fn (Connection $latest) => ['credentials' => [...$latest->credentials, 'auth' => $renewed]]);
                    }
                }
            } finally {
                File::deleteDirectory($home);
            }
        }
    }

    private function updateCurrentSession(Connection $connection, array $credentials, ?string $generation, callable $changes): void
    {
        DB::transaction(function () use ($connection, $credentials, $generation, $changes) {
            $latest = Connection::whereKey($connection->id)->lockForUpdate()->first();
            // Login and disconnect may finish while the native command is running.
            // An older command may update only the same authenticated session.
            if (! $latest || $latest->status !== 'connected' || ! $latest->credentials || ($latest->metadata['session_generation'] ?? null) !== $generation || ($latest->credentials['auth'] ?? null) !== ($credentials['auth'] ?? null)) {
                return;
            }
            $latest->update($changes($latest));
        });
        $connection->refresh();
    }

    public function refreshTenants(Connection $connection): void
    {
        $connection->refresh();
        $generation = $connection->metadata['session_generation'] ?? null;
        $result = $this->run($connection, ['tenants', 'list']);
        $data = $result['data'];
        $tenants = is_array($data) ? ($data['tenants'] ?? $data) : null;
        if (! is_array($tenants) || ! array_is_list($tenants)) {
            throw new RuntimeException('A resposta de tenants do G-Analise não pôde ser interpretada. Seus acessos anteriores foram preservados.');
        }
        if (isset($data['count']) && (int) $data['count'] !== count($tenants)) {
            throw new RuntimeException('A lista de tenants retornou incompleta. Atualize os acessos novamente antes de escolher um tenant.');
        }
        $normalized = [];
        foreach ($tenants as $tenant) {
            if (! is_array($tenant) || ! isset($tenant['id']) || (! is_int($tenant['id']) && ! is_string($tenant['id'])) || trim((string) $tenant['id']) === '') {
                throw new RuntimeException('Um tenant retornado pelo G-Analise está sem identificador válido. Seus acessos anteriores foram preservados.');
            }
            $normalized[] = ['id' => (string) $tenant['id'], 'name' => $tenant['name'] ?? $tenant['nome'] ?? ('Tenant '.$tenant['id'])];
        }
        DB::transaction(function () use ($connection, $generation, $normalized) {
            $latest = Connection::whereKey($connection->id)->lockForUpdate()->first();
            if (! $latest || $latest->status !== 'connected' || ($latest->metadata['session_generation'] ?? null) !== $generation) {
                throw new RuntimeException('A sessão G-Analise mudou durante a consulta. Atualize os acessos novamente.');
            }
            $latest->update(['metadata' => [...($latest->metadata ?? []), 'tenants' => $normalized, 'checked_at' => now()->toIso8601String()]]);
        });
        $connection->refresh();
    }

    public function initialize(Connection $connection, Project $project, bool $readOnly = false): void
    {
        if (! $project->tenant_id) {
            throw new RuntimeException('Vincule um tenant G-Analise ao projeto antes de iniciar.');
        }
        $this->refreshTenants($connection);
        if (! collect($connection->fresh()->metadata['tenants'] ?? [])->contains(fn ($t) => (string) $t['id'] === (string) $project->tenant_id)) {
            throw new RuntimeException('Este tenant não está mais disponível para seu usuário.');
        }
        $path = app(WorkspaceFiles::class)->prepare($project);
        if (! is_file($path.'/.lumo/workspace.json')) {
            $this->run($connection, ['init', $project->tenant_id, $path, ...($readOnly ? ['--fetch-only'] : [])], null, 180);
        }
    }
}
