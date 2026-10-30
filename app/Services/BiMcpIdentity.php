<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BiMcpIdentity
{
    public const PREFIX = 'horus_mcp:';

    public function __construct(private BiMcpHttp $http) {}

    public function provider(string $tenant): string
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $tenant)) {
            throw new RuntimeException('Escolha um ambiente BI válido.');
        }

        return self::PREFIX.$tenant;
    }

    public function configured(): bool
    {
        try {
            $this->http->resource();
            $this->redirectUri();

            return (bool) config('horus.mcp.enabled');
        } catch (RuntimeException) {
            return false;
        }
    }

    public function redirectUri(): string
    {
        $uri = (string) config('horus.mcp.redirect_uri');
        $parts = parse_url($uri);
        $local = app()->environment('local', 'testing') && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        if (preg_match('/[\x00-\x20\\\\]/', $uri) || ! is_array($parts) || empty($parts['host']) || ! str_ends_with($parts['path'] ?? '', '/auth/bi-mcp/callback') || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! (($parts['scheme'] ?? '') === 'https' || ($local && ($parts['scheme'] ?? '') === 'http'))) {
            throw new RuntimeException('O retorno da autorização MCP precisa de HTTPS, ou loopback na instalação local.');
        }

        return $uri;
    }

    public function discovery(): array
    {
        return Cache::remember('bi-mcp:discovery:'.hash('sha256', $this->http->resource()), 3600, function (): array {
            $resourcePath = parse_url($this->http->resource(), PHP_URL_PATH);
            $protected = $this->http->send('GET', $this->http->origin().'/.well-known/oauth-protected-resource'.$resourcePath);
            if ($protected['status'] === 404) {
                $protected = $this->http->send('GET', $this->http->origin().'/.well-known/oauth-protected-resource');
            }
            $resource = $this->http->json($protected);
            if ($protected['status'] !== 200 || ($resource['resource'] ?? null) !== $this->http->resource() || ! in_array($this->http->origin(), $resource['authorization_servers'] ?? [], true) || ! in_array('mcp:read', $resource['scopes_supported'] ?? [], true)) {
                throw new RuntimeException('Os metadados de autorização MCP não correspondem ao BI configurado.');
            }
            $response = $this->http->send('GET', $this->http->origin().'/.well-known/oauth-authorization-server');
            if ($response['status'] === 404) {
                $response = $this->http->send('GET', $this->http->origin().'/.well-known/openid-configuration');
            }
            $server = $this->http->json($response);
            if ($response['status'] !== 200 || ($server['issuer'] ?? '') !== $this->http->origin() || ! in_array('S256', $server['code_challenge_methods_supported'] ?? [], true) || ! in_array('code', $server['response_types_supported'] ?? [], true) || ! in_array('none', $server['token_endpoint_auth_methods_supported'] ?? ['none'], true)) {
                throw new RuntimeException('O BI não anunciou a autorização MCP com PKCE esperada.');
            }
            foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
                $this->http->endpoint((string) ($server[$key] ?? ''));
            }
            foreach (['registration_endpoint', 'revocation_endpoint'] as $key) {
                if (isset($server[$key])) {
                    $this->http->endpoint((string) $server[$key]);
                }
            }

            return $server;
        });
    }

    public function begin(User $user, string $tenant, int $appId): array
    {
        $provider = $this->provider($tenant);
        if (! $this->configured() || (! $user->canStartWork() || $user->role === 'viewer')) {
            throw new RuntimeException('A conexão MCP ainda não está disponível para esta conta ou instalação.');
        }
        [$lumo, $environment] = app(BiCatalog::class)->access($user, $tenant);
        $generation = $lumo->metadata['session_generation'] ?? null;
        if (! is_string($generation) || $generation === '') {
            throw new RuntimeException('Atualize sua conexão G-Analise BI antes de autorizar as consultas.');
        }
        app(BiMcpClient::class)->assertApp($user, $tenant, $appId);
        $this->assertLumoGeneration($user, $tenant, $generation, $user->access_version);
        $discovery = $this->discovery();
        $client = $this->client($discovery);
        $state = Str::random(64);
        $verifier = Str::random(96);
        $scopes = ['mcp:read'];
        if (in_array('offline_access', $discovery['scopes_supported'] ?? [], true)) {
            $scopes[] = 'offline_access';
        }
        $transaction = ['user_id' => $user->id, 'access_version' => $user->access_version, 'lumo_generation' => $generation, 'tenant_id' => $tenant, 'tenant_name' => $environment['name'], 'app_id' => $appId, 'resource' => $this->http->resource(), 'client_id' => $client, 'redirect_uri' => $this->redirectUri(), 'verifier' => $verifier, 'scope' => implode(' ', $scopes), 'attempt' => (string) Str::uuid()];
        $this->locked($this->lockKey($user->id, $provider), function () use ($user, $transaction, $state, $provider): void {
            $this->assertLumoGeneration($user, $transaction['tenant_id'], $transaction['lumo_generation'], $transaction['access_version']);
            Cache::put($this->attemptKey($user->id, $provider), $transaction['attempt'], 600);
            Cache::put('bi-mcp:oauth:'.hash('sha256', $state), Crypt::encryptString(json_encode($transaction, JSON_THROW_ON_ERROR)), 600);
        });
        $query = ['client_id' => $client, 'response_type' => 'code', 'redirect_uri' => $transaction['redirect_uri'], 'scope' => $transaction['scope'], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'resource' => $transaction['resource']];

        return ['state' => $state, 'url' => $discovery['authorization_endpoint'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)];
    }

    private function client(array $discovery): string
    {
        if (filled(config('horus.mcp.client_id'))) {
            return (string) config('horus.mcp.client_id');
        }
        if (empty($discovery['registration_endpoint'])) {
            throw new RuntimeException('O administrador precisa registrar o cliente MCP desta instalação.');
        }
        $key = 'bi-mcp:client:'.hash('sha256', $this->http->resource().'|'.$this->redirectUri());

        return $this->locked($key.':lock', fn (): string => Cache::remember($key, 2_592_000, function () use ($discovery): string {
            $response = $this->http->send('POST', $discovery['registration_endpoint'], ['client_name' => 'G-Analise IA', 'redirect_uris' => [$this->redirectUri()], 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
            $data = $this->http->json($response);
            if (! in_array($response['status'], [200, 201], true) || ! is_string($data['client_id'] ?? null) || $data['client_id'] === '' || strlen($data['client_id']) > 2000 || preg_match('/[\x00-\x20]/', $data['client_id']) || ($data['token_endpoint_auth_method'] ?? 'none') !== 'none') {
                throw new RuntimeException('O BI não concluiu o registro do G-Analise IA para consultas.');
            }

            return $data['client_id'];
        }), 40);
    }

    public function consume(string $state): ?array
    {
        if (strlen($state) !== 64) {
            return null;
        }
        try {
            return Cache::lock('bi-mcp:oauth-lock:'.hash('sha256', $state), 5)->block(2, function () use ($state): ?array {
                $value = Cache::pull('bi-mcp:oauth:'.hash('sha256', $state));

                return is_string($value) ? json_decode(Crypt::decryptString($value), true, 32, JSON_THROW_ON_ERROR) : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    public function complete(User $user, array $transaction, string $code, ?string $issuer): Connection
    {
        $tenant = (string) ($transaction['tenant_id'] ?? '');
        $provider = $this->provider($tenant);

        return $this->locked($this->lockKey($user->id, $provider), function () use ($user, $transaction, $code, $issuer, $tenant, $provider): Connection {
            $this->assertTransaction($user, $transaction, $issuer);
            $response = $this->http->send('POST', $this->discovery()['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $transaction['client_id'], 'redirect_uri' => $transaction['redirect_uri'], 'code_verifier' => $transaction['verifier'], 'resource' => $this->http->resource()], true);
            $tokens = $this->tokens($response, $transaction['scope']);
            try {
                $verified = app(BiMcpClient::class)->verifyGrant($tokens['credentials']['access_token'], (int) $transaction['app_id']);
                $this->assertTransaction($user, $transaction, $issuer);
                $connection = Connection::updateOrCreate(['user_id' => $user->id, 'provider' => $provider], ['status' => 'connected', 'subject' => null, 'account_label' => 'Consultas — '.$transaction['tenant_name'], 'credentials' => $tokens['credentials'], 'scopes' => $tokens['scopes'], 'metadata' => ['issuer' => $this->http->origin(), 'resource' => $this->http->resource(), 'client_id' => $transaction['client_id'], 'session_generation' => (string) Str::uuid(), 'lumo_generation' => $transaction['lumo_generation'], 'access_version' => $transaction['access_version'], 'tenant_id' => $tenant, 'tenant_name' => $transaction['tenant_name'], 'verified_app_id' => $transaction['app_id'], 'available_tools' => $verified['available_tools'], 'verified_at' => now()->toIso8601String(), 'account_binding' => 'individual_oauth_grant', 'connected_at' => now()->toIso8601String()], 'expires_at' => $tokens['expires_at']]);
                Cache::forget($this->attemptKey($user->id, $provider));
                Audit::record($user->id, 'connection.mcp.connected', 'Consultas BI autorizadas por OAuth individual e ambiente conferido.', null, ['tenant_id' => $tenant, 'app_id' => $transaction['app_id']]);

                return $connection;
            } catch (Throwable $e) {
                $this->revoke($tokens['credentials'], $transaction['client_id']);
                throw $e;
            }
        });
    }

    private function assertTransaction(User $user, array $transaction, ?string $issuer): void
    {
        $tenant = (string) ($transaction['tenant_id'] ?? '');
        if (($transaction['user_id'] ?? null) !== $user->id || ($transaction['resource'] ?? '') !== $this->http->resource() || ($transaction['redirect_uri'] ?? '') !== $this->redirectUri() || ($issuer !== null && $issuer !== $this->http->origin()) || ! is_string($transaction['attempt'] ?? null) || Cache::get($this->attemptKey($user->id, $this->provider($tenant))) !== $transaction['attempt']) {
            throw new RuntimeException('A conta ou a conexão BI mudou durante a autorização. Tente conectar novamente.');
        }
        $this->assertLumoGeneration($user, $tenant, (string) ($transaction['lumo_generation'] ?? ''), (int) ($transaction['access_version'] ?? -1));
    }

    private function tokens(array $response, string $fallbackScope): array
    {
        $data = $this->http->json($response);
        $scope = $data['scope'] ?? $fallbackScope;
        $scopes = is_string($scope) ? preg_split('/\s+/', trim($scope)) : [];
        if ($response['status'] !== 200 || strtolower((string) ($data['token_type'] ?? '')) !== 'bearer' || ! $this->validToken($data['access_token'] ?? null) || ! in_array('mcp:read', $scopes, true) || array_diff($scopes, ['mcp:read', 'offline_access']) !== [] || (isset($data['expires_in']) && (! is_numeric($data['expires_in']) || (int) $data['expires_in'] < 1 || (int) $data['expires_in'] > 31_536_000))) {
            throw new RuntimeException('O BI não concedeu a autorização de consultas somente de leitura esperada.');
        }
        $credentials = ['access_token' => $data['access_token']];
        if (isset($data['refresh_token'])) {
            if (! $this->validToken($data['refresh_token'])) {
                throw new RuntimeException('O BI retornou uma renovação de autorização inválida.');
            }
            $credentials['refresh_token'] = $data['refresh_token'];
        }

        return ['credentials' => $credentials, 'scopes' => array_values(array_unique($scopes)), 'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null];
    }

    private function validToken(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 32000 && ! preg_match('/[\x00-\x20\x7f]/', $value);
    }

    public function assertLumoGeneration(User $user, string $tenant, string $generation, int $accessVersion): void
    {
        $current = $user->fresh();
        if ((! $current?->canStartWork() || $current->role === 'viewer') || $current->access_version !== $accessVersion) {
            throw new RuntimeException('Seu acesso BI mudou. Autorize novamente as consultas em Conexões.');
        }
        [$lumo] = app(BiCatalog::class)->access($current, $tenant);
        if ($generation === '' || ($lumo->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Seu acesso BI mudou. Autorize novamente as consultas em Conexões.');
        }
    }

    public function assertBound(Connection $connection): void
    {
        $tenant = (string) ($connection->metadata['tenant_id'] ?? '');
        if (! $this->configured() || $connection->status !== 'connected' || $connection->provider !== $this->provider($tenant) || ($connection->metadata['resource'] ?? '') !== $this->http->resource() || empty($connection->metadata['lumo_generation'])) {
            throw new RuntimeException('Autorize as consultas deste ambiente em Conexões para usar o MCP.');
        }
        $user = User::findOrFail($connection->user_id);
        $this->assertLumoGeneration($user, $tenant, $connection->metadata['lumo_generation'], (int) ($connection->metadata['access_version'] ?? -1));
    }

    public function accessToken(Connection $connection, string $expectedGeneration): string
    {
        return $this->locked($this->lockKey($connection->user_id, $connection->provider), function () use ($connection, $expectedGeneration): string {
            $connection->refresh();
            $this->assertBound($connection);
            if (($connection->metadata['session_generation'] ?? null) !== $expectedGeneration) {
                throw new RuntimeException('A autorização MCP mudou. Inicie a consulta novamente.');
            }
            $credentials = $connection->credentials ?? [];
            if ($connection->expires_at?->isBefore(now()->addSeconds(45))) {
                if (empty($credentials['refresh_token'])) {
                    $connection->update(['status' => 'reauthentication_required']);
                    throw new RuntimeException('A autorização de consultas expirou. Conecte novamente.');
                }
                $response = $this->http->send('POST', $this->discovery()['token_endpoint'], ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'], 'client_id' => $connection->metadata['client_id'], 'resource' => $this->http->resource()], true);
                if ($response['status'] !== 200) {
                    if (in_array($response['status'], [400, 401, 403], true)) {
                        $connection->update(['status' => 'reauthentication_required']);
                    }
                    throw new RuntimeException('O BI não renovou a autorização de consultas. Aguarde ou conecte novamente.');
                }
                $tokens = $this->tokens($response, implode(' ', $connection->scopes));
                $credentials = [...$credentials, ...$tokens['credentials']];
                DB::transaction(function () use ($connection, $expectedGeneration, $credentials, $tokens): void {
                    $current = Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                    $this->assertBound($current);
                    if (($current->metadata['session_generation'] ?? null) !== $expectedGeneration) {
                        throw new RuntimeException('A autorização MCP mudou durante a renovação. Tente novamente.');
                    }
                    $current->update(['credentials' => $credentials, 'scopes' => $tokens['scopes'], 'expires_at' => $tokens['expires_at']]);
                });
                $connection->refresh();
            }
            if (empty($credentials['access_token'])) {
                throw new RuntimeException('Conecte as consultas BI novamente.');
            }

            return $credentials['access_token'];
        });
    }

    public function disconnect(User $user, string $tenant): ?bool
    {
        $provider = $this->provider($tenant);

        return $this->locked($this->lockKey($user->id, $provider), function () use ($user, $tenant, $provider): ?bool {
            Cache::forget($this->attemptKey($user->id, $provider));
            $connection = Connection::where('user_id', $user->id)->where('provider', $provider)->first();
            if (! $connection || ($connection->status === 'disconnected' && empty($connection->credentials))) {
                return null;
            }
            $credentials = $connection->credentials ?? [];
            $clientId = $connection->metadata['client_id'] ?? '';
            try {
                $sameResource = ($connection->metadata['resource'] ?? '') === $this->http->resource();
            } catch (RuntimeException) {
                $sameResource = false;
            }
            $connection->update(['status' => 'disconnected', 'credentials' => null, 'metadata' => [], 'scopes' => [], 'expires_at' => null]);
            $confirmed = $sameResource && $this->revoke($credentials, $clientId);
            Audit::record($user->id, 'connection.mcp.disconnected', 'Consultas BI desconectadas.', null, ['tenant_id' => $tenant, 'remote_revocation_acknowledged' => $confirmed]);

            return $confirmed;
        });
    }

    private function revoke(array $credentials, string $clientId): bool
    {
        try {
            $endpoint = $this->discovery()['revocation_endpoint'] ?? null;
        } catch (Throwable) {
            return false;
        }
        if (! $endpoint || $clientId === '') {
            return false;
        }
        $confirmed = true;
        foreach (['refresh_token', 'access_token'] as $type) {
            if (empty($credentials[$type])) {
                continue;
            }
            try {
                $result = $this->http->send('POST', $endpoint, ['token' => $credentials[$type], 'token_type_hint' => $type, 'client_id' => $clientId], true);
                $confirmed = in_array($result['status'], [200, 204], true) && $confirmed;
            } catch (Throwable) {
                $confirmed = false;
            }
        }

        return $confirmed;
    }

    private function locked(string $key, Closure $operation, int $seconds = 240): mixed
    {
        try {
            return Cache::lock($key, $seconds)->block(3, $operation);
        } catch (LockTimeoutException) {
            throw new BiBusy;
        }
    }

    private function lockKey(int $userId, string $provider): string
    {
        return 'bi-mcp:grant:'.$userId.':'.$provider;
    }

    private function attemptKey(int $userId, string $provider): string
    {
        return $this->lockKey($userId, $provider).':attempt';
    }
}
