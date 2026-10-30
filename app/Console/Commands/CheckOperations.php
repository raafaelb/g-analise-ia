<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class CheckOperations extends Command
{
    protected $signature = 'ganalise:health';

    protected $description = 'Verifica banco, cache, workers, scheduler e espaço em disco sem expor credenciais.';

    public function handle(): int
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }
        try {
            $key = 'operations:probe:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            $checks['cache'] = Cache::pull($key) === 'ok';
            foreach (['scheduler' => 180, 'worker:agents' => 1200, 'worker:documents' => 180] as $name => $seconds) {
                $last = Cache::get('operations:'.$name);
                $checks[$name] = is_numeric($last) && $last <= now()->timestamp && $last >= now()->timestamp - $seconds;
            }
        } catch (\Throwable) {
            $checks['cache'] = false;
        }
        $free = @disk_free_space(storage_path());
        $checks['disk'] = $free !== false && $free >= config('operations.minimum_free_bytes');
        $depth = [];
        foreach (['actions', 'agents', 'documents', 'default'] as $channel) {
            try {
                $depth[$channel] = Queue::size($channel);
            } catch (\Throwable) {
                $checks['queue'] = false;
            }
        }
        $ok = ! in_array(false, $checks, true);
        $this->line(json_encode(['ok' => $ok, 'checks' => $checks, 'queue_depth' => $depth, 'free_bytes' => $free, 'checked_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
