<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SuiteIdentity
{
    public function configured(): bool
    {
        $url = parse_url((string) config('suite.base_url'));

        return (bool) config('suite.enabled') && is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && in_array($url['path'] ?? '', ['', '/'], true);
    }

    public function issuer(): string
    {
        return rtrim(strtolower((string) config('suite.base_url')), '/');
    }

    /** @return array<string, mixed> */
    public function authenticate(string $email, string $password, ?string $ip): array
    {
        if (! $this->configured()) {
            return ['state' => 'unavailable'];
        }
        $headers = ['User-email' => $email, 'User-password' => $password, 'X-Inovadora-Application-Code' => (string) config('suite.application_code'), 'X-Inovadora-Application-Name' => (string) config('suite.application_name'), 'X-Inovadora-Application-Url' => route('login'), 'X-Inovadora-Request-Id' => (string) Str::uuid()];
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $headers['X-Inovadora-Client-IP'] = $ip;
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)->withHeaders($headers)->post($this->issuer().'/api/auth/suite/autenticate/check_login.json');
        } catch (ConnectionException|\InvalidArgumentException) {
            return ['state' => 'unavailable'];
        }
        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');

            return ['state' => 'limited', 'retry_after' => is_string($retry) && ctype_digit($retry) ? min(3600, max(1, (int) $retry)) : 300];
        }
        if ($response->status() === 401) {
            return ['state' => 'invalid'];
        }
        if ($response->status() !== 200 || strlen($response->body()) > 64000) {
            return ['state' => 'unavailable'];
        }
        $body = $response->json();
        if (! is_array($body) || ($body['success'] ?? null) !== true) {
            return ['state' => 'invalid'];
        }
        $identity = $body['data'] ?? null;
        if (! is_array($identity) || ! is_string($identity['uuid'] ?? null) || ! Str::isUuid($identity['uuid']) || ! is_string($identity['email'] ?? null) || strtolower(trim($identity['email'])) !== $email || ! is_string($identity['nome'] ?? null) || trim($identity['nome']) === '' || mb_strlen($identity['nome']) > 200) {
            return ['state' => 'unavailable'];
        }

        return ['state' => 'verified', 'identity' => ['uuid' => strtolower($identity['uuid']), 'name' => trim($identity['nome']), 'email' => $email]];
    }
}
