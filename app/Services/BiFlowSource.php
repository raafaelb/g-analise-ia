<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BiFlowSource
{
    public function __construct(private BiCatalog $catalog, private BiCredentials $credentials, private BiCredentialTypes $types, private BiSourceSchema $schema, private TenantPolicy $policy, private LumoClient $lumo) {}

    public function choices(User $user, string $tenant): array
    {
        [$connection, $environment] = $this->access($user, $tenant);
        $context = $this->catalog->context($connection, $environment);
        $this->lumo->initialize($connection, $context, true);
        $generation = $connection->metadata['session_generation'] ?? null;
        $rows = $this->catalog->rows($connection, $context, 'credential');
        $latest = User::findOrFail($user->id);
        [$after] = $this->access($latest, $tenant);
        abort_unless($after->id === $connection->id && ($after->metadata['session_generation'] ?? null) === $generation && $latest->access_version === $connection->operationAccessVersion, 403, 'Seu acesso mudou durante a consulta.');

        return ['credentials' => array_map(fn (array $row): array => ['ref' => 'credential:'.$row['id'], 'name' => (string) ($row['nome'] ?? $row['name'] ?? 'Conexão #'.$row['id'])], $rows), 'tenant' => $environment, 'supported_drivers' => ['postgres', 'mysql', 'sqlserver']];
    }

    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['credential', 'schema', 'table', 'columns', 'row_limit']) !== []) {
            throw ValidationException::withMessages(['source' => 'A origem aceita somente conexão, agrupamento, tabela, campos e limite opcional.']);
        }
        $data = Validator::make($input, ['credential' => ['required', 'string', 'regex:/^credential:[1-9][0-9]{0,15}$/D'], 'schema' => ['required', 'string', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/'], 'table' => ['required', 'string', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/'], 'columns' => ['required', 'array', 'list', 'min:1', 'max:200'], 'columns.*' => ['required', 'string', 'max:128', 'distinct:strict', 'not_regex:/[\x00-\x1F\x7F]/'], 'row_limit' => ['nullable', 'integer', 'min:1', 'max:10000']])->validate();
        sort($data['columns'], SORT_STRING);

        return ['credential' => $data['credential'], 'schema' => $data['schema'], 'table' => $data['table'], 'columns' => $data['columns'], 'row_limit' => isset($data['row_limit']) ? (int) $data['row_limit'] : null];
    }

    public function fields(User $user, string $tenant, array $input): array
    {
        if (array_diff(array_keys($input), ['credential', 'schema', 'table', 'offset']) !== []) {
            throw ValidationException::withMessages(['source' => 'Consulte somente a conexão, a tabela e a posição de seus campos.']);
        }
        $data = $this->normalize([...array_diff_key($input, ['offset' => true]), 'columns' => ['__metadata_only__']]);
        $offset = Validator::make($input, ['offset' => ['sometimes', 'integer', 'min:0', 'max:1000000']])->validate()['offset'] ?? 0;
        $this->access($user, $tenant);

        return $this->credentials->withSource($user, $tenant, substr($data['credential'], 11), function (Connection $connection, Project $context, array $body) use ($data, $offset): array {
            $driver = $this->driver($body);
            $report = $this->schema->inspect($connection, $context, $data['credential'], $driver, $data['schema'], null, (int) $offset, $data['table']);
            $table = $this->table($report, $data);
            Audit::record($context->user_id, 'bi.flow_source.structure_read', 'Campos da tabela consultados para preparar um ETL, sem registros de negócio.', null, ['tenant_id' => $context->tenant_id, 'resource' => $data['credential'], 'column_count' => count($table['columns'])]);

            return ['credential' => $data['credential'], 'driver' => $driver, 'table' => $table, 'offset' => $report['offset'], 'page_size' => $report['page_size'], 'next_offset' => $report['next_offset'], 'truncated' => $report['truncated'], 'pagination_limit_reached' => $report['pagination_limit_reached']];
        });
    }

    /** The credential reference key remains inside this server-side callback. */
    public function withSelection(User $user, string $tenant, array $input, callable $operation): array
    {
        $data = $this->normalize($input);
        $this->access($user, $tenant);

        return $this->credentials->withSource($user, $tenant, substr($data['credential'], 11), function (Connection $connection, Project $context, array $body) use ($data, $operation): array {
            $driver = $this->driver($body);
            $report = $this->schema->inspect($connection, $context, $data['credential'], $driver, $data['schema'], null, 0, $data['table'], $data['columns']);
            $table = $this->table($report, $data);
            $names = array_column($table['columns'], 'name');
            if (count($names) !== count($data['columns']) || array_diff($names, $data['columns']) !== [] || array_diff($data['columns'], $names) !== []) {
                throw new RuntimeException('Os campos selecionados não foram confirmados na estrutura atual. Confira a origem antes de preparar outra revisão.');
            }
            if (count(array_unique(array_map(mb_strtoupper(...), $names))) !== count($names)) {
                throw new RuntimeException('Dois campos selecionados teriam o mesmo nome no destino. Escolha campos distintos ou prepare os nomes pelo projeto antes de criar a tabela.');
            }
            $key = $body['key'] ?? null;
            $agent = $body['agentId'] ?? $body['agent_id'] ?? $body['tokenId'] ?? null;
            if (! is_string($key) || $key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $key) || (! is_int($agent) && ! is_string($agent)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $agent)) {
                throw new RuntimeException('A conexão não retornou referência e agente confirmados para o ETL.');
            }
            $agentRow = collect($this->catalog->rows($connection, $context, 'agent'))->first(fn (array $row): bool => (string) $row['id'] === (string) $agent);
            if (! $agentRow) {
                throw new RuntimeException('O agente desta conexão não está disponível para sua conta neste ambiente.');
            }
            $public = ['credential' => $data['credential'], 'name' => (string) ($body['nome'] ?? 'Conexão selecionada'), 'driver' => $driver, 'schema' => $table['schema'], 'table' => $table['name'], 'columns' => $table['columns'], 'visible_column_count' => $table['visible_column_count'], 'row_limit' => $data['row_limit'], 'agent' => ['id' => (int) $agent, 'name' => (string) ($agentRow['nome'] ?? $agentRow['name'] ?? 'Agente #'.$agent)]];
            $privateVersion = $this->digest(['key' => $key, 'agent' => (int) $agent, 'type' => $body['type'] ?? null, 'config' => $body['config'] ?? null, 'password' => $body['password'] ?? null]);

            return $operation($connection, ['public' => $public, 'credential_key' => $key, 'source_sha256' => $privateVersion, 'sql' => $this->sql($driver, $table['schema'], $table['name'], $names, $data['row_limit'])]);
        });
    }

    public function digest(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return hash_hmac('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function table(array $report, array $data): array
    {
        if (($report['verified'] ?? false) !== true) {
            throw new RuntimeException('A fonte não confirmou os campos desta tabela. Confira o acesso e tente novamente.');
        }
        $matches = array_values(array_filter($report['tables'], fn (array $table): bool => $table['schema'] === $data['schema'] && $table['name'] === $data['table']));
        abort_unless(count($matches) === 1, 404, 'Esta tabela não está disponível com campos visíveis na conexão selecionada.');

        return $matches[0];
    }

    private function driver(array $body): string
    {
        $driver = $this->types->driver((string) ($body['type'] ?? ''));
        if (! in_array($driver, ['postgres', 'mysql', 'sqlserver'], true)) {
            throw new RuntimeException('Este assistente consulta PostgreSQL, MySQL e SQL Server. Outros conectores continuam disponíveis para autoria pelo projeto.');
        }

        return $driver;
    }

    private function access(User $user, string $tenant): array
    {
        $current = User::findOrFail($user->id);
        abort_unless($current->canStartWork() && $current->role !== 'viewer', 403, 'Seu perfil não permite criar ETLs.');
        [$connection, $environment] = $this->catalog->access($current, $tenant);
        $this->policy->assertCommandAllowed($tenant, ['new', 'flow'], $connection);

        return [$connection, $environment];
    }

    private function sql(string $driver, string $schema, string $table, array $columns, ?int $limit): string
    {
        $quote = fn (string $name): string => match ($driver) {
            'postgres' => '"'.str_replace('"', '""', $name).'"',
            'mysql' => '`'.str_replace('`', '``', $name).'`',
            'sqlserver' => '['.str_replace(']', ']]', $name).']',
        };
        $query = 'SELECT '.($driver === 'sqlserver' && $limit !== null ? 'TOP ('.$limit.') ' : '').implode(', ', array_map($quote, $columns)).' FROM '.$quote($schema).'.'.$quote($table);

        return $query.($driver !== 'sqlserver' && $limit !== null ? ' LIMIT '.$limit : '');
    }
}
