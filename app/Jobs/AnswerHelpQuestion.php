<?php

namespace App\Jobs;

use App\Models\HelpQuestion;
use App\Services\Agent\ProductHelpRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnswerHelpQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $questionId) {}

    public function handle(ProductHelpRuntime $runtime): void
    {
        if (! HelpQuestion::whereKey($this->questionId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now()])) {
            return;
        }
        $question = HelpQuestion::find($this->questionId);
        if (! $question) {
            return;
        }
        try {
            $runtime->run($question);
        } catch (Throwable $e) {
            $this->failQuestion();
            Log::warning('G-Analise IA: product help ended', ['question_id' => $this->questionId, 'exception_type' => get_class($e), 'exception_code' => (int) $e->getCode()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failQuestion();
    }

    private function failQuestion(): void
    {
        HelpQuestion::whereKey($this->questionId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_message' => 'Não foi possível concluir esta resposta. Confira sua conexão ChatGPT e os limites da conta; envie uma nova pergunta para tentar novamente. Os guias continuam disponíveis.', 'finished_at' => now()]);
    }
}
