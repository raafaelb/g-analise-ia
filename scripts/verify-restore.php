<?php

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\HelpQuestion;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGuideProgress;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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
    $overrides = ['APP_KEY' => $values['APP_KEY'], 'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/ganalise-restore-no-cache.php', 'DB_DATABASE' => $database, 'DB_CONNECTION' => 'pgsql', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOCAL_PREVIEW' => 'false', 'OPENAI_SIGN_IN_ENABLED' => 'false', 'LUMO_LOGIN_ENABLED' => 'false', 'BI_MCP_ENABLED' => 'false', 'BI_VIEWER_ENABLED' => 'false', 'SUITE_AUTH_ENABLED' => 'false'];
    foreach ($overrides as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    unset($values,$overrides);
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    if (config('horus.openai.enabled') || config('horus.lumo.login_enabled') || config('horus.mcp.enabled') || config('horus.viewer.enabled') || config('suite.enabled')) {
        throw new RuntimeException('Integrações externas precisam permanecer desativadas na restauração isolada.');
    }
    if (config('database.connections.pgsql.database') !== $database) {
        throw new RuntimeException('A conexão não aponta para o banco isolado.');
    }
    $guideCount = Schema::hasTable('user_guide_progress') ? UserGuideProgress::count() : 0;
    $helpCount = Schema::hasTable('help_questions') ? HelpQuestion::count() : 0;
    $manifest = json_decode(file_get_contents('/restore/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['guide_progress' => $guideCount, 'help_questions' => $helpCount] as $key => $actual) {
        if (isset($manifest['state'][$key]) && $manifest['state'][$key] !== $actual) {
            throw new RuntimeException('A restauração não preservou todo o progresso e histórico de ajuda.');
        }
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
    if (Schema::hasTable('help_questions')) {
        HelpQuestion::whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_message' => 'Dados restaurados. Reconecte sua conta antes de iniciar outro atendimento.', 'finished_at' => now()]);
    }
    Approval::whereIn('status', ['pending', 'approved', 'executing'])->update(['status' => 'expired']);
    Connection::whereNotNull('credentials')->update(['status' => 'reauthentication_required']);
    echo json_encode(['ok' => true, 'users' => User::count(), 'projects' => Project::count(), 'guide_progress_checked' => $guideCount, 'help_questions_checked' => $helpCount, 'private_files_checked' => $files, 'encrypted_credentials_checked' => $checked, 'outbound_integrations_disabled' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error_type' => get_class($e), 'message' => 'A validação isolada da restauração falhou. Os dados em uso não foram alterados.']).PHP_EOL);
    exit(1);
}
