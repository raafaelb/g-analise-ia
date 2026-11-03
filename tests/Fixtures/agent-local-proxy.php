<?php

use App\Services\Agent\AgentLocalProxy;

$root = getenv('GANALISE_PROXY_TEST_ROOT');
if (getenv('APP_ENV') !== 'testing' || ! is_string($root) || ! preg_match('#^/tmp/ganalise-local-proxy-[a-f0-9]{16}$#D', $root) || ! is_dir($root)) {
    exit(3);
}
require __DIR__.'/../../vendor/autoload.php';
if (($argv[1] ?? '') === 'proxy') {
    (new AgentLocalProxy)->serve($root.'/upstream.sock', getenv('GANALISE_PROXY_TEST_ADDRESS'), (int) getenv('GANALISE_PROXY_TEST_DEADLINE'), function (): void {
        echo "READY\n";
        flush();
    });
    exit;
}
$listener = stream_socket_server('unix://'.$root.'/upstream.sock');
if (! is_resource($listener)) {
    exit(4);
}
echo "READY\n";
flush();
$connections = (int) (getenv('GANALISE_PROXY_TEST_CONNECTIONS') ?: 1);
try {
    for ($index = 0; $index < $connections; $index++) {
        $client = stream_socket_accept($listener, 10);
        if (! is_resource($client)) {
            exit(5);
        }
        file_put_contents($root.'/accepted-'.$index, 'synthetic');
        stream_set_timeout($client, 10);
        $hash = hash_init('sha256');
        $bytes = 0;
        while (! feof($client)) {
            $chunk = fread($client, 65536);
            if ($chunk === false || ($chunk === '' && (stream_get_meta_data($client)['timed_out'] ?? false))) {
                exit(6);
            }
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
        }
        $digest = hash_final($hash);
        file_put_contents($root.'/receipt-'.$index.'.json', json_encode(['bytes' => $bytes, 'sha256' => $digest], JSON_THROW_ON_ERROR));
        @fwrite($client, 'RECEIVED:'.$digest."\n");
        fclose($client);
    }
} finally {
    fclose($listener);
}
