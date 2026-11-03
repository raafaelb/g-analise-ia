<?php

namespace Tests\Feature;

use App\Services\Agent\AgentLocalProxy;
use App\Services\ProcessEnvironment;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentLocalProxyTest extends TestCase
{
    private function context(int $connections = 1): array
    {
        $root = '/tmp/ganalise-local-proxy-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($root, 0700, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = [...ProcessEnvironment::isolated(), 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'APP_ENV' => 'testing',
            'GANALISE_PROXY_TEST_ROOT' => $root, 'GANALISE_PROXY_TEST_ADDRESS' => 'tcp://'.$address,
            'GANALISE_PROXY_TEST_DEADLINE' => (string) (time() + 30), 'GANALISE_PROXY_TEST_CONNECTIONS' => (string) $connections,
        ];

        return [$root, $address, $environment];
    }

    private function start(string $mode, string $root, array $environment): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/agent-local-proxy.php'), $mode], $root, $environment);
        $process->start();
        $until = microtime(true) + 3;
        do {
            if (str_contains($process->getOutput(), 'READY')) {
                return $process;
            }
            usleep(10000);
        } while ($process->isRunning() && microtime(true) < $until);
        if ($process->isRunning()) {
            $process->stop(.5);
        }
        $this->fail('O transporte sintético não iniciou: '.$process->getErrorOutput());
    }

    /** @return resource */
    private function connect(string $address): mixed
    {
        $client = stream_socket_client('tcp://'.$address, $error, $message, 3);
        $this->assertIsResource($client);
        stream_set_timeout($client, 5);

        return $client;
    }

    /** @param resource $client */
    private function write(mixed $client, string $payload): void
    {
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($client, substr($payload, $offset, 65536));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('Não foi possível enviar o conteúdo sintético.');
            }
            $offset += $written;
        }
        stream_socket_shutdown($client, STREAM_SHUT_WR);
    }

    public function test_large_binary_payloads_and_write_half_closes_preserve_the_complete_response(): void
    {
        [$root, $address, $environment] = $this->context();
        $upstream = $this->start('upstream', $root, $environment);
        $proxy = null;
        $client = null;
        $payload = str_repeat(" \0\xff\n\r\"é", 262144);
        try {
            $proxy = $this->start('proxy', $root, $environment);
            $client = $this->connect($address);

            $this->write($client, $payload);
            $response = stream_get_contents($client);

            $this->assertSame('RECEIVED:'.hash('sha256', $payload)."\n", $response);
            $this->assertSame(['bytes' => strlen($payload), 'sha256' => hash('sha256', $payload)], json_decode(File::get($root.'/receipt-0.json'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            $proxy?->stop(.5);
            $upstream->stop(.5);
            File::deleteDirectory($root);
        }
    }

    public function test_two_connections_do_not_mix_their_request_or_response_bytes(): void
    {
        [$root, $address, $environment] = $this->context(2);
        $upstream = $this->start('upstream', $root, $environment);
        $proxy = null;
        $clients = [];
        $payloads = ['SYNTHETIC-A-'.str_repeat('a', 8192), 'SYNTHETIC-B-'.str_repeat('b', 8192)];
        try {
            $proxy = $this->start('proxy', $root, $environment);
            $clients = [$this->connect($address), $this->connect($address)];

            foreach ($clients as $index => $client) {
                $this->write($client, $payloads[$index]);
            }

            foreach ($clients as $index => $client) {
                $this->assertSame('RECEIVED:'.hash('sha256', $payloads[$index])."\n", stream_get_contents($client));
            }
        } finally {
            foreach ($clients as $client) {
                fclose($client);
            }
            $proxy?->stop(.5);
            $upstream->stop(.5);
            File::deleteDirectory($root);
        }
    }

    public function test_an_unavailable_unix_gateway_closes_the_connection_without_a_fallback(): void
    {
        [$root, $address, $environment] = $this->context();
        $proxy = null;
        $client = null;
        try {
            $proxy = $this->start('proxy', $root, $environment);
            $client = $this->connect($address);

            $response = stream_get_contents($client);

            $this->assertSame('', $response);
            $this->assertTrue(feof($client));
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            $proxy?->stop(.5);
            File::deleteDirectory($root);
        }
    }

    public function test_stopping_the_proxy_closes_an_active_tunnel(): void
    {
        [$root, $address, $environment] = $this->context();
        $upstream = $this->start('upstream', $root, $environment);
        $proxy = null;
        $client = null;
        try {
            $proxy = $this->start('proxy', $root, $environment);
            $client = $this->connect($address);
            fwrite($client, 'SYNTHETIC-PENDING-REQUEST');
            $until = microtime(true) + 2;
            while (! is_file($root.'/accepted-0') && microtime(true) < $until) {
                usleep(10000);
            }
            $this->assertFileExists($root.'/accepted-0');

            $proxy->stop(.5);

            $this->assertFalse($proxy->isRunning());
            $this->assertSame('', stream_get_contents($client));
            $this->assertTrue(feof($client));
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            $proxy?->stop(.5);
            $upstream->stop(.5);
            File::deleteDirectory($root);
        }
    }

    public static function invalidBindings(): array
    {
        return ['all interfaces' => ['tcp://0.0.0.0:8091'], 'external address' => ['tcp://192.0.2.1:8091'], 'unix listener' => ['unix:///tmp/disallowed.sock']];
    }

    #[DataProvider('invalidBindings')]
    public function test_the_proxy_cannot_listen_on_an_external_or_non_tcp_address(string $address): void
    {
        $this->expectException(RuntimeException::class);

        (new AgentLocalProxy)->serve('/tmp/synthetic-gateway.sock', $address, time() + 30);
    }
}
