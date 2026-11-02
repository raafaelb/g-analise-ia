<?php

namespace App\Services;

use App\Models\Approval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use stdClass;

class BiVariableDrafts
{
    public function digest(mixed $value, string $binding): string
    {
        $canonical = function (mixed $part) use (&$canonical): mixed {
            if ($part instanceof stdClass) {
                $fields = get_object_vars($part);
                ksort($fields);

                return (object) array_map($canonical, $fields);
            }
            if (! is_array($part)) {
                return $part;
            }
            if (! array_is_list($part)) {
                ksort($part);
            }

            return array_map($canonical, $part);
        };

        return hash_hmac('sha256', json_encode([$binding, $canonical($value)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function store(Approval $approval, array $input): void
    {
        Cache::put($this->key($approval), Crypt::encryptString(json_encode(['user_id' => $approval->user_id, 'approval_id' => $approval->id, 'fingerprint' => $approval->fingerprint, 'input' => $input], JSON_THROW_ON_ERROR)), $approval->expires_at);
    }

    public function read(Approval $approval): array
    {
        $encrypted = Cache::get($this->key($approval));
        if (! is_string($encrypted)) {
            throw new RuntimeException('Os valores privados desta revisão expiraram. Reabra o formulário e prepare outra revisão.');
        }
        try {
            $value = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Os valores privados desta revisão não puderam ser validados. Prepare outra revisão.');
        }
        if (($value['user_id'] ?? null) !== $approval->user_id || ($value['approval_id'] ?? null) !== $approval->id || ! hash_equals($approval->fingerprint, (string) ($value['fingerprint'] ?? '')) || ! is_array($value['input'] ?? null)) {
            throw new RuntimeException('Os valores privados não correspondem a esta revisão de variável.');
        }

        return $value['input'];
    }

    public function forget(Approval $approval): void
    {
        Cache::forget($this->key($approval));
    }

    public function key(Approval $approval): string
    {
        return 'bi-variable-input:'.$approval->user_id.':'.$approval->id;
    }
}
