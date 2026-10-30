<?php

namespace App\Jobs;

use App\Models\Artifact;
use App\Services\AttachmentContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $artifactId) {}

    public function handle(AttachmentContext $context): void
    {
        if ($artifact = Artifact::find($this->artifactId)) {
            $context->extract($artifact);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $artifact = Artifact::find($this->artifactId);
        if ($artifact && ! in_array($artifact->metadata['extraction']['status'] ?? '', ['ready', 'empty', 'failed'], true)) {
            $artifact->update(['metadata' => [...($artifact->metadata ?? []), 'extraction' => ['status' => 'failed', 'message' => 'A interpretação foi interrompida ou excedeu os limites de processamento. Envie um arquivo menor.', 'updated_at' => now()->toIso8601String()]]]);
        }
    }
}
