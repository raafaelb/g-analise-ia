<?php

namespace App\Services;

use App\Models\Approval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class BiCredentialDrafts
{
    public function digest(array $value, string $binding): string
    {
        $sort = function (array $item) use (&$sort): array {
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $part) {
                if (is_array($part)) {
                    $item[$key] = $sort($part);
                }
            }

            return $item;
        };

        return hash_hmac('sha256', json_encode([$binding, $sort($value)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function store(Approval $approval, array $input): void
    {
        Cache::put($this->key($approval), Crypt::encryptString(json_encode(['user_id' => $approval->user_id, 'approval_id' => $approval->id, 'fingerprint' => $approval->fingerprint, 'input' => $input], JSON_THROW_ON_ERROR)), $approval->expires_at);
    }

    /** @return array<string, mixed> */
    public function read(Approval $approval): array
    {
        $encrypted = Cache::get($this->key($approval));
        if (! is_string($encrypted)) {
            throw new RuntimeException('Os dados privados desta revisão expiraram. Preencha o formulário novamente; nenhuma nova conexão será criada automaticamente.');
        }
        try {
            $value = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Os dados privados desta revisão não puderam ser validados. Preencha o formulário novamente.');
        }
        if (($value['user_id'] ?? null) !== $approval->user_id || ($value['approval_id'] ?? null) !== $approval->id || ! hash_equals($approval->fingerprint, (string) ($value['fingerprint'] ?? '')) || ! is_array($value['input'] ?? null)) {
            throw new RuntimeException('Os dados privados não correspondem a esta revisão.');
        }

        return $value['input'];
    }

    public function forget(Approval $approval): void
    {
        Cache::forget($this->key($approval));
    }

    public function key(Approval $approval): string
    {
        return 'bi-credential-input:'.$approval->user_id.':'.$approval->id;
    }
}
