<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BiMcpClient
{
    public const TOOLS = ['search_catalog', 'get_app', 'search_values', 'query'];

    private const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(private BiMcpIdentity $identity, private BiMcpHttp $http, private BiCatalog $catalog) {}

    public function applications(User $user, string $tenant): array
    {
        [$connection, $environment] = $this->catalog->access($user, $tenant);
        $project = $this->catalog->context($connection, $environment);
        app(LumoClient::class)->initialize($connection, $project, true);

        return collect($this->catalog->rows($connection, $project, 'app'))->map(fn (array $app): array => ['id' => (int) $app['id'], 'name' => (string) ($app['nome'] ?? $app['name'] ?? 'Aplicação '.$app['id'])])->values()->all();
    }

    public function assertApp(User $user, string $tenant, int $appId): void
    {
        if ($appId < 1) {
            throw ValidationException::withMessages(['app_id' => 'Escolha uma aplicação para conferir o ambiente.']);
        }
        $detail = $this->catalog->detail($user, $tenant, 'app', (string) $appId, false);
        if ((string) ($detail['resource']['id'] ?? '') !== (string) $appId || (string) ($detail['tenant']['id'] ?? '') !== $tenant) {
            throw new RuntimeException('A aplicação não corresponde ao ambiente selecionado.');
        }
    }

    public function connection(User $user, string $tenant): Connection
    {
        $connection = Connection::where('user_id', $user->id)->where('provider', $this->identity->provider($tenant))->where('status', 'connected')->first();
        if (! $connection) {
            throw new RuntimeException('Autorize as consultas deste ambiente na página Conexões antes de usar o MCP.');
        }
        $this->identity->assertBound($connection);

        return $connection;
    }

    public function verifyGrant(string $token, int $appId, ?Connection $connection = null, ?string $generation = null): array
    {
        $session = $this->initialize($token, $connection, $generation);
        try {
            $available = $this->availableTools($session, $connection, $generation);
            if (array_diff(['get_app', 'query'], $available) !== []) {
                throw new RuntimeException('O MCP não disponibilizou as ferramentas de consulta esperadas.');
            }
            $data = $this->toolData($this->request($session, 'tools/call', ['name' => 'get_app', 'arguments' => ['appId' => $appId]], 10, $connection, $generation));
            if ((string) ($data['app']['id'] ?? '') !== (string) $appId) {
                throw new RuntimeException('O MCP não confirmou a aplicação deste ambiente. Confira a conta e o ambiente escolhidos no BI.');
            }

            return ['available_tools' => $available];
        } finally {
            $this->close($session);
        }
    }

    public function check(User $user, string $tenant): array
    {
        $connection = $this->connection($user, $tenant);
        $generation = $connection->metadata['session_generation'];
        $verified = $this->verifyGrant($this->identity->accessToken($connection, $generation), (int) $connection->metadata['verified_app_id'], $connection, $generation);
        $this->assertCurrent($connection, $generation);
        $verifiedAt = now()->toIso8601String();
        $updated = Connection::whereKey($connection->id)->where('status', 'connected')->where('metadata->session_generation', $generation)->update(['metadata' => json_encode([...$connection->metadata, ...$verified, 'verified_at' => $verifiedAt], JSON_THROW_ON_ERROR)]);
        if ($updated !== 1) {
            throw new RuntimeException('A autorização MCP mudou durante a conferência. Tente novamente.');
        }
        Audit::record($user->id, 'bi.mcp.verified', 'Autorização, ambiente e ferramentas MCP conferidos.', null, ['tenant_id' => $tenant]);

        return ['status' => 'available', 'tools' => $verified['available_tools'], 'read_only' => true, 'verified_at' => $verifiedAt, 'message' => 'Acesso às consultas deste ambiente confirmado.'];
    }

    public function invoke(User $user, string $tenant, int $appId, string $tool, array $arguments, ?string $projectId = null): array
    {
        $arguments = $this->arguments($tool, $arguments);
        $accessVersion = $user->access_version;
        [$lumo] = $this->catalog->access($user, $tenant);
        $lumoGeneration = $lumo->metadata['session_generation'] ?? '';
        $connection = $this->connection($user, $tenant);
        $generation = $connection->metadata['session_generation'];
        $this->assertApp($user, $tenant, $appId);
        $this->assertProject($user, $tenant, $appId, $projectId);
        $this->assertCurrent($connection, $generation);
        if (! in_array($tool, $connection->metadata['available_tools'] ?? [], true)) {
            throw new RuntimeException('Esta ferramenta não está disponível na sua conexão MCP. Confira o acesso em Conexões.');
        }
        $session = $this->initialize($this->identity->accessToken($connection, $generation), $connection, $generation);
        try {
            $data = $this->toolData($this->request($session, 'tools/call', ['name' => $tool, 'arguments' => ['appId' => $appId, ...$arguments]], 2, $connection, $generation));
        } finally {
            $this->close($session);
        }
        if ((isset($data['appId']) && (string) $data['appId'] !== (string) $appId) || ($tool === 'get_app' && (string) ($data['app']['id'] ?? '') !== (string) $appId)) {
            throw new RuntimeException('O MCP retornou dados de outra aplicação. O resultado foi descartado.');
        }
        if ($tool === 'query' && (! is_array($data['rows'] ?? null) || ! array_is_list($data['rows']) || count($data['rows']) > $arguments['limit'] || ! is_array($data['columns'] ?? null) || ! is_int($data['rowCount'] ?? null) || $data['rowCount'] !== count($data['rows']) || ! is_int($data['totalRows'] ?? null) || $data['totalRows'] < count($data['rows']) || ! is_bool($data['truncated'] ?? null))) {
            throw new RuntimeException('A consulta MCP retornou um resultado incompleto ou fora do limite. Reduza os campos ou o período.');
        }
        if (isset($data['permalink']) && ! $this->validPermalink($data['permalink'])) {
            unset($data['permalink']);
        }
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 300_000) {
            throw new RuntimeException('O resultado MCP é muito extenso. Consulte menos campos, linhas ou um período menor.');
        }
        $this->assertCurrent($connection, $generation);
        $this->identity->assertLumoGeneration($user, $tenant, $lumoGeneration, $accessVersion);
        $this->assertProject($user, $tenant, $appId, $projectId);
        Audit::record($user->id, 'bi.mcp.'.$tool, 'Consulta MCP concluída na aplicação selecionada.', $projectId, ['tenant_id' => $tenant, 'app_id' => $appId, 'row_count' => $tool === 'query' ? count($data['rows']) : null, 'truncated' => $tool === 'query' ? $data['truncated'] : null]);

        return ['tenant_id' => $tenant, 'app_id' => $appId, 'tool' => $tool, 'read_only' => true, 'data' => $data, 'queried_at' => now()->toIso8601String()];
    }

    private function assertProject(User $user, string $tenant, int $appId, ?string $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        $exists = Project::whereKey($projectId)->where('user_id', $user->id)->where('tenant_id', $tenant)->where('source_ref', 'app:'.$appId)->where('status', '!=', 'archived')->exists();
        if (! $exists) {
            throw new RuntimeException('O projeto mudou ou não está disponível para esta consulta.');
        }
    }

    private function initialize(string $token, ?Connection $connection = null, ?string $generation = null): array
    {
        $response = $this->http->send('POST', $this->http->resource(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::VERSIONS[0], 'capabilities' => (object) [], 'clientInfo' => ['name' => 'G-Analise IA', 'version' => '0.1.0']]], token: $token, rpcId: 1);
        $this->assertStatus($response, [200], $connection, $generation);
        $result = $this->http->rpc($response, 1);
        if (! in_array($result['protocolVersion'] ?? '', self::VERSIONS, true) || ! is_array($result['capabilities']['tools'] ?? null)) {
            throw new RuntimeException('O servidor não confirmou uma versão MCP compatível com consultas.');
        }
        $headers = ['MCP-Protocol-Version' => $result['protocolVersion']];
        $id = $response['headers']['mcp-session-id'];
        if ($id !== '') {
            if (strlen($id) > 512 || preg_match('/[^\x21-\x7E]/', $id)) {
                throw new RuntimeException('O servidor retornou uma sessão MCP inválida.');
            }
            $headers['Mcp-Session-Id'] = $id;
        }
        $session = ['token' => $token, 'headers' => $headers];
        try {
            $notification = $this->http->send('POST', $this->http->resource(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => (object) []], token: $token, headers: $headers);
            $this->assertStatus($notification, [200, 202, 204], $connection, $generation);
        } catch (Throwable $e) {
            $this->close($session);
            throw $e;
        }

        return $session;
    }

    private function availableTools(array $session, ?Connection $connection = null, ?string $generation = null): array
    {
        $available = [];
        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            $result = $this->request($session, 'tools/list', $cursor === null ? (object) [] : ['cursor' => $cursor], 2 + $page, $connection, $generation);
            if (! is_array($result['tools'] ?? null) || ! array_is_list($result['tools'])) {
                throw new RuntimeException('O MCP não informou as ferramentas disponíveis.');
            }
            foreach ($result['tools'] as $tool) {
                if (is_array($tool) && in_array($tool['name'] ?? '', self::TOOLS, true)) {
                    $available[] = $tool['name'];
                }
            }
            $next = $result['nextCursor'] ?? null;
            if ($next === null || $next === '') {
                return array_values(array_intersect(self::TOOLS, array_unique($available)));
            }
            if (! is_string($next) || strlen($next) > 2000 || in_array($next, $seen, true)) {
                break;
            }
            $seen[] = $next;
            $cursor = $next;
        }
        throw new RuntimeException('A lista de ferramentas MCP não pôde ser concluída.');
    }

    private function request(array $session, string $method, array|object $params, int $id, ?Connection $connection = null, ?string $generation = null): array
    {
        $response = $this->http->send('POST', $this->http->resource(), ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], token: $session['token'], headers: $session['headers'], rpcId: $id);
        $this->assertStatus($response, [200], $connection, $generation);

        return $this->http->rpc($response, $id);
    }

    private function close(array $session): void
    {
        if (! isset($session['headers']['Mcp-Session-Id'])) {
            return;
        }
        try {
            $this->http->send('DELETE', $this->http->resource(), token: $session['token'], headers: $session['headers']);
        } catch (Throwable) {
            // A sessão remota pode já ter sido encerrada ou expirada.
        }
    }

    private function assertStatus(array $response, array $accepted, ?Connection $connection = null, ?string $generation = null): void
    {
        if ($response['status'] === 401) {
            if ($connection && $generation) {
                Connection::whereKey($connection->id)->where('status', 'connected')->where('metadata->session_generation', $generation)->update(['status' => 'reauthentication_required']);
            }
            throw new RuntimeException('O BI recusou a autorização MCP. Conecte as consultas novamente.');
        }
        if ($response['status'] === 403) {
            throw new RuntimeException('Sua autorização MCP não permite esta consulta. Confira o ambiente e a função Conectar via MCP no BI.');
        }
        if ($response['status'] === 429) {
            throw new RuntimeException('O BI limitou as consultas MCP. Aguarde alguns instantes antes de tentar novamente.');
        }
        if (! in_array($response['status'], $accepted, true)) {
            throw new RuntimeException('O BI não concluiu a comunicação MCP. Tente novamente.');
        }
    }

    private function assertCurrent(Connection $connection, string $generation): void
    {
        $connection->refresh();
        $this->identity->assertBound($connection);
        if (($connection->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A autorização MCP mudou durante a consulta. O resultado foi descartado.');
        }
    }

    private function toolData(array $result): array
    {
        if (($result['isError'] ?? false) !== false) {
            throw new RuntimeException('O BI não autorizou ou não concluiu esta consulta. Confira a conta, o ambiente escolhido na autorização MCP e suas permissões.');
        }
        if (is_array($result['structuredContent'] ?? null)) {
            return $result['structuredContent'];
        }
        foreach ($result['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parsed = json_decode($block['text'], true, 128);
                if (is_array($parsed) && ! array_is_list($parsed)) {
                    return $parsed;
                }
            }
        }
        throw new RuntimeException('O MCP não retornou dados estruturados para esta consulta.');
    }

    private function validPermalink(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 16000 || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== parse_url($this->http->resource(), PHP_URL_HOST) || (($parts['port'] ?? 443) !== 443) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/token|secret|password|authorization|credential/i', $key)) {
                return false;
            }
        }

        return true;
    }

    public function arguments(string $tool, array $arguments): array
    {
        if (! in_array($tool, self::TOOLS, true) || strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 32000) {
            throw ValidationException::withMessages(['tool' => 'Esta ferramenta ou consulta MCP não é permitida.']);
        }
        $allowed = match ($tool) {
            'search_catalog' => ['terms'], 'get_app' => [], 'search_values' => ['ref', 'contains', 'mode'], 'query' => ['fields', 'filters', 'orderBy', 'limit'],
        };
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Não informe appId, tenant ou SQL em arguments; a aplicação vem do projeto. Para '.$tool.' os campos aceitos são: '.($allowed === [] ? 'nenhum; use {}' : implode(', ', $allowed)).'.']);
        }
        foreach (['terms', 'ref', 'contains', 'mode', 'filters', 'orderBy', 'limit'] as $optional) {
            if (array_key_exists($optional, $arguments) && $arguments[$optional] === null) {
                unset($arguments[$optional]);
            }
        }
        foreach (['fields', 'filters', 'orderBy'] as $collection) {
            if (is_array($arguments[$collection] ?? null)) {
                foreach ($arguments[$collection] as &$field) {
                    if (is_array($field)) {
                        foreach (['agg', 'bucket'] as $optional) {
                            if (array_key_exists($optional, $field) && $field[$optional] === null) {
                                unset($field[$optional]);
                            }
                        }
                    }
                }
                unset($field);
            }
        }
        $aggregations = ['sum', 'avg', 'min', 'max', 'count', 'count_distinct'];
        $buckets = ['day', 'month', 'year', 'month_of_year', 'hour_of_day', 'day_of_week'];
        $rules = match ($tool) {
            'get_app' => [],
            'search_catalog' => ['terms' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_string($value) && ! is_array($value)) || (is_array($value) && (! array_is_list($value) || count($value) > 8))) {
                    $fail('Informe até oito termos para pesquisar.');

                    return;
                }
                foreach ((array) $value as $term) {
                    if (! is_string($term) || mb_strlen($term) < 1 || mb_strlen($term) > 120) {
                        $fail('Cada termo de pesquisa deve ter entre 1 e 120 caracteres.');
                    }
                }
            }]],
            'search_values' => ['ref' => ['sometimes', 'string', 'min:1', 'max:300'], 'contains' => ['sometimes', 'string', 'min:1', 'max:300'], 'mode' => ['sometimes', Rule::in(['distinct', 'sample'])]],
            'query' => [
                'fields' => ['required', 'array', 'min:1', 'max:20'], 'fields.*' => ['required', 'array:ref,agg,bucket'], 'fields.*.ref' => ['required', 'string', 'min:1', 'max:300'], 'fields.*.agg' => ['sometimes', Rule::in($aggregations)], 'fields.*.bucket' => ['sometimes', Rule::in($buckets)],
                'filters' => ['sometimes', 'array', 'max:20'], 'filters.*' => ['required', 'array:ref,agg,bucket,op,value'], 'filters.*.ref' => ['required', 'string', 'min:1', 'max:300'], 'filters.*.agg' => ['sometimes', Rule::in($aggregations)], 'filters.*.bucket' => ['sometimes', Rule::in($buckets)], 'filters.*.value' => ['sometimes', 'nullable'], 'filters.*.op' => ['required', Rule::in(['=', '!=', '>', '<', '>=', '<=', 'between', 'not between', 'in', 'not in', 'like', 'not like', 'is null', 'is not null'])],
                'orderBy' => ['sometimes', 'array', 'max:5'], 'orderBy.*' => ['required', 'array:ref,agg,bucket,dir'], 'orderBy.*.ref' => ['required', 'string', 'min:1', 'max:300'], 'orderBy.*.agg' => ['sometimes', Rule::in($aggregations)], 'orderBy.*.bucket' => ['sometimes', Rule::in($buckets)], 'orderBy.*.dir' => ['required', Rule::in(['asc', 'desc'])], 'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            ],
        };
        $data = Validator::make($arguments, $rules)->validate();
        if ($tool === 'search_values' && empty($data['ref']) && empty($data['contains'])) {
            throw ValidationException::withMessages(['arguments' => 'Escolha uma coluna ou informe o valor que deseja localizar.']);
        }
        if ($tool === 'query') {
            foreach (['fields', 'filters', 'orderBy'] as $key) {
                if (isset($data[$key]) && ! array_is_list($data[$key])) {
                    throw ValidationException::withMessages(['arguments' => 'Os campos e filtros devem ser listas.']);
                }
                foreach ($data[$key] ?? [] as $field) {
                    if (isset($field['agg'], $field['bucket'])) {
                        throw ValidationException::withMessages(['arguments' => 'Use uma agregação ou um agrupamento de data por campo.']);
                    }
                }
            }
            foreach ($data['filters'] ?? [] as $filter) {
                $value = $filter['value'] ?? null;
                $list = in_array($filter['op'], ['between', 'not between', 'in', 'not in'], true);
                $null = in_array($filter['op'], ['is null', 'is not null'], true);
                if (($null && $value !== null) || (! $null && $value === null) || ($list && (! is_array($value) || ! array_is_list($value) || count($value) < 1 || count($value) > 100)) || (! $list && is_array($value)) || (in_array($filter['op'], ['between', 'not between'], true) && count((array) $value) !== 2)) {
                    throw ValidationException::withMessages(['arguments' => 'Confira os valores e os intervalos dos filtros.']);
                }
                foreach ((array) $value as $item) {
                    if ((! is_string($item) && ! is_int($item) && ! is_float($item)) || (is_string($item) && mb_strlen($item) > 500)) {
                        throw ValidationException::withMessages(['arguments' => 'O valor do filtro não é válido.']);
                    }
                }
            }
            foreach ($data['orderBy'] ?? [] as $order) {
                $candidates = collect($data['fields'])->filter(fn (array $field): bool => $field['ref'] === $order['ref']);
                $qualified = isset($order['agg']) || isset($order['bucket']);
                if ($candidates->isEmpty() || (($candidates->count() > 1 || $qualified) && ! $candidates->contains(fn (array $field): bool => ($field['agg'] ?? null) === ($order['agg'] ?? null) && ($field['bucket'] ?? null) === ($order['bucket'] ?? null)))) {
                    throw ValidationException::withMessages(['arguments' => 'A ordenação deve usar um dos campos da consulta.']);
                }
            }
            $data['limit'] = (int) ($data['limit'] ?? 20);
        }

        return $data;
    }
}
