<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatGptVerifier
{
    public function __construct(private OpenAiIdentity $identity) {}

    public function verify(Connection $connection, ?string $model = null): array
    {
        $token = $this->identity->accessToken($connection);
        $catalog = Http::withoutRedirecting()->withToken($token)->timeout(20)->get('https://api.openai.com/v1/models');
        if (! $catalog->successful()) {
            throw new RuntimeException('A OpenAI não retornou o catálogo autorizado (HTTP '.$catalog->status().').');
        }
        $models = collect($catalog->json('models') ?? $catalog->json('data') ?? [])->filter(fn ($item) => ($item['visibility'] ?? 'list') === 'list');
        if ($model) {
            $selected = $models->first(fn ($item) => ($item['slug'] ?? $item['id'] ?? '') === $model);
        } else {
            $selected = $models->first();
        }
        if (! $selected) {
            throw new RuntimeException('Nenhum modelo autorizado foi encontrado para este teste.');
        }
        $model = $selected['slug'] ?? $selected['id'];
        $response = Http::withoutRedirecting()->withToken($token)->timeout(90)->post('https://api.openai.com/v1/responses', ['model' => $model, 'input' => [['role' => 'user', 'content' => 'Responda apenas com a palavra OK.']], 'store' => false, 'stream' => true]);
        if (! $response->successful()) {
            throw new RuntimeException('A OpenAI não concluiu o teste (HTTP '.$response->status().'; código '.$this->safeCode($response->json('error.code')).').');
        }
        $text = '';
        $completed = false;
        $failure = null;
        foreach (preg_split('/\r?\n/', $response->body()) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (! is_array($event)) {
                continue;
            }
            if (($event['type'] ?? '') === 'response.output_text.delta') {
                $text .= $event['delta'] ?? '';
            }
            if (($event['type'] ?? '') === 'response.completed') {
                $completed = ($event['response']['status'] ?? 'completed') === 'completed';
            }
            if (in_array($event['type'] ?? '', ['response.failed', 'response.incomplete', 'error'], true)) {
                $failure = $this->safeCode($event['response']['error']['code'] ?? $event['code'] ?? $event['type']);
            }
        }
        if ($failure || ! $completed || trim($text) === '') {
            throw new RuntimeException('O teste não confirmou uma resposta completa da OpenAI'.($failure ? ' ('.$failure.')' : '').'.');
        }
        $result = ['completed' => true, 'model' => $model, 'response' => mb_substr(trim($text), 0, 200), 'verified_at' => now()->toIso8601String()];
        $connection->update(['metadata' => [...($connection->metadata ?? []), 'verification' => $result]]);

        return $result;
    }

    private function safeCode(mixed $code): string
    {
        return is_string($code) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $code) ? $code : 'provider_error';
    }
}
