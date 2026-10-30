<?php

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

try {
    $database = getenv('GANALISE_RESTORE_DATABASE');
    if (! is_string($database) || ! preg_match('/^ganalise_restore_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Banco isolado de restauração inválido.');
    }
    $values = Dotenv\Dotenv::parse(file_get_contents('/restore/config.env'));
    if (empty($values['APP_KEY'])) {
        throw new RuntimeException('APP_KEY não foi incluída no backup.');
    }
    $overrides = ['APP_KEY' => $values['APP_KEY'], 'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/ganalise-restore-no-cache.php', 'DB_DATABASE' => $database, 'DB_CONNECTION' => 'pgsql', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOCAL_PREVIEW' => 'false', 'OPENAI_SIGN_IN_ENABLED' => 'false', 'LUMO_LOGIN_ENABLED' => 'false'];
    foreach ($overrides as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    unset($values,$overrides);
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    if (config('database.connections.pgsql.database') !== $database) {
        throw new RuntimeException('A conexão não aponta para o banco isolado.');
    }
    $checked = 0;
    foreach (Connection::cursor() as $connection) {
        $credentials = $connection->credentials;
        if ($credentials !== null) {
            if (! is_array($credentials)) {
                throw new RuntimeException('Credencial restaurada inválida.');
            }$checked++;
        }unset($credentials);
    }
    $files = 0;
    foreach (Artifact::whereNotNull('path')->cursor() as $artifact) {
        if (str_starts_with($artifact->path, '/') || str_contains($artifact->path, '..') || ! is_file('/restore/files/private/'.$artifact->path)) {
            throw new RuntimeException('Um arquivo privado referenciado pelo banco não foi restaurado.');
        }$files++;
    }
    // Cópias restauradas nunca retomam tarefas remotas ou usam sessões antigas automaticamente.
    AgentRun::whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested'])->update(['status' => 'failed', 'error_code' => 'restored_requires_review', 'error_message' => 'Dados restaurados. Confira o estado atual do BI antes de retomar.', 'finished_at' => now()]);
    Approval::whereIn('status', ['pending', 'approved', 'executing'])->update(['status' => 'expired']);
    Connection::whereNotNull('credentials')->update(['status' => 'reauthentication_required']);
    echo json_encode(['ok' => true, 'users' => User::count(), 'projects' => Project::count(), 'private_files_checked' => $files, 'encrypted_credentials_checked' => $checked, 'outbound_integrations_disabled' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error_type' => get_class($e), 'message' => 'A validação isolada da restauração falhou. Os dados em uso não foram alterados.']).PHP_EOL);
    exit(1);
}
