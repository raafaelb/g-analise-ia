<?php

/** Proves worker independence only inside the disposable HTTPS rehearsal. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Jobs\ObserveBiLoadExecution;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array('--disposable', $argv, true) || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'ganalise_ensaio_descartavel' || config('queue.default') !== 'redis' || config('cache.default') !== 'redis' || config('horus.openai.enabled') || config('horus.openai.plan_enabled') || config('horus.lumo.login_enabled') || config('suite.enabled') || config('horus.mcp.enabled') || config('horus.viewer.enabled')) {
    fwrite(STDERR, "Este teste exige o banco efêmero ganalise_ensaio_descartavel, Redis e integrações externas desabilitadas.\n");
    exit(2);
}
$prefix = 'operations:queue-rehearsal:'.bin2hex(random_bytes(12));
$file = 'operations/queue-rehearsal-'.bin2hex(random_bytes(12)).'.txt';
$parallel = in_array('--parallel', $argv, true);
$keys = ['agents_started', 'agents_finished', 'agents_worker', 'actions', 'documents', 'help', 'bi_observation'];
$report = ['ok' => false, 'isolated_database' => true, 'external_calls' => false];
try {
    $observationQueue = (new ObserveBiLoadExecution('00000000-0000-4000-8000-000000000001'))->queue;
    if ($observationQueue !== 'actions') {
        throw new RuntimeException('A conferência de cargas precisa usar a fila de ações BI, separada da IA.');
    }
    $report['bi_observation_queue'] = $observationQueue;
    Storage::disk('local')->put($file, $prefix);
    Bus::dispatch(CallQueuedClosure::create(static function () use ($prefix): void {
        Cache::put($prefix.':agents_started', microtime(true), 45);
        Cache::put($prefix.':agents_worker', gethostname().':'.getmypid(), 45);
        usleep(8_000_000);
        Cache::put($prefix.':agents_finished', microtime(true), 45);
    })->onQueue('agents'));
    $deadline = microtime(true) + 25;
    while (! Cache::has($prefix.':agents_started') && microtime(true) < $deadline) {
        usleep(100_000);
    }
    if (! Cache::has($prefix.':agents_started')) {
        throw new RuntimeException('O worker de IA não iniciou a tarefa sintética.');
    }
    foreach (['actions' => 'actions', 'documents' => 'documents', 'help' => 'help', 'bi_observation' => $observationQueue] as $key => $channel) {
        Bus::dispatch(CallQueuedClosure::create(static function () use ($prefix, $key, $channel, $file): void {
            if ($channel === 'documents' && Storage::disk('local')->get($file) !== $prefix) {
                throw new RuntimeException('O worker de documentos não recebeu o arquivo privado do portal.');
            }
            Cache::put($prefix.':'.$key, microtime(true), 45);
        })->onQueue($channel));
    }
    if ($parallel) {
        foreach (['parallel_agents' => 'agents', 'parallel_actions_1' => 'actions', 'parallel_actions_2' => 'actions'] as $key => $channel) {
            $keys[] = $key;
            Bus::dispatch(CallQueuedClosure::create(static function () use ($prefix, $key): void {
                $started = microtime(true);
                usleep(2_000_000);
                Cache::put($prefix.':'.$key, ['started' => $started, 'finished' => microtime(true), 'worker' => gethostname().':'.getmypid()], 45);
            })->onQueue($channel));
        }
    }
    do {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = Cache::get($prefix.':'.$key);
        }
        if (! in_array(null, $values, true)) {
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);
    if (in_array(null, $values, true)) {
        throw new RuntimeException('Um dos workers não concluiu a tarefa sintética no prazo.');
    }
    $independent = [];
    foreach (['actions', 'documents', 'help', 'bi_observation'] as $channel) {
        $independent[$channel] = $values[$channel] >= $values['agents_started'] && $values[$channel] < $values['agents_finished'];
    }
    $report['independent_while_agent_busy'] = $independent;
    $report['private_storage_shared_with_documents'] = $independent['documents'];
    $report['agent_duration_seconds'] = round($values['agents_finished'] - $values['agents_started'], 3);
    $report['ok'] = ! in_array(false, $independent, true);
    if ($parallel) {
        $pairs = ['agents' => [['started' => $values['agents_started'], 'finished' => $values['agents_finished'], 'worker' => $values['agents_worker']], $values['parallel_agents']], 'actions' => [$values['parallel_actions_1'], $values['parallel_actions_2']]];
        foreach ($pairs as $channel => [$first, $second]) {
            $overlap = min($first['finished'], $second['finished']) - max($first['started'], $second['started']);
            $workers = count(array_unique([$first['worker'], $second['worker']]));
            $confirmed = $overlap > 0 && $workers === 2;
            $report['parallel_workers'][$channel] = ['confirmed' => $confirmed, 'distinct_processes' => $workers, 'overlap_seconds' => round(max(0, $overlap), 3)];
            $report['ok'] = $report['ok'] && $confirmed;
        }
    }
} catch (Throwable $error) {
    $report['error'] = $error->getMessage();
} finally {
    Storage::disk('local')->delete($file);
    foreach ($keys as $key) {
        Cache::forget($prefix.':'.$key);
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['ok'] ? 0 : 1);
