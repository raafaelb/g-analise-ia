<?php

namespace App\Services\Agent;

use RuntimeException;
use Throwable;

class AgentLocalProxy
{
    private const MAX_CONNECTIONS = 8;

    private const MAX_BUFFER = 262144;

    private bool $stopped = false;

    public function serve(string $unixPath, string $listenAddress, int $deadline, ?callable $ready = null): void
    {
        if (! preg_match('#^tcp://127\.0\.0\.1:([0-9]{1,5})$#D', $listenAddress, $match) || (int) $match[1] < 1 || (int) $match[1] > 65535 || ! str_starts_with($unixPath, '/') || str_contains($unixPath, "\0") || strlen($unixPath) > 100 || $deadline <= time() || $deadline > time() + 930 || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('A configuração do transporte local é inválida.');
        }
        $server = @stream_socket_server($listenAddress, $error, $message);
        if (! is_resource($server)) {
            throw new RuntimeException('Não foi possível iniciar o transporte local.');
        }
        $this->stopped = false;
        $children = [];
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        $previousAsync = pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopped = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stopped = true;
        });
        try {
            if ($ready !== null) {
                $ready();
            }
            while (! $this->stopped && time() < $deadline) {
                $this->reap($children);
                if (count($children) >= self::MAX_CONNECTIONS) {
                    usleep(10000);

                    continue;
                }
                $client = @stream_socket_accept($server, .2);
                if (! is_resource($client)) {
                    continue;
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fclose($client);

                    continue;
                }
                if ($pid === 0) {
                    fclose($server);
                    $upstream = null;
                    try {
                        $upstream = @stream_socket_client('unix://'.$unixPath, $error, $message, min(2, max(.1, $deadline - time())));
                        if (is_resource($upstream)) {
                            $this->forward($client, $upstream, $deadline);
                        }
                    } catch (Throwable) {
                        // Transport failures close this tunnel without exposing request contents.
                    } finally {
                        fclose($client);
                        if (is_resource($upstream)) {
                            fclose($upstream);
                        }
                    }
                    exit(0);
                }
                $children[$pid] = true;
                fclose($client);
            }
        } finally {
            fclose($server);
            foreach (array_keys($children) as $pid) {
                @posix_kill($pid, SIGTERM);
            }
            $until = microtime(true) + .4;
            do {
                $this->reap($children);
                if ($children !== []) {
                    usleep(10000);
                }
            } while ($children !== [] && microtime(true) < $until);
            foreach (array_keys($children) as $pid) {
                @posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_signal(SIGINT, $previousInt);
            pcntl_async_signals($previousAsync);
        }
    }

    /** @param array<int, bool> $children */
    private function reap(array &$children): void
    {
        foreach (array_keys($children) as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset($children[$pid]);
            }
        }
    }

    /**
     * @param  resource  $client
     * @param  resource  $upstream
     */
    private function forward(mixed $client, mixed $upstream, int $deadline): void
    {
        if (! stream_set_blocking($client, false) || ! stream_set_blocking($upstream, false)) {
            return;
        }
        $peers = [$client, $upstream];
        $readOpen = [true, true];
        $writeClosed = [false, false];
        $buffers = ['', ''];
        while (! $this->stopped && time() < $deadline) {
            $read = [];
            $write = [];
            foreach ([0, 1] as $source) {
                if ($readOpen[$source] && strlen($buffers[$source]) < self::MAX_BUFFER) {
                    $read[] = $peers[$source];
                }
                if ($buffers[$source] !== '') {
                    $write[] = $peers[1 - $source];
                } elseif (! $readOpen[$source] && ! $writeClosed[1 - $source]) {
                    @stream_socket_shutdown($peers[1 - $source], STREAM_SHUT_WR);
                    $writeClosed[1 - $source] = true;
                }
            }
            if ($read === [] && $write === []) {
                break;
            }
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($read as $peer) {
                $source = $peer === $client ? 0 : 1;
                $chunk = @fread($peer, min(65536, self::MAX_BUFFER - strlen($buffers[$source])));
                if ($chunk === false) {
                    return;
                }
                if ($chunk === '' && feof($peer)) {
                    $readOpen[$source] = false;
                } else {
                    $buffers[$source] .= $chunk;
                }
            }
            foreach ($write as $peer) {
                $source = $peer === $upstream ? 0 : 1;
                $written = @fwrite($peer, $buffers[$source]);
                if ($written === false) {
                    return;
                }
                if ($written > 0) {
                    $buffers[$source] = substr($buffers[$source], $written);
                }
            }
        }
    }
}
