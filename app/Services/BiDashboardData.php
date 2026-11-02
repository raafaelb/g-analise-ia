<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class BiDashboardData
{
    public function __construct(private BiMcpClient $mcp) {}

    public function availability(User $user, string $tenant): array
    {
        try {
            $this->authorization($user, $tenant);

            return ['creation_available' => true, 'creation_unavailable_reason' => null];
        } catch (RuntimeException $e) {
            return ['creation_available' => false, 'creation_unavailable_reason' => $e->getMessage()];
        }
    }

    public function assertAuthorization(User $user, string $tenant, ?array $expected): void
    {
        $current = $this->authorization($user, $tenant);
        if ($expected !== null) {
            ksort($expected);
        }
        ksort($current);
        if ($expected !== $current) {
            throw new RuntimeException('A autorização de consulta mudou depois da revisão. Reabra o assistente para conferir os dados com o acesso atual.');
        }
    }

    public function verifyAuthorization(User $user, string $tenant, int $appId, ?array $expected, array $selection): void
    {
        $this->assertAuthorization($user, $tenant, $expected);
        $schema = $this->mcp->invoke($user, $tenant, $appId, 'get_app', [])['data'];
        $this->references($schema, $selection);
        $this->assertAuthorization($user, $tenant, $expected);
    }

    public function preview(User $user, string $tenant, int $appId, array $data, array $selection): array
    {
        $authorization = $this->authorization($user, $tenant);
        $schema = $this->mcp->invoke($user, $tenant, $appId, 'get_app', [])['data'];
        [$metric, $group] = $this->references($schema, $selection);
        $metricField = ['ref' => $metric, 'agg' => strtolower($data['aggregation'])];
        $groupField = ['ref' => $group];
        if ($selection['group']['date']) {
            $groupField['bucket'] = ['DATE' => 'day', 'YEARMONTH' => 'month', 'YEAR' => 'year'][$data['grain']];
        }
        $this->assertAuthorization($user, $tenant, $authorization);
        $total = $this->mcp->invoke($user, $tenant, $appId, 'query', ['fields' => [$metricField], 'limit' => 1])['data'];
        $this->assertAuthorization($user, $tenant, $authorization);
        $groups = $this->mcp->invoke($user, $tenant, $appId, 'query', ['fields' => [$groupField, $metricField], 'orderBy' => [[...($selection['group']['date'] ? $groupField : $metricField), 'dir' => $selection['group']['date'] ? 'asc' : 'desc']], 'limit' => 26])['data'];
        $this->assertAuthorization($user, $tenant, $authorization);
        $valueKey = $data['metric_id'].'_'.$data['aggregation'];
        $groupKey = $data['group_id'].'_'.$data['grain'];
        $totalRows = $this->rows($total, [$valueKey]);
        $groupRows = $this->rows($groups, [$groupKey, $valueKey]);
        if (count($totalRows) !== 1 || $total['totalRows'] !== 1 || $total['truncated'] !== false) {
            throw new RuntimeException('O BI não confirmou um único indicador global completo. Confira os campos antes de criar o painel.');
        }
        $dataAsOf = $total['dataAsOf'] ?? null;
        if ($dataAsOf !== ($groups['dataAsOf'] ?? null) || ($dataAsOf !== null && (! is_string($dataAsOf) || strlen($dataAsOf) > 100 || preg_match('/[\x00-\x1F\x7F]/', $dataAsOf)))) {
            throw new RuntimeException('A atualização dos dados mudou entre as consultas. Confira a prévia novamente antes de criar o painel.');
        }

        return ['authorization' => $authorization, 'total' => ['rows' => $totalRows], 'groups' => ['rows' => $groupRows, 'truncated' => $groups['truncated'] || $groups['totalRows'] > count($groupRows)], 'data_as_of' => $dataAsOf];
    }

    private function authorization(User $user, string $tenant): array
    {
        $connection = $this->mcp->connection($user, $tenant);
        $metadata = $connection->metadata;
        if (! in_array('mcp:read', $connection->scopes ?? [], true) || array_diff(['get_app', 'query'], $metadata['available_tools'] ?? []) !== [] || ! is_string($metadata['session_generation'] ?? null) || $metadata['session_generation'] === '') {
            throw new RuntimeException('Confira a autorização de consultas deste ambiente em Conexões antes de criar o painel.');
        }

        return ['connection_id' => $connection->id, 'generation' => $metadata['session_generation'], 'tenant_id' => $tenant, 'user_id' => $user->id, 'access_version' => $user->access_version, 'lumo_generation' => $metadata['lumo_generation']];
    }

    private function references(array $schema, array $selection): array
    {
        $tables = $schema['tables'] ?? null;
        if (! is_array($tables) || ! array_is_list($tables)) {
            throw new RuntimeException('O MCP não confirmou os campos desta aplicação. Confira a conexão antes de criar o painel.');
        }
        $references = [];
        foreach ($tables as $table) {
            if (! is_array($table) || ! is_string($table['name'] ?? null) || ! is_array($table['columns'] ?? null) || ! array_is_list($table['columns'])) {
                throw new RuntimeException('O MCP não confirmou a estrutura das tabelas para esta consulta.');
            }
            foreach ($table['columns'] as $column) {
                if (! is_array($column) || ! is_string($column['ref'] ?? null) || ! is_string($column['label'] ?? null) || ! is_string($column['type'] ?? null) || $column['ref'] !== $table['name'].'.'.$column['label']) {
                    throw new RuntimeException('Um campo MCP não tem referência inequívoca. Confira a modelagem antes de criar o painel.');
                }
                $references[$column['ref']][] = $column['type'];
            }
        }
        $selected = [];
        foreach (['metric', 'group'] as $role) {
            $field = $selection[$role];
            $ref = $field['query_ref'] ?? null;
            if (! is_string($ref) || mb_strlen($ref) > 300 || count($references[$ref] ?? []) !== 1 || $references[$ref][0] !== $field['type']) {
                throw new RuntimeException('Os campos escolhidos não correspondem de forma única ao MCP. Confira os nomes e os tipos antes de criar o painel.');
            }
            $selected[] = $ref;
        }

        return $selected;
    }

    private function rows(array $data, array $expected): array
    {
        $columns = $data['columns'] ?? null;
        if (! is_array($columns) || ! array_is_list($columns) || count($columns) !== count($expected)) {
            throw new RuntimeException('A consulta MCP não confirmou as colunas escolhidas.');
        }
        $keys = [];
        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['key'] ?? null) || ! in_array($column['key'], $expected, true) || in_array($column['key'], $keys, true)) {
                throw new RuntimeException('A consulta MCP retornou uma coluna diferente da escolhida. O resultado foi descartado.');
            }
            $keys[] = $column['key'];
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($keys)) {
                throw new RuntimeException('Uma linha MCP não corresponde às colunas conferidas.');
            }
            $rows[] = array_combine($keys, $row);
        }

        return $rows;
    }
}
