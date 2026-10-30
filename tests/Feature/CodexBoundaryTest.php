<?php

namespace Tests\Feature;

use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexProtocol;
use App\Services\Agent\JsonRpcBuffer;
use App\Services\ProcessEnvironment;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CodexBoundaryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function canaryLocations(): array
    {
        return ['inside workspace' => ['workspace/'], 'outside workspace' => ['']];
    }

    #[DataProvider('canaryLocations')]
    public function test_native_mutations_are_refused_and_only_portal_capabilities_are_exposed(string $location): void
    {
        $binary = config('horus.codex.binary');
        if (! is_executable($binary)) {
            $this->markTestSkipped('O teste de integração requer o binário oficial presente na imagem Docker.');
        }
        $root = sys_get_temp_dir().'/ganalise-boundary-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($root.'/home', 0700, true);
        File::ensureDirectoryExists($root.'/workspace', 0700, true);
        $markers = ['SYNTHETIC_PARENT_GUIDANCE_CANARY', 'SYNTHETIC_HOME_GUIDANCE_CANARY', 'SYNTHETIC_LUMO_GUIDANCE_CANARY'];
        File::put($root.'/AGENTS.md', $markers[0]);
        File::put($root.'/home/AGENTS.md', $markers[1]);
        File::put($root.'/home/AGENTS.override.md', CodexProtocol::homeInstructions());
        File::put($root.'/workspace/AGENTS.md', $markers[2]);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $output = $root.'/result.json';
        $canary = $root.'/'.$location.'disallowed-native-canary.txt';
        $environment = [...ProcessEnvironment::isolated(), 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $root.'/home', 'CODEX_HOME' => $root.'/home', 'ACCESS_TOKEN' => 'synthetic-token-no-account'];
        $server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/codex-provider.php')], $root, [...$environment, 'BOUNDARY_OUTPUT' => $output, 'BOUNDARY_CANARY' => $canary, 'BOUNDARY_MARKERS' => json_encode($markers, JSON_THROW_ON_ERROR)]);
        $input = new InputStream;
        $process = new Process(CodexProtocol::command($binary, 'http://'.$address.'/v1'), $root.'/workspace', $environment);
        $process->setInput($input);
        $process->setTimeout(20);
        $send = static fn (array $message) => $input->write(json_encode($message, JSON_THROW_ON_ERROR)."\n");
        $buffer = new JsonRpcBuffer;
        $denied = [];
        try {
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
            $this->assertTrue($serverReady, 'O provedor sintético local não iniciou.');
            $process->start();
            $send(['id' => 1, 'method' => 'initialize', 'params' => CodexProtocol::initialize()]);
            $until = microtime(true) + 15;
            while ($process->isRunning() && microtime(true) < $until && ! is_file($output)) {
                $process->checkTimeout();
                foreach ($buffer->receive($process->getIncrementalOutput()) as $message) {
                    if (isset($message['error'])) {
                        $this->fail('O binário recusou o contrato de protocolo do portal.');
                    }
                    if (CodexProtocol::isResponse($message, 1)) {
                        $send(['method' => 'initialized', 'params' => (object) []]);
                        $send(['id' => 2, 'method' => 'thread/start', 'params' => CodexProtocol::thread($root.'/workspace', 'gpt-6.1-sol', app(ActionBroker::class)->tools())]);
                    }
                    if (CodexProtocol::isResponse($message, 2)) {
                        $send(['id' => 3, 'method' => 'turn/start', 'params' => CodexProtocol::turn($message['result']['thread']['id'], 'Synthetic transport test. No real inference.')]);
                    }
                    if (isset($message['id'], $message['method'])) {
                        $denied[] = $message['method'];
                        $send(CodexProtocol::restrictedResponse($message));
                    }
                }
                usleep(20000);
            }
            $this->assertFileExists($output, 'O binário não concluiu o ciclo sintético de ferramenta.');
            $names = json_decode(File::get($output.'.catalog'), true, flags: JSON_THROW_ON_ERROR);
            foreach (['tenant_variables', 'refresh_coverage', 'project_image', 'horus_action', 'workspace_file'] as $tool) {
                $this->assertContains($tool, $names, 'Ferramenta do portal indisponível: '.$tool);
            }
            foreach (['exec_command', 'shell', 'shell_command', 'shell_tool', 'write_stdin', 'collaboration', 'spawn_agent', 'js', 'js_repl', 'view_image', 'web_search', 'browser', 'browser_use', 'computer_use'] as $tool) {
                $this->assertNotContains($tool, $names, 'Ferramenta nativa indevida: '.$tool);
            }
            $guidance = json_decode(File::get($output.'.guidance'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(array_fill_keys($markers, false), $guidance, 'Instruções locais foram incluídas sem passar pelo contexto controlado do portal.');
            $this->assertSame(['item/fileChange/requestApproval'], $denied);
            $this->assertTrue(json_decode(File::get($output), true, flags: JSON_THROW_ON_ERROR)['native_patch_rejected']);
            $this->assertFileDoesNotExist($canary);
        } finally {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if ($server->isRunning()) {
                $server->stop(1);
            }
            File::deleteDirectory($root);
        }
    }
}
