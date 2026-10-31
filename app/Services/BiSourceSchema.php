<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use RuntimeException;

class BiSourceSchema
{
    private const PAGE_SIZE = 500;

    public function __construct(private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function inspect(Connection $connection, Project $context, string $resource, string $driver, ?string $schema, ?string $search, int $offset): array
    {
        $query = $this->query($driver, $schema, $search, $offset);
        $data = $this->lumo->run($connection, ['credential', 'query', $resource, '--sql', $query, '--limit', (string) (self::PAGE_SIZE + 1), '--timeout', '45s'], $context, 60)['data'] ?? null;
        if (! is_array($data) || ! $this->successful($data) || ! is_array($data['rows'] ?? null) || ! array_is_list($data['rows']) || count($data['rows']) > self::PAGE_SIZE + 1) {
            throw new RuntimeException('A fonte não retornou uma consulta de estrutura confirmada.');
        }
        $emptyEvidence = null;
        if ($data['rows'] === []) {
            $columns = $data['columns'] ?? [];
            $names = is_array($columns) ? array_map(fn (mixed $column): string => strtolower(is_string($column) ? $column : (is_array($column) && is_string($column['name'] ?? null) ? $column['name'] : '')), $columns) : [];
            if (array_diff(['schema_name', 'table_name', 'column_name', 'data_type', 'is_nullable', 'ordinal_position', 'visible_column_count'], $names) === []) {
                $emptyEvidence = 'result_columns';
            } elseif ($this->confirmsEmpty($connection, $context, $resource, $query)) {
                $emptyEvidence = 'bounded_count';
            } else {
                return ['verified' => false, 'state' => 'unconfirmed', 'message' => 'A fonte retornou uma resposta vazia sem confirmação suficiente. Isso pode indicar falta de acesso ou falha na consulta; não comprova ausência de tabelas.', 'checked_at' => now()->toIso8601String()];
            }
        }
        $tables = [];
        $truncated = count($data['rows']) > self::PAGE_SIZE;
        foreach (array_slice($data['rows'], 0, self::PAGE_SIZE) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Formato de estrutura não reconhecido.');
            }
            $row = array_change_key_case($row, CASE_LOWER);
            foreach (['schema_name', 'table_name', 'column_name', 'data_type'] as $field) {
                if (! is_string($row[$field] ?? null) || $row[$field] === '' || strlen($row[$field]) > 1024) {
                    throw new RuntimeException('Formato de estrutura não reconhecido.');
                }
            }
            $position = $this->positiveInteger($row['ordinal_position'] ?? null);
            $count = $this->positiveInteger($row['visible_column_count'] ?? null);
            if ($position === null || $count === null || $position > 65535 || $count > 65535) {
                throw new RuntimeException('A ordem e a cobertura dos campos não foram confirmadas.');
            }
            $key = json_encode([$row['schema_name'], $row['table_name']], JSON_THROW_ON_ERROR);
            $tables[$key] ??= ['schema' => $row['schema_name'], 'name' => $row['table_name'], 'visible_column_count' => $count, 'columns' => []];
            if ($tables[$key]['visible_column_count'] !== $count || isset($tables[$key]['columns'][$position]) || in_array($row['column_name'], array_column($tables[$key]['columns'], 'name'), true)) {
                throw new RuntimeException('A fonte retornou campos ou contagens divergentes da mesma tabela.');
            }
            $nullable = match ($row['is_nullable'] ?? null) {
                'YES' => true, 'NO' => false, null => null,
                default => throw new RuntimeException('A possibilidade de campos vazios não foi reconhecida.'),
            };
            $tables[$key]['columns'][$position] = ['name' => $row['column_name'], 'type' => $row['data_type'], 'nullable' => $nullable, 'position' => $position];
        }
        foreach ($tables as &$table) {
            if (count($table['columns']) > $table['visible_column_count']) {
                throw new RuntimeException('A fonte retornou uma cobertura divergente da tabela.');
            }
            ksort($table['columns']);
            $table['columns_complete'] = count($table['columns']) === $table['visible_column_count'];
            $table['columns'] = array_values($table['columns']);
        }
        unset($table);

        return ['verified' => true, 'state' => 'available', 'resource' => $resource, 'driver' => $driver, 'method' => $driver === 'sqlserver' ? 'sqlserver_catalog' : $driver.'_information_schema', 'tables' => array_values($tables), 'page_table_count' => count($tables), 'page_size' => self::PAGE_SIZE, 'offset' => $offset, 'next_offset' => $truncated && $offset + self::PAGE_SIZE <= 1000000 ? $offset + self::PAGE_SIZE : null, 'truncated' => $truncated, 'pagination_limit_reached' => $truncated && $offset + self::PAGE_SIZE > 1000000, 'pagination_supported' => true, 'search_supported' => true, 'schema' => $schema, 'search' => $search, 'coverage' => 'visible_columns', 'empty_result_evidence' => $emptyEvidence, 'message' => count($tables).' tabela(s) com campos visíveis nesta página da consulta.', 'scope_warning' => 'A consulta mostra somente metadados visíveis para esta conexão. A busca encontra tabelas por nome ou pelo nome de uma coluna e mantém seus demais campos visíveis, respeitando as páginas. Não comprova acesso aos registros nem mostra relacionamentos ou regras de negócio. Cada página reflete o momento da consulta.', 'checked_at' => now()->toIso8601String()];
    }

