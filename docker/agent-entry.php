<?php

use App\Services\Agent\AgentLocalProxy;
use App\Services\Agent\CodexProtocol;

require __DIR__.'/AgentLocalProxy.php';
require __DIR__.'/CodexProtocol.php';

$proxyPid = null;
try {
    $authorization = getenv('ACCESS_TOKEN');
    $deadline = filter_var(getenv('AGENT_RUN_DEADLINE'), FILTER_VALIDATE_INT);
    if (! is_string($authorization) || ! preg_match('/^gaigr_[A-Za-z0-9]{64}$/D', $authorization) || $deadline === false || $deadline <= time() || $deadline > time() + 930 || posix_geteuid() !== 1000 || @filetype('/run/agent-inference/http.sock') !== 'socket') {
        throw new RuntimeException('Invalid isolated runtime context.');
    }
    foreach (['APP_KEY', 'DB_PASSWORD', 'DB_URL', 'OPENAI_CLIENT_SECRET', 'AGENT_INFERENCE_SECRET', 'AGENT_LAUNCHER_SECRET', 'LUMO_PASSWORD', 'DOCKER_HOST'] as $name) {
        if (getenv($name) !== false && getenv($name) !== '') {
            throw new RuntimeException('Unexpected privileged environment.');
        }
    }
    foreach (['/var/run/docker.sock', '/run/docker.sock', '/var/www/html/artisan', '/var/www/html/.env'] as $path) {
        if (file_exists($path)) {
            throw new RuntimeException('Unexpected host resource.');
        }
    }
    umask(0077);
    if (! is_dir('/home/agent') || is_link('/home/agent') || is_link('/home/agent/AGENTS.override.md') || is_link('/home/agent/execution')) {
        throw new RuntimeException('Invalid private home.');
    }
    if (! is_dir('/home/agent/execution') && ! mkdir('/home/agent/execution', 0700)) {
        throw new RuntimeException('Unavailable private execution directory.');
    }
    if (file_put_contents('/home/agent/AGENTS.override.md', CodexProtocol::homeInstructions(), LOCK_EX) === false) {
        throw new RuntimeException('Unavailable runtime instructions.');
    }
    $synchronization = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($synchronization === false) {
        throw new RuntimeException('Unavailable local synchronization.');
    }
    $proxyPid = pcntl_fork();
    if ($proxyPid === -1) {
        throw new RuntimeException('Unavailable local transport process.');
    }
    if ($proxyPid === 0) {
        fclose($synchronization[0]);
        try {
            (new AgentLocalProxy)->serve('/run/agent-inference/http.sock', 'tcp://127.0.0.1:8091', $deadline, function () use ($synchronization): void {
                fwrite($synchronization[1], '1');
                fclose($synchronization[1]);
            });
            exit(0);
        } catch (Throwable) {
            fwrite(STDERR, "O transporte interno da IA não iniciou.\n");
            exit(1);
        }
    }
    fclose($synchronization[1]);
    stream_set_timeout($synchronization[0], 3);
    $ready = fread($synchronization[0], 1);
    fclose($synchronization[0]);
    if ($ready !== '1') {
        throw new RuntimeException('Unavailable local transport.');
    }
    $command = CodexProtocol::command('/usr/local/bin/codex', 'http://127.0.0.1:8091/v1');
    if (! chdir('/home/agent/execution')) {
        throw new RuntimeException('Unavailable working directory.');
    }
    pcntl_exec($command[0], array_slice($command, 1), [
        'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => '/home/agent', 'CODEX_HOME' => '/home/agent', 'ACCESS_TOKEN' => $authorization,
    ]);
    throw new RuntimeException('The native process did not start.');
} catch (Throwable) {
    if (is_int($proxyPid) && $proxyPid > 0) {
        @posix_kill($proxyPid, SIGTERM);
        pcntl_waitpid($proxyPid, $status);
    }
    fwrite(STDERR, "Não foi possível iniciar a execução isolada da IA.\n");
    exit(1);
}
