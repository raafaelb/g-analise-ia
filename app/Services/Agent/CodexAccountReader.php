<?php

namespace App\Services\Agent;

use App\Services\ProcessEnvironment;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class CodexAccountReader
{
    /** @return array{account: array|null, limits: array|null} */
    public function read(string $token): array
    {
        $binary = config('horus.codex.binary');
        if (! is_string($binary) || ! is_executable($binary)) {
            throw new RuntimeException('O cliente oficial não está disponível para consultar o plano.');
        }
        $directory = storage_path('app/private/codex-account/'.bin2hex(random_bytes(16)));
        File::ensureDirectoryExists($directory.'/work', 0700, true);
        File::ensureDirectoryExists($directory.'/home', 0700, true);
        $environment = ProcessEnvironment::isolated();
        $environment = [...$environment, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $directory.'/home', 'CODEX_HOME' => $directory.'/home', 'ACCESS_TOKEN' => $token];
        $input = new InputStream;
        $process = new Process(CodexProtocol::command($binary), $directory.'/work', $environment);
        $process->setInput($input);
        $process->setTimeout(20);
        $send = fn (array $message) => $input->write(json_encode($message, JSON_THROW_ON_ERROR)."\n");
        $buffer = new JsonRpcBuffer;
        $account = null;
        try {
            $process->start();
            $send(['id' => 1, 'method' => 'initialize', 'params' => CodexProtocol::initialize()]);
            while ($process->isRunning()) {
                $process->checkTimeout();
                foreach ($buffer->receive($process->getIncrementalOutput()) as $message) {
                    if (($message['id'] ?? null) === 1 && isset($message['error'])) {
                        throw new RuntimeException('A consulta oficial de conta não está disponível.');
                    }
                    if (CodexProtocol::isResponse($message, 1)) {
                        $send(['method' => 'initialized', 'params' => (object) []]);
                        $send(['id' => 2, 'method' => 'account/read', 'params' => ['refreshToken' => false]]);
                    }
                    if (($message['id'] ?? null) === 2) {
                        $account = $message['result']['account'] ?? null;
                        $send(['id' => 3, 'method' => 'account/rateLimits/read']);
                    }
                    if (($message['id'] ?? null) === 3) {
                        return ['account' => is_array($account) ? $account : null, 'limits' => is_array($message['result'] ?? null) ? $message['result'] : null];
                    }
                }
                usleep(25000);
            }
            throw new RuntimeException('A consulta de conta terminou sem confirmação.');
        } finally {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(1);
            }
            File::deleteDirectory($directory);
            unset($token);
        }
    }
}
