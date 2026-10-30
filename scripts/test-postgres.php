<?php

/** Runs the existing suite against the dedicated disposable PostgreSQL service. */
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$xml = new DOMDocument;
$xml->load($root.'/phpunit.xml');
$xml->documentElement->setAttribute('bootstrap', $root.'/vendor/autoload.php');
foreach ($xml->getElementsByTagName('directory') as $directory) {
    $directory->nodeValue = $root.'/'.ltrim(trim($directory->nodeValue), '/');
}
$settings = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres-test', 'DB_PORT' => '5432', 'DB_DATABASE' => 'ganalise_test', 'DB_USERNAME' => 'ganalise_test', 'DB_PASSWORD' => 'ganalise_test_local_only', 'DB_URL' => ''];
$php = $xml->getElementsByTagName('php')->item(0);
foreach ($settings as $name => $value) {
    foreach (['env', 'server'] as $tag) {
        foreach (iterator_to_array($php->getElementsByTagName($tag)) as $entry) {
            if ($entry->getAttribute('name') === $name) {
                $php->removeChild($entry);
            }
        }
        $entry = $xml->createElement($tag);
        $entry->setAttribute('name', $name);
        $entry->setAttribute('value', $value);
        $entry->setAttribute('force', 'true');
        $php->appendChild($entry);
    }
}
$config = tempnam(sys_get_temp_dir(), 'ganalise-postgres-tests-');
try {
    file_put_contents($config, $xml->saveXML());
    $process = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration', $config, '--colors=never', ...array_slice($argv, 1)], $root);
    $process->setTimeout(600);
    fwrite(STDOUT, "Banco de testes isolado: postgres-test / ganalise_test.\n");
    $code = $process->run(function (string $type, string $text): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $text);
    });
} finally {
    unlink($config);
}
exit($code);
