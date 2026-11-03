<?php

namespace Tests\Feature;

use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexProtocol;
use App\Services\Agent\JsonRpcBuffer;
use App\Services\ProcessEnvironment;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentInferenceNativeTest extends TestCase
{
    public function test_the_native_codex_completes_a_synthetic_turn_through_the_authenticated_gateway(): void
    {
        $binary = config('horus.codex.binary');
        if (! is_executable($binary)) {
            $this->markTestSkipped('O teste de transporte requer o binário oficial presente na imagem Docker.');
        }
        $root = '/tmp/ganalise-inference-native-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($root.'/home', 0700, true);
        File::ensureDirectoryExists($root.'/workspace', 0700, true);
        File::put($root.'/home/AGENTS.override.md', CodexProtocol::homeInstructions());
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $backendEnvironment = [...ProcessEnvironment::isolated(),
            'PATH' => '/usr/local/bin:/usr/bin:/bin', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://localhost',
            'BROADCAST_CONNECTION' => 'null', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/database.sqlite',
            'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'GANALISE_NATIVE_TEST_ROOT' => $root,
        ];
        $fixture = base_path('tests/Fixtures/inference-native-server.php');
        $seed = new Process([PHP_BINARY, $fixture], $root, $backendEnvironment);
        $seed->setTimeout(20);
        $server = new Process([PHP_BINARY, '-S', $address, $fixture], $root, $backendEnvironment);
        $process = null;
        $input = new InputStream;
        $send = static fn (array $message) => $input->write(json_encode($message, JSON_THROW_ON_ERROR)."\n");
        $buffer = new JsonRpcBuffer;
        $completed = null;
        $text = '';
        $nativeRequests = [];
        try {
            $seed->mustRun();
            $authorization = json_decode($seed->getOutput(), true, flags: JSON_THROW_ON_ERROR)['authorization'];
            $environment = [...ProcessEnvironment::isolated(), 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $root.'/home', 'CODEX_HOME' => $root.'/home', 'ACCESS_TOKEN' => $authorization];
            $process = new Process(CodexProtocol::command($binary, 'http://'.$address.'/v1'), $root.'/workspace', $environment);
            $process->setInput($input);
            $process->setTimeout(25);
            $server->start();
            $readyUntil = microtime(true) + 3;
            $serverReady = false;
            do {
                $ready = @stream_socket_client('tcp://'.$address, $error, $errorMessage, 0.1);
                if (is_resource($ready)) {
                    $serverReady = true;
                    fclose($ready);
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $readyUntil && $server->isRunning());
            $this->assertTrue($serverReady, 'O gateway sintético local não iniciou.');
            $process->start();
            $send(['id' => 1, 'method' => 'initialize', 'params' => CodexProtocol::initialize()]);
            $until = microtime(true) + 20;
            while ($process->isRunning() && microtime(true) < $until && $completed === null) {
                $process->checkTimeout();
                foreach ($buffer->receive($process->getIncrementalOutput()) as $message) {
                    if (isset($message['error'])) {
                        $this->fail('O binário recusou o contrato do transporte de inferência.');
                    }
                    if (CodexProtocol::isResponse($message, 1)) {
                        $send(['method' => 'initialized', 'params' => (object) []]);
                        $send(['id' => 2, 'method' => 'thread/start', 'params' => CodexProtocol::thread($root.'/workspace', 'gpt-synthetic', app(ActionBroker::class)->tools())]);
                    }
                    if (CodexProtocol::isResponse($message, 2)) {
                        $send(['id' => 3, 'method' => 'turn/start', 'params' => CodexProtocol::turn($message['result']['thread']['id'], 'SYNTHETIC_NATIVE_GATEWAY_QUESTION')]);
                    }
                    if (($message['method'] ?? '') === 'item/agentMessage/delta') {
                        $text .= $message['params']['delta'] ?? '';
                    }
                    if (($message['method'] ?? '') === 'turn/completed') {
                        $completed = $message['params']['turn']['status'] ?? 'missing';
                    }
                    if (isset($message['id'], $message['method'])) {
                        $nativeRequests[] = $message['method'];
                        $send(CodexProtocol::restrictedResponse($message));
                    }
                }
                usleep(20000);
            }

            $this->assertSame('completed', $completed, 'O gateway não entregou um turno completo ao Codex.');
            $this->assertSame('SYNTHETIC_NATIVE_GATEWAY_COMPLETED', $text);
            $this->assertSame([], $nativeRequests);
            $this->assertFileExists($root.'/receipt.json');
            $this->assertSame([
                'requests' => 1, 'temporary_bearer_at_gateway' => true,
                'proxy_secret_absent_from_native_request' => true, 'backend_oauth_at_provider' => true,
                'proxy_secret_absent_from_provider_request' => true, 'prompt_received' => true,
                'stream' => true, 'store' => false,
            ], json_decode(File::get($root.'/receipt.json'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $input->close();
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if ($server->isRunning()) {
                $server->stop(1);
            }
            File::deleteDirectory($root);
        }
    }
}
