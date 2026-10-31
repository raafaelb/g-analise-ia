<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use RuntimeException;

class BiSourceRelationships
{
    private const PAGE_SIZE = 200;

    public function __construct(private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function inspect(Connection $connection, Project $context, string $resource, ?string $driver, ?string $schema, ?string $search, int $offset): array
    {
        if (! in_array($driver, ['postgres', 'mysql', 'sqlserver'], true)) {
            return ['verified' => false, 'state' => 'unsupported', 'message' => 'A consulta de relacionamentos declarados está disponível para PostgreSQL, MySQL e SQL Server. Use as tabelas e colunas e os ETLs existentes para investigar esta fonte, sem presumir vínculos.', 'checked_at' => now()->toIso8601String()];
        }
        $query = $this->query($driver, $schema, $search, $offset);
        $data = $this->lumo->run($connection, ['credential', 'query', $resource, '--sql', $query, '--limit', (string) (self::PAGE_SIZE + 1), '--timeout', '45s'], $context, 60)['data'] ?? null;
        if (! is_array($data) || ! is_array($data['rows'] ?? null) || ! array_is_list($data['rows']) || count($data['rows']) > self::PAGE_SIZE + 1) {
            throw new RuntimeException('O agente não retornou uma lista confirmada de relacionamentos da fonte.');
        }
        foreach (['Success', 'success', 'ok'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== true) {
                throw new RuntimeException('O estado da consulta de relacionamentos não foi confirmado.');
            }
        }
        $emptyEvidence = null;
        if ($data['rows'] === []) {
            $columns = $data['columns'] ?? [];
            $names = is_array($columns) ? array_map(fn (mixed $column): string => strtolower(is_string($column) ? $column : (is_array($column) && is_string($column['name'] ?? null) ? $column['name'] : '')), $columns) : [];
            if (array_diff(['constraint_schema', 'constraint_name', 'source_schema', 'source_table', 'target_schema', 'target_table', 'source_column', 'target_column', 'position', 'column_count', 'validated', 'enabled'], $names) !== []) {
                if (! $this->confirmsEmpty($connection, $context, $resource, $query)) {
                    return ['verified' => false, 'state' => 'unconfirmed', 'message' => 'O agente retornou uma resposta vazia sem confirmação suficiente da consulta. Isso pode indicar falta de acesso ou falha na fonte; não comprova ausência de relacionamentos.', 'checked_at' => now()->toIso8601String()];
                }
                $emptyEvidence = 'bounded_count';
            } else {
                $emptyEvidence = 'result_columns';
            }
        }
        $truncated = count($data['rows']) > self::PAGE_SIZE;
        $relations = [];
        foreach (array_slice($data['rows'], 0, self::PAGE_SIZE) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('O formato dos relacionamentos não foi reconhecido.');
            }
            $row = array_change_key_case($row, CASE_LOWER);
            foreach (['constraint_schema', 'constraint_name', 'source_schema', 'source_table', 'target_schema', 'target_table', 'source_column', 'target_column'] as $field) {
                if (! is_string($row[$field] ?? null) || $row[$field] === '' || strlen($row[$field]) > 1024) {
                    throw new RuntimeException('O formato dos relacionamentos não foi reconhecido.');
                }
            }
            $position = $this->positiveInteger($row['position'] ?? null);
            $count = $this->positiveInteger($row['column_count'] ?? null);
            if ($position === null || $count === null || $position > $count || $count > 1600) {
                throw new RuntimeException('A ordem dos campos do relacionamento não foi confirmada.');
            }
            $identity = [$row['constraint_schema'], $row['constraint_name'], $row['source_schema'], $row['source_table']];
            $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            $base = ['id' => $id, 'name' => $row['constraint_name'], 'from' => ['schema' => $row['source_schema'], 'table' => $row['source_table']], 'to' => ['schema' => $row['target_schema'], 'table' => $row['target_table']], 'column_count' => $count, 'column_count_verified' => $driver !== 'mysql', 'validated' => $this->flag($row['validated'] ?? null), 'enabled' => $this->flag($row['enabled'] ?? null)];
            if (isset($relations[$id]) && array_diff_key($relations[$id], ['columns' => true]) !== $base) {
                throw new RuntimeException('O BI retornou definições divergentes para o mesmo relacionamento.');
            }
            $relations[$id] ??= [...$base, 'columns' => []];
            if (isset($relations[$id]['columns'][$position])) {
                throw new RuntimeException('O BI retornou campos duplicados no mesmo relacionamento.');
            }
            $relations[$id]['columns'][$position] = ['source' => $row['source_column'], 'target' => $row['target_column'], 'position' => $position];
        }
        foreach ($relations as &$relation) {
            ksort($relation['columns']);
            $relation['complete'] = $relation['column_count_verified'] && array_keys($relation['columns']) === range(1, $relation['column_count']);
            $relation['columns'] = array_values($relation['columns']);
        }
        unset($relation);

        return ['verified' => true, 'state' => 'available', 'resource' => $resource, 'driver' => $driver, 'relationships' => array_values($relations), 'truncated' => $truncated, 'page_size' => self::PAGE_SIZE, 'offset' => $offset, 'next_offset' => $truncated && $offset + self::PAGE_SIZE <= 1000000 ? $offset + self::PAGE_SIZE : null, 'pagination_limit_reached' => $truncated && $offset + self::PAGE_SIZE > 1000000, 'search' => $search, 'schema' => $schema, 'coverage' => 'visible_declared_constraints', 'empty_result_evidence' => $emptyEvidence, 'scope_warning' => 'São vínculos declarados e visíveis para esta conexão, não todos os relacionamentos possíveis do negócio. Uma lista vazia não comprova ausência de vínculos. Confirme os dados antes de combinar tabelas; vínculos incompletos, não validados ou desativados exigem investigação.', 'message' => $relations === [] ? 'Nenhum relacionamento declarado ficou visível nesta consulta e nos filtros escolhidos.' : count($relations).' relacionamento(s) declarado(s) nesta página.', 'checked_at' => now()->toIso8601String()];
    }

