<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class OperationsHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $channel)
    {
        $this->onQueue($channel);
    }

    public function handle(): void
    {
        Cache::put('operations:worker:'.$this->channel, now()->timestamp, 1800);
    }
}
