<?php

use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexProtocol;
use App\Services\Agent\JsonRpcBuffer;
use App\Services\ProcessEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$home = sys_get_temp_dir().'/horus-codex-smoke-'.bin2hex(random_bytes(8));
File::ensureDirectoryExists($home, 0700, true);
$env = ProcessEnvironment::isolated();
$env = array_merge($env, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home, 'CODEX_HOME' => $home]);
$process = new Process(CodexProtocol::command(config('horus.codex.binary'), 'http://127.0.0.1:9'), $home, $env);
$input = new InputStream;
$process->setInput($input);
$process->setTimeout(25);
$send = fn ($m) => $input->write(json_encode($m, JSON_THROW_ON_ERROR)."\n");
$buffer = new JsonRpcBuffer;
$ok = false;
try {
    $sandboxHelper = new Process([dirname(config('horus.codex.binary')).'/codex-resources/bwrap', '--version'], $home, $env);
    $sandboxHelper->setTimeout(10);
    $sandboxHelper->mustRun();
    echo "Codex: auxiliar bubblewrap executável; isolamento do host exige ensaio próprio.\n";
    $process->start();
    $send(['id' => 1, 'method' => 'initialize', 'params' => CodexProtocol::initialize()]);
    while ($process->isRunning()) {
        $process->checkTimeout();
        foreach ($buffer->receive($process->getIncrementalOutput()) as $message) {
            if (isset($message['error'])) {
                throw new RuntimeException('Protocolo rejeitado: '.json_encode($message['error']));
            }
            if (CodexProtocol::isResponse($message, 1)) {
                $send(['method' => 'initialized', 'params' => (object) []]);
                $send(['id' => 2, 'method' => 'thread/start', 'params' => CodexProtocol::thread($home, 'smoke-no-inference', app(ActionBroker::class)->tools())]);
            }
            if (CodexProtocol::isResponse($message, 2)) {
                if (empty($message['result']['thread']['id'])) {
                    throw new RuntimeException('Thread ausente.');
                }$ok = true;
                break 2;
            }
        }usleep(30000);
    }
    if (! $ok) {
        throw new RuntimeException('Handshake não confirmado.');
    }
    echo "Codex: initialize e thread/start aceitos; nenhuma inferência solicitada.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Codex smoke falhou: '.$error->getMessage()."\n");
} finally {
    $input->close();
    if ($process->isRunning()) {
        $process->stop(1);
    }File::deleteDirectory($home);
}

if (! $ok) {
    exit(1);
}