    private function confirmsEmpty(Connection $connection, Project $context, string $resource, string $query): bool
    {
        $sql = 'SELECT COUNT(*) AS metadata_row_count FROM ('.$query.') AS relationship_probe';
        $data = $this->lumo->run($connection, ['credential', 'query', $resource, '--sql', $sql, '--limit', '1', '--timeout', '45s'], $context, 60)['data'] ?? null;
        if (! is_array($data)) {
            return false;
        }
        foreach (['Success', 'success', 'ok'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== true) {
                return false;
            }
        }
        $rows = $data['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            return false;
        }
        $row = array_change_key_case($rows[0], CASE_LOWER);

        return in_array($row['metadata_row_count'] ?? null, [0, '0'], true);
    }

    private function query(string $driver, ?string $schema, ?string $search, int $offset): string
    {
        $base = match ($driver) {
            'postgres' => <<<'SQL'
SELECT cn.nspname AS constraint_schema, c.conname AS constraint_name,
 sn.nspname AS source_schema, s.relname AS source_table,
 tn.nspname AS target_schema, t.relname AS target_table,
 sa.attname AS source_column, ta.attname AS target_column,
 k.position, cardinality(c.conkey) AS column_count,
 c.convalidated AS validated, NULL AS enabled
FROM pg_catalog.pg_constraint c
JOIN pg_catalog.pg_namespace cn ON cn.oid = c.connamespace
JOIN pg_catalog.pg_class s ON s.oid = c.conrelid
JOIN pg_catalog.pg_namespace sn ON sn.oid = s.relnamespace
JOIN pg_catalog.pg_class t ON t.oid = c.confrelid
JOIN pg_catalog.pg_namespace tn ON tn.oid = t.relnamespace
CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(source_number, target_number, position)
JOIN pg_catalog.pg_attribute sa ON sa.attrelid = s.oid AND sa.attnum = k.source_number
JOIN pg_catalog.pg_attribute ta ON ta.attrelid = t.oid AND ta.attnum = k.target_number
WHERE c.contype = 'f' AND sn.nspname NOT IN ('pg_catalog', 'information_schema')
 AND has_column_privilege(s.oid, sa.attnum, 'SELECT') AND has_column_privilege(t.oid, ta.attnum, 'SELECT')
SQL,
            'mysql' => <<<'SQL'
SELECT k.CONSTRAINT_SCHEMA AS constraint_schema, k.CONSTRAINT_NAME AS constraint_name,
 k.TABLE_SCHEMA AS source_schema, k.TABLE_NAME AS source_table,
 k.REFERENCED_TABLE_SCHEMA AS target_schema, k.REFERENCED_TABLE_NAME AS target_table,
 k.COLUMN_NAME AS source_column, k.REFERENCED_COLUMN_NAME AS target_column,
 k.ORDINAL_POSITION AS position,
 (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE kc
  WHERE kc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND kc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND kc.TABLE_SCHEMA = k.TABLE_SCHEMA AND kc.TABLE_NAME = k.TABLE_NAME AND kc.REFERENCED_TABLE_NAME IS NOT NULL) AS column_count,
 NULL AS validated, NULL AS enabled
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.REFERENCED_TABLE_NAME IS NOT NULL
 AND k.TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
SQL,
            'sqlserver' => <<<'SQL'
SELECT sn.name AS constraint_schema, fk.name AS constraint_name,
 sn.name AS source_schema, s.name AS source_table,
 tn.name AS target_schema, t.name AS target_table,
 sc.name AS source_column, tc.name AS target_column,
 k.constraint_column_id AS position,
 COUNT(*) OVER (PARTITION BY fk.object_id) AS column_count,
 CASE WHEN fk.is_not_trusted = 0 THEN 1 ELSE 0 END AS validated,
 CASE WHEN fk.is_disabled = 0 THEN 1 ELSE 0 END AS enabled
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns k ON k.constraint_object_id = fk.object_id
JOIN sys.tables s ON s.object_id = k.parent_object_id
JOIN sys.schemas sn ON sn.schema_id = s.schema_id
JOIN sys.tables t ON t.object_id = k.referenced_object_id
JOIN sys.schemas tn ON tn.schema_id = t.schema_id
JOIN sys.columns sc ON sc.object_id = s.object_id AND sc.column_id = k.parent_column_id
JOIN sys.columns tc ON tc.object_id = t.object_id AND tc.column_id = k.referenced_column_id
SQL,
        };
        $where = [];
        if ($schema !== null) {
            $literal = $this->literal($schema);
            $where[] = '(source_schema = '.$literal.' OR target_schema = '.$literal.')';
        }
        if ($search !== null) {
            $literal = $this->literal('%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%');
            $where[] = '('.implode(' OR ', array_map(fn (string $field): string => "LOWER($field) LIKE LOWER($literal) ESCAPE '!'", ['constraint_name', 'source_schema', 'source_table', 'target_schema', 'target_table'])).')';
        }
        $query = 'SELECT * FROM ('.$base.') AS declared_links'.($where !== [] ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY constraint_schema, constraint_name, source_schema, source_table, position';

        return $query.($driver === 'sqlserver' ? ' OFFSET '.$offset.' ROWS FETCH NEXT '.(self::PAGE_SIZE + 1).' ROWS ONLY' : ' LIMIT '.(self::PAGE_SIZE + 1).' OFFSET '.$offset);
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function positiveInteger(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value))) && (int) $value > 0 ? (int) $value : null;
    }

    private function flag(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'true', 't' => true,
            false, 0, '0', 'false', 'f' => false,
            null => null,
            default => throw new RuntimeException('O estado de validação do relacionamento não foi reconhecido.'),
        };
    }
}
