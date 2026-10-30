<?php

namespace App\Services;

class BiResourceOverview
{
    public function __construct(private RefreshCoverage $coverage) {}

    /** @return array<string, mixed> */
    public function build(string $kind, array $body): array
    {
        $facts = [];
        $add = function (string $label, mixed $value, string $format = 'text') use (&$facts): void {
            if (is_scalar($value) && $value !== '') {
                $facts[] = ['label' => $label, 'value' => $value, 'format' => $format];
            }
        };
        $columns = [];
        $nodes = [];
        if (in_array($kind, ['dw-desk', 'bi-desk'], true)) {
            $add('Tipo de mesa', $kind === 'dw-desk' ? 'Dados e ETLs' : 'Aplicações e dashboards');
            $add('Criada em', $body['criado_em'] ?? null, 'date');
            $add('Última alteração', $body['atualizado_em'] ?? null, 'date');
        }
        if ($kind === 'table') {
            $columns = $this->columns(is_array($body['columns'] ?? null) ? $body['columns'] : []);
            if (is_array($body['columns'] ?? null)) {
                $add('Colunas', count($columns), 'number');
            }
            $add('Campos calculados', count($body['expressions'] ?? []), 'number');
            $add('Tipo de tabela', $body['table_type'] ?? null);
        }
        if ($kind === 'flow') {
            $nodes = $this->nodes(is_array($body['nodes'] ?? null) ? $body['nodes'] : []);
            $add('Forma de atualização', match ($body['load_type'] ?? '') {
                'Total' => 'Carga completa', 'Incremental' => 'Carga incremental', 'Temporal' => 'Por período', default => $body['load_type'] ?? null,
            });
            if (is_array($body['nodes'] ?? null)) {
                $add('Etapas', count($nodes), 'number');
            }
            $add('Gravação', $body['write_mode'] ?? null);
        }
        if ($kind === 'schedule') {
            if (array_key_exists('ativo', $body)) {
                $add('Situação', $body['ativo'] ? 'Ativa' : 'Pausada');
            }
            $add('Frequência', $this->coverage->frequency($body['triggers'] ?? []));
            $add('Fuso horário', $body['timezone'] ?? null);
            $add('ETLs vinculados', count($body['flows'] ?? []), 'number');
        }
        if ($kind === 'credential') {
            $add('Tipo de conexão', $body['type'] ?? null);
        }
        if ($kind === 'agent') {
            if (array_key_exists('online', $body)) {
                $add('Conectividade', $body['online'] ? 'Online' : 'Offline');
            }
            $add('Sistema', $body['os'] ?? $body['platform'] ?? null);
            $add('Versão', $body['version'] ?? null);
        }
        if ($kind === 'writeback') {
            $columns = $this->columns(is_array($body['fields'] ?? null) ? $body['fields'] : []);
            if (is_array($body['fields'] ?? null)) {
                $add('Campos do cadastro', count($columns), 'number');
            }
        }

        return ['facts' => $facts, 'columns' => $columns, 'nodes' => $nodes, 'columns_available' => is_array($body[$kind === 'writeback' ? 'fields' : 'columns'] ?? null), 'nodes_available' => is_array($body['nodes'] ?? null)];
    }

    /** @return list<array<string, mixed>> */
    private function columns(array $source): array
    {
        $columns = [];
        foreach ($source as $key => $column) {
            if (! is_array($column)) {
                continue;
            }
            $name = $column['name'] ?? $column['column_name'] ?? $column['alias'] ?? (is_string($key) ? $key : null);
            if (! is_string($name)) {
                continue;
            }
            $type = $column['type'] ?? $column['data_type'] ?? null;
            if (is_string($key) && preg_match('/^(.*?) \((.+)\)$/u', $key, $match)) {
                $name = $match[1];
                $type = $match[2];
            }
            $text = fn (mixed $value): ?string => is_string($value) ? $value : null;
            $columns[] = ['name' => $name, 'type' => $text($type), 'label' => $text($column['label'] ?? null) ?? $name, 'description' => $text($column['human_description'] ?? $column['description'] ?? null), 'semantic_role' => $text($column['semantic_role'] ?? $column['role'] ?? null), 'mask' => $text($column['mask'] ?? null)];
        }

        return $columns;
    }

    /** @return list<array<string, mixed>> */
    private function nodes(array $source): array
    {
        $processors = $source['Processors'] ?? (array_is_list($source) ? $source : []);
        $nodes = [];
        foreach ($processors as $processor) {
            if (! is_array($processor)) {
                continue;
            }
            $type = is_string($processor['kind'] ?? $processor['type'] ?? null) ? ($processor['kind'] ?? $processor['type']) : 'Etapa';
            $default = match ($type) {
                'ExtractPostgreSQL' => 'Ler dados do PostgreSQL', 'GetDataSQL', 'ExtractSQLServer' => 'Consultar banco de dados', 'PythonProcessor' => 'Transformar os dados', 'InsertDatawarehouse' => 'Salvar no armazém de dados', default => preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $type),
            };
            $description = $processor['description'] ?? null;
            $executed = $processor['metadata']['ExecutedWhen'] ?? null;
            $nodes[] = ['id' => (string) ($processor['internalId'] ?? $processor['id'] ?? count($nodes)), 'name' => is_string($description) && $description !== '' ? $description : $default, 'type' => $type, 'input_count' => is_array($processor['inputs'] ?? null) ? count($processor['inputs']) : 0, 'executed_at' => is_string($executed) ? $executed : null, 'rows' => is_numeric($processor['metadata']['Rows'] ?? null) ? (int) $processor['metadata']['Rows'] : null];
        }

        return $nodes;
    }
}