    private function confirmsEmpty(Connection $connection, Project $context, string $resource, string $query): bool
    {
        $sql = 'SELECT COUNT(*) AS metadata_row_count FROM ('.$query.') AS structure_probe';
        $data = $this->lumo->run($connection, ['credential', 'query', $resource, '--sql', $sql, '--limit', '1', '--timeout', '45s'], $context, 60)['data'] ?? null;
        if (! is_array($data) || ! $this->successful($data) || ! is_array($data['rows'] ?? null) || ! array_is_list($data['rows']) || count($data['rows']) !== 1 || ! is_array($data['rows'][0])) {
            return false;
        }

        return in_array(array_change_key_case($data['rows'][0], CASE_LOWER)['metadata_row_count'] ?? null, [0, '0'], true);
    }

    private function successful(array $data): bool
    {
        foreach (['Success', 'success', 'ok'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== true) {
                return false;
            }
        }

        return true;
    }

    private function query(string $driver, ?string $schema, ?string $search, int $offset): string
    {
        if ($driver === 'sqlserver') {
            $base = "SELECT sn.name AS schema_name, o.name AS table_name, c.name AS column_name,\n CASE WHEN t.is_user_defined = 1 THEN SCHEMA_NAME(t.schema_id) + '.' + t.name ELSE t.name END AS data_type,\n CASE WHEN c.is_nullable = 1 THEN 'YES' ELSE 'NO' END AS is_nullable, c.column_id AS ordinal_position,\n COUNT(*) OVER (PARTITION BY o.object_id) AS visible_column_count\nFROM sys.objects o\nJOIN sys.schemas sn ON sn.schema_id = o.schema_id\nJOIN sys.columns c ON c.object_id = o.object_id\nJOIN sys.types t ON t.user_type_id = c.user_type_id\nWHERE o.type IN ('U', 'V') AND o.is_ms_shipped = 0";
            if ($schema !== null) {
                $base .= ' AND sn.name = '.$this->literal($schema, $driver);
            }
            if ($search !== null) {
                $term = $this->searchLiteral($search, $driver);
                $base .= " AND (LOWER(o.name) LIKE LOWER($term) ESCAPE '!' OR EXISTS (SELECT 1 FROM sys.columns mc WHERE mc.object_id = o.object_id AND LOWER(mc.name) LIKE LOWER($term) ESCAPE '!'))";
            }

            return $base.' ORDER BY sn.name, o.name, c.column_id OFFSET '.$offset.' ROWS FETCH NEXT '.(self::PAGE_SIZE + 1).' ROWS ONLY';
        }
        if (! in_array($driver, ['postgres', 'mysql'], true)) {
            throw new RuntimeException('Este conector não oferece consulta paginada da estrutura.');
        }
        $type = $driver === 'postgres' ? "CASE WHEN c.data_type IN ('USER-DEFINED', 'ARRAY') THEN c.udt_schema || '.' || c.udt_name ELSE c.data_type END" : 'c.COLUMN_TYPE';
        $count = $driver === 'postgres' ? 'COUNT(*) OVER (PARTITION BY c.table_schema, c.table_name)' : '(SELECT COUNT(*) FROM information_schema.columns cc WHERE cc.table_schema = c.table_schema AND cc.table_name = c.table_name)';
        $excluded = $driver === 'postgres' ? "('pg_catalog','information_schema')" : "('information_schema','mysql','performance_schema','sys')";
        $base = 'SELECT c.table_schema AS schema_name, c.table_name, c.column_name, '.$type.' AS data_type, c.is_nullable, c.ordinal_position, '.$count.' AS visible_column_count FROM information_schema.columns c WHERE c.table_schema NOT IN '.$excluded;
        if ($schema !== null) {
            $base .= ' AND c.table_schema = '.$this->literal($schema, $driver);
        }
        if ($search !== null) {
            $term = $this->searchLiteral($search, $driver);
            $base .= " AND (LOWER(c.table_name) LIKE LOWER($term) ESCAPE '!' OR EXISTS (SELECT 1 FROM information_schema.columns mc WHERE mc.table_catalog = c.table_catalog AND mc.table_schema = c.table_schema AND mc.table_name = c.table_name AND LOWER(mc.column_name) LIKE LOWER($term) ESCAPE '!'))";
        }

        return $base.' ORDER BY c.table_schema, c.table_name, c.ordinal_position LIMIT '.(self::PAGE_SIZE + 1).' OFFSET '.$offset;
    }

    private function searchLiteral(string $value, string $driver): string
    {
        return $this->literal('%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%', $driver);
    }

    private function literal(string $value, string $driver): string
    {
        return ($driver === 'sqlserver' ? 'N' : '')."'".str_replace("'", "''", $value)."'";
    }

    private function positiveInteger(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value))) && (int) $value > 0 ? (int) $value : null;
    }
}
