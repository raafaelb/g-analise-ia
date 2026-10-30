<?php

namespace App\Services;

use App\Models\Connection;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiIdentity
{
    public function configured(): bool
    {
        return config('horus.openai.enabled') && ($this->localMode() || filled(config('horus.openai.client_id')));
    }

    public function localMode(): bool
    {
        return config('horus.openai.mode') === 'oss_local';
    }

    private function hostId(): string
    {
        return Cache::lock('openai-host-id', 5)->block(2, function () {
            $path = storage_path('app/private/openai-host.json');
            if (! is_file($path)) {
                File::ensureDirectoryExists(dirname($path), 0700, true);
                file_put_contents($path, json_encode(['id' => 'urn:uuid:'.Str::uuid()]));
                chmod($path, 0600);
            }

            return json_decode(file_get_contents($path), true)['id'];
        });
    }

    public function discovery(): array
    {
        return Cache::remember('openai.oidc.discovery', 3600, function () {
            $data = Http::withoutRedirecting()->timeout(15)->get('https://auth.openai.com/.well-known/openid-configuration')->throw()->json();
            if (($data['issuer'] ?? null) !== config('horus.openai.issuer')) {
                throw new RuntimeException('Emissor OpenAI inválido.');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                $this->validateEndpoint($data[$key] ?? '');
            }

            return $data;
        });
    }

    private function validateEndpoint(string $url): void
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'auth.openai.com') {
            throw new RuntimeException('Endpoint OpenAI inválido.');
        }
    }

    public function begin(?int $userId): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Sign in with ChatGPT ainda não foi configurado para esta instalação.');
        }
        $local = $this->localMode();
        if ($local && (! app()->environment('local') || request()->getHost() !== '127.0.0.1')) {
            throw new RuntimeException('O fluxo aberto local só pode iniciar em http://127.0.0.1:8088. Ele não autentica usuários de um servidor central remoto.');
        }
        $saved = $userId ? Connection::where('user_id', $userId)->where('provider', 'openai')->first() : null;
        $client = $local ? ($saved?->metadata['client_id'] ?? $saved?->metadata['pending_client_id'] ?? 'dynamic_agent_client') : config('horus.openai.client_id');
        $redirect = $local ? config('horus.openai.local_redirect_uri') : config('horus.openai.redirect_uri');
        if ($local && (parse_url($redirect, PHP_URL_SCHEME) !== 'http' || parse_url($redirect, PHP_URL_HOST) !== '127.0.0.1' || parse_url($redirect, PHP_URL_PATH) !== '/auth/openai/callback')) {
            throw new RuntimeException('O callback do fluxo aberto precisa usar HTTP em 127.0.0.1 e o caminho configurado.');
        }
        $discovery = $this->discovery();
        $verifier = Str::random(96);
        $state = Str::random(64);
        $nonce = Str::random(64);
        $transaction = ['user_id' => $userId, 'verifier' => $verifier, 'nonce' => $nonce, 'client_id' => $client, 'redirect_uri' => $redirect, 'mode' => $local ? 'oss_local' : 'hosted', 'previous_subject' => $saved?->subject, 'created_at' => time()];
        Cache::put('oauth:'.hash('sha256', $state), $transaction, 600);
        $query = ['client_id' => $client, 'response_type' => 'code', 'redirect_uri' => $redirect, 'scope' => $local ? 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct' : config('horus.openai.scopes'), 'state' => $state, 'nonce' => $nonce, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        if ($local) {
            $query['resource'] = 'https://api.openai.com/v1';
            $query['ext_agent_host_id'] = $this->hostId();
            if ($client === 'dynamic_agent_client') {
                $query['agent_name_hint'] = 'G-Analise IA';
            } elseif (! empty($saved?->credentials['id_token'])) {
                $query['id_token_hint'] = $saved->credentials['id_token'];
            }
        }

        return ['state' => $state, 'url' => $discovery['authorization_endpoint'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)];
    }

    public function consume(string $state): ?array
    {
        return Cache::lock('oauth-lock:'.hash('sha256', $state), 5)->block(2, fn () => Cache::pull('oauth:'.hash('sha256', $state)));
    }

    private function tokenRequest(): PendingRequest
    {
        $request = Http::withoutRedirecting()->asForm()->acceptJson()->timeout(20);
        if (! $this->localMode() && config('horus.openai.client_auth') === 'client_secret_basic') {
            if (! filled(config('horus.openai.client_secret'))) {
                throw new RuntimeException('Credencial OAuth confidencial não configurada.');
            }
            $request = $request->withBasicAuth(urlencode(config('horus.openai.client_id')), urlencode(config('horus.openai.client_secret')));
        }

        return $request;
    }

    public function exchange(string $code, array $transaction, ?string $issuedClient = null): array
    {
        $d = $this->discovery();
        $local = ($transaction['mode'] ?? 'hosted') === 'oss_local';
        $client = $transaction['client_id'] ?? config('horus.openai.client_id');
        if ($local && $client === 'dynamic_agent_client') {
            if (! $issuedClient || ! preg_match('/^oaiapp_[a-zA-Z0-9_-]+$/D', $issuedClient)) {
                throw new RuntimeException('O registro não retornou um identificador de cliente válido.');
            }
            $client = $issuedClient;
            if ($transaction['user_id']) {
                $pending = Connection::firstOrCreate(['user_id' => $transaction['user_id'], 'provider' => 'openai']);
                $pending->update(['metadata' => [...($pending->metadata ?? []), 'pending_client_id' => $client]]);
            }
        } elseif ($issuedClient !== null && ! hash_equals((string) $client, $issuedClient)) {
            throw new RuntimeException('O identificador do registro foi alterado.');
        }
        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => $transaction['redirect_uri'] ?? config('horus.openai.redirect_uri'), 'code_verifier' => $transaction['verifier']];
        if ($local) {
            $body['resource'] = 'https://api.openai.com/v1';
        }
        $response = $this->tokenRequest()->post($d['token_endpoint'], $body);
        if (! $response->successful()) {
            throw new RuntimeException('A OpenAI não concluiu a autorização. Tente conectar novamente.');
        }
        $tokens = $response->json();
        $identity = $this->verifyIdToken($tokens['id_token'] ?? '', $transaction['nonce'], $client);
        if ($local && ! empty($transaction['previous_subject']) && ! hash_equals($transaction['previous_subject'], hash('sha256', $identity['iss'].'|'.$client.'|'.$identity['sub']))) {
            throw new RuntimeException('A identidade não corresponde ao registro selecionado.');
        }

        return ['tokens' => $tokens, 'identity' => $identity, 'client_id' => $client, 'mode' => $local ? 'oss_local' : 'hosted'];
    }

    public function verifyIdToken(string $token, string $nonce, ?string $clientId = null): array
    {
        if (! $token) {
            throw new RuntimeException('Token de identidade ausente.');
        }
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0] ?? '', '-_', '+/')), true);
        if (! in_array($header['alg'] ?? '', ['RS256', 'ES256'], true)) {
            throw new RuntimeException('Assinatura de identidade inválida.');
        }
        $discovery = $this->discovery();
        $jwks = Cache::remember('openai.oidc.jwks', 3600, fn () => Http::withoutRedirecting()->timeout(15)->get($discovery['jwks_uri'])->throw()->json());
        if (! empty($header['kid']) && ! collect($jwks['keys'] ?? [])->contains('kid', $header['kid'])) {
            Cache::forget('openai.oidc.jwks');
            $jwks = Http::withoutRedirecting()->timeout(15)->get($discovery['jwks_uri'])->throw()->json();
            Cache::put('openai.oidc.jwks', $jwks, 3600);
        }JWT::$leeway = 5;
        $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks));
        $audiences = (array) ($claims['aud'] ?? []);
        $client = $clientId ?? config('horus.openai.client_id');
        if (($claims['iss'] ?? null) !== $discovery['issuer'] || ! in_array($client, $audiences, true) || empty($claims['sub']) || ! isset($claims['exp'],$claims['iat']) || $claims['exp'] < time() - 5 || $claims['iat'] > time() + 5 || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new RuntimeException('A identidade OpenAI não pôde ser verificada.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client) {
            throw new RuntimeException('Destinatário OpenAI inválido.');
        }

        return $claims;
    }

    public function planAuthorized(array $tokens): bool
    {
        $required = $this->localMode() ? 'chatgpt.tokens.use.direct' : config('horus.openai.inference_scope');

        return ($this->localMode() || config('horus.openai.plan_enabled')) && filled($required) && ! empty($tokens['access_token']) && in_array($required, preg_split('/\s+/', trim($tokens['scope'] ?? '')), true);
    }

    public function accessToken(Connection $connection): string
    {
        if (($connection->metadata['auth_mode'] ?? null) === 'oss_local' && ! app()->environment('local')) {
            throw new RuntimeException('Esta autorização OSS pertence à homologação local. A hospedagem central exige uma integração habilitada para esse ambiente.');
        }

        return Cache::lock('openai-refresh:'.$connection->id, 30)->block(5, function () use ($connection) {
            $connection->refresh();
            $credentials = $connection->credentials ?? [];
            if ($connection->status !== 'connected' || ! ($connection->metadata['plan_usage'] ?? false)) {
                throw new RuntimeException('Autorize o uso do seu plano ChatGPT antes de iniciar uma tarefa.');
            }
            if ($connection->expires_at && $connection->expires_at->isBefore(now()->addSeconds(60))) {
                if (empty($credentials['refresh_token'])) {
                    throw new RuntimeException('Sua autorização expirou. Conecte o ChatGPT novamente.');
                }
                $response = $this->tokenRequest()->post($this->discovery()['token_endpoint'], ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'], 'client_id' => $connection->metadata['client_id'] ?? config('horus.openai.client_id'), ...(($connection->metadata['auth_mode'] ?? '') === 'oss_local' ? ['resource' => 'https://api.openai.com/v1'] : [])]);
                if (! $response->successful()) {
                    $connection->update(['status' => 'reauthentication_required']);
                    throw new RuntimeException('Conecte o ChatGPT novamente para renovar sua autorização.');
                }
                $renewed = $response->json();
                $credentials = array_merge($credentials, $renewed);
                if (! empty($renewed['scope'])) {
                    $required = $this->localMode() ? 'chatgpt.tokens.use.direct' : config('horus.openai.inference_scope');
                    if (! $required || ! in_array($required, preg_split('/\s+/', trim($renewed['scope'])), true)) {
                        throw new RuntimeException('A autorização de uso do plano não está mais disponível.');
                    }
                }
                if (($connection->metadata['auth_mode'] ?? '') !== 'oss_local') {
                    unset($credentials['id_token']);
                }$connection->update(['credentials' => $credentials, 'expires_at' => now()->addSeconds((int) ($renewed['expires_in'] ?? 3600))]);
            }
            if (empty($credentials['access_token'])) {
                throw new RuntimeException('Sua conta foi conectada apenas para identificação. O uso do plano ainda precisa ser autorizado.');
            }

            return $credentials['access_token'];
        });
    }

    public function revoke(Connection $connection): bool
    {
        $refresh = $connection->credentials['refresh_token'] ?? null;
        if (! $refresh) {
            return true;
        }
        $endpoint = $this->discovery()['revocation_endpoint'] ?? null;
        if (! $endpoint) {
            return false;
        }$this->validateEndpoint($endpoint);

        return $this->tokenRequest()->post($endpoint, ['token' => $refresh, 'token_type_hint' => 'refresh_token', 'client_id' => $connection->metadata['client_id'] ?? config('horus.openai.client_id')])->successful();
    }
}
