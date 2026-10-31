<?php

namespace App\Jobs;

use App\Models\BiLoadExecution;
use App\Services\BiScheduleRuns;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ObserveBiLoadExecution implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 14400;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public string $executionId) {}

    public function uniqueId(): string
    {
        return $this->executionId;
    }

    public function handle(BiScheduleRuns $runs): void
    {
        $execution = BiLoadExecution::find($this->executionId);
        if (! $execution || ! in_array($execution->status, BiScheduleRuns::OBSERVING, true)) {
            return;
        }
        $result = $runs->observeRecord($execution);
        if (in_array($result['status'], BiScheduleRuns::OBSERVING, true)) {
            self::dispatch($execution->id)->onQueue('agents')->delay(now()->addSeconds(15));
        }
    }
}
