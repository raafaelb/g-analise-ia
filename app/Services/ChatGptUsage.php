<?php

namespace App\Services;

use App\Models\Connection;
use App\Services\Agent\CodexAccountReader;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ChatGptUsage
{
    public function __construct(private OpenAiIdentity $identity, private CodexAccountReader $reader) {}

    /** @return array<string, mixed> */
    public function read(Connection $connection): array
    {
        $token = $this->identity->accessToken($connection);
        $key = 'chatgpt-usage:'.$connection->id.':'.hash('sha256', $token);

        return Cache::lock($key.':lock', 30)->block(3, fn (): array => Cache::remember($key, now()->addSeconds(120), function () use ($token): array {
            $account = null;
            $limits = null;
            $status = 'not_exposed';
            try {
                $result = $this->reader->read($token);
                $account = $result['account'];
                $limits = $result['limits'];
            } catch (Throwable) {
                $status = 'unavailable';
            }
            $plan = $this->plan($account['planType'] ?? null);
            $buckets = [];
            $source = $limits['rateLimitsByLimitId'] ?? [];
            if ($source === [] && is_array($limits['rateLimits'] ?? null)) {
                $source = [$limits['rateLimits']['limitId'] ?? 'default' => $limits['rateLimits']];
            }
            foreach (is_array($source) ? $source : [] as $id => $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }
                $plan ??= $this->plan($bucket['planType'] ?? null);
                $windows = [];
                foreach (['primary', 'secondary'] as $name) {
                    $window = $bucket[$name] ?? null;
                    if (! is_array($window) || ! is_numeric($window['usedPercent'] ?? null) || $window['usedPercent'] < 0 || $window['usedPercent'] > 100) {
                        continue;
                    }
                    $minutes = $window['windowDurationMins'] ?? null;
                    $reset = $window['resetsAt'] ?? null;
                    $windows[] = ['name' => $name, 'used_percent' => (float) $window['usedPercent'], 'remaining_percent' => round(100 - (float) $window['usedPercent'], 2), 'window_minutes' => is_int($minutes) && $minutes > 0 ? $minutes : null, 'resets_at' => is_int($reset) && $reset > 0 && $reset < 253402300800 ? gmdate('c', $reset) : null];
                }
                if ($windows !== []) {
                    $buckets[] = ['id' => mb_substr((string) $id, 0, 100), 'name' => is_string($bucket['limitName'] ?? null) ? mb_substr($bucket['limitName'], 0, 100) : 'Uso do plano', 'windows' => $windows];
                }
            }

            return ['provider' => 'openai', 'plan' => $plan, 'status' => $buckets !== [] ? 'available' : $status, 'buckets' => $buckets, 'checked_at' => now()->toIso8601String(), 'manage_url' => 'https://chatgpt.com/settings/usage', 'message' => $buckets !== [] ? 'Limites informados pela OpenAI. Outros aplicativos também podem consumir seu plano.' : ($status === 'not_exposed' ? 'Esta modalidade de conexão não disponibilizou os detalhes do plano e o saldo. Consulte o uso na sua conta ChatGPT.' : 'Não foi possível atualizar os limites agora. Consulte o uso na sua conta ChatGPT.')];
        }));
    }

    private function plan(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $value) ? $value : null;
    }
}
