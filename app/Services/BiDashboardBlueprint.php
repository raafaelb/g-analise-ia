<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

class BiDashboardBlueprint
{
    public const AGGREGATIONS = ['SUM' => 'Total de valores que podem ser somados', 'AVG' => 'Média de observações individuais', 'COUNT' => 'Quantidade de valores preenchidos', 'MIN' => 'Menor valor', 'MAX' => 'Maior valor'];

    public function normalize(array $input): array
    {
        $keys = ['request_id', 'version', 'name', 'metric_id', 'group_id', 'aggregation', 'grain', 'format', 'metric_title'];
        if (array_diff(array_keys($input), $keys) !== []) {
            throw ValidationException::withMessages(['dashboard' => 'Use somente o nome e as opções de dados deste assistente.']);
        }
        foreach (['name', 'metric_title'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        $data = Validator::make($input, ['request_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'metric_id' => ['required', 'integer', 'min:1'], 'group_id' => ['required', 'integer', 'min:1'], 'aggregation' => ['required', Rule::in(array_keys(self::AGGREGATIONS))], 'grain' => ['required', Rule::in(['AGP', 'DATE', 'YEARMONTH', 'YEAR'])], 'format' => ['required', Rule::in(['default', 'integer', 'decimal', 'currency'])], 'metric_title' => ['sometimes', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();

        return ['action' => 'create_dashboard', 'request_id' => strtolower($data['request_id']), 'source_version' => $data['version'], 'name' => $data['name'], 'metric_id' => (int) $data['metric_id'], 'group_id' => (int) $data['group_id'], 'aggregation' => $data['aggregation'], 'grain' => $data['grain'], 'format' => $data['format'], 'metric_title' => $data['metric_title'] ?? ''];
    }

    public function fields(array $info, stdClass $working, string $resource, string $tenant): array
    {
        $payload = $info['payload'] ?? null;
        $header = $payload['header'] ?? null;
        $tables = $payload['body']['tables'] ?? null;
        if (! is_array($header) || ($header['kind'] ?? null) !== 'app' || ! is_int($header['id'] ?? null) || 'app:'.$header['id'] !== $resource || ! is_int($header['tenantId'] ?? null) || (string) $header['tenantId'] !== $tenant || ! is_array($tables) || ! array_is_list($tables) || ! is_array($working->tables ?? null)) {
            throw new RuntimeException('Os campos não foram confirmados nesta aplicação e neste ambiente.');
        }
        $expected = [];
        foreach ($working->tables as $binding) {
            if (! is_int($binding->tableId ?? null) || $binding->tableId < 1) {
                throw new RuntimeException('Uma tabela vinculada não foi identificada com segurança.');
            }
            $expected[] = $binding->tableId;
        }
        $received = [];
        $fields = [];
        foreach ($tables as $table) {
            if (! is_array($table) || ! is_int($table['tableId'] ?? null) || $table['tableId'] < 1 || ! is_string($table['nome'] ?? null) || ! is_array($table['columns'] ?? null) || ! array_is_list($table['columns'])) {
                throw new RuntimeException('A estrutura completa das tabelas não está disponível para o assistente.');
            }
            $received[] = $table['tableId'];
            foreach ($table['columns'] as $column) {
                if (! is_array($column) || ! is_int($column['id'] ?? null) || $column['id'] < 1 || ! is_string($column['column_name'] ?? null) || ! is_string($column['data_type'] ?? null)) {
                    throw new RuntimeException('Um campo retornou sem identidade ou tipo confirmado.');
                }
                $label = is_string($column['label'] ?? null) && trim($column['label']) !== '' ? $column['label'] : $column['column_name'];
                $field = ['id' => $column['id'], 'table_id' => $table['tableId'], 'table_name' => Str::limit($table['nome'], 160, '…'), 'label' => Str::limit($label, 160, '…'), 'query_ref' => $table['nome'].'.'.$label, 'type' => $column['data_type'], 'numeric' => $column['data_type'] === 'Number', 'date' => in_array($column['data_type'], ['Date', 'DateTime'], true), 'groupable' => in_array($column['data_type'], ['String', 'Number', 'Date', 'DateTime', 'Boolean'], true)];
                if (isset($fields[$field['id']]) && $fields[$field['id']] !== $field) {
                    throw new RuntimeException('A aplicação usa o mesmo campo em contextos diferentes. Revise a modelagem com a IA antes de usar o assistente.');
                }
                $fields[$field['id']] = $field;
                if (count($fields) > 2000) {
                    throw new RuntimeException('Esta aplicação tem mais campos do que o assistente pode apresentar. Use o projeto para escolher e preparar os indicadores com a IA.');
                }
            }
        }
        sort($expected);
        sort($received);
        if ($expected !== $received) {
            throw new RuntimeException('As tabelas dos campos não correspondem às tabelas vinculadas à aplicação.');
        }

        return array_values($fields);
    }

    public function selection(array $data, array $fields): array
    {
        $byId = array_column($fields, null, 'id');
        $metric = $byId[$data['metric_id']] ?? null;
        $group = $byId[$data['group_id']] ?? null;
        if (! $metric || ! $group || ! $group['groupable']) {
            throw ValidationException::withMessages(['dashboard' => 'Escolha campos disponíveis nesta aplicação.']);
        }
        if ($data['aggregation'] !== 'COUNT' && ! $metric['numeric']) {
            throw ValidationException::withMessages(['dashboard' => 'Este cálculo precisa de um campo numérico. Para outros campos, escolha a contagem de valores preenchidos.']);
        }
        if ((! $group['date'] && $data['grain'] !== 'AGP') || ($group['date'] && ! in_array($data['grain'], ['DATE', 'YEARMONTH', 'YEAR'], true))) {
            throw ValidationException::withMessages(['dashboard' => 'Escolha um período para campos de data e uma categoria para os demais campos.']);
        }
        $prefix = ['SUM' => 'Total', 'AVG' => 'Média', 'COUNT' => 'Quantidade', 'MIN' => 'Menor valor', 'MAX' => 'Maior valor'][$data['aggregation']];
        $title = $data['metric_title'] !== '' ? $data['metric_title'] : Str::limit($prefix.' de '.$metric['label'], 100, '…');
        $groupLabel = ($group['date'] ? ['DATE' => 'Dia de ', 'YEARMONTH' => 'Mês de ', 'YEAR' => 'Ano de '][$data['grain']] : '').$group['label'];

        return ['metric' => $metric, 'group' => $group, 'metric_ref' => $metric['id'].':'.$data['aggregation'], 'group_ref' => $group['id'].':'.$data['grain'], 'metric_title' => $title, 'group_title' => $groupLabel, 'aggregation_label' => self::AGGREGATIONS[$data['aggregation']], 'format' => $data['aggregation'] === 'COUNT' ? 'integer' : $data['format'], 'chart_type' => $group['date'] ? 'line' : 'bar'];
    }

    public function dashboard(array $data, array $selection, int $position, bool $perDashboard): stdClass
    {
        $metric = $selection['metric_ref'];
        $group = $selection['group_ref'];
        $format = $selection['format'];
        $date = $selection['chart_type'] === 'line';
        $title = $selection['metric_title'];
        $groupTitle = $selection['group_title'];
        $color = fn (string $value = ''): array => ['value' => $value, 'expression' => null];
        $secondaryVisual = ['alignItems' => $color('center'), 'backgroundChartColor' => $color(), 'backgroundColor' => $color(), 'chartLine' => $color(), 'numberColor' => $color(), 'numberFormat' => ['format' => 'default', 'expression' => null], 'numberSize' => 24, 'titleColor' => $color(), 'titleSize' => 16];
        $widget = fn (string $type, string $name, int $w, int $h, int $x, int $y, array $chart): array => ['type' => $type, 'nome' => Str::limit($name, 100, '…'), 'display_nome' => $type !== 'Kpi', 'w' => $w, 'h' => $h, 'x' => $x, 'y' => $y, 'mobileY' => $y, 'mobileH' => $h, 'mobileVisible' => true, 'chart' => $chart];
        $sort = $date ? $group : $metric;
        $direction = $date ? 'ASC' : 'DESC';
        $options = ['customLabel' => true, 'label' => $title, 'numberFormat' => $format === 'default' ? 'default' : 'list'];
        if ($format !== 'default') {
            $options['numberFormatOption'] = $format;
        }
        $totalizer = match ($data['aggregation']) {
            'SUM', 'COUNT' => 'sum', 'MIN' => 'min', 'MAX' => 'max', default => null,
        };
        if ($totalizer !== null) {
            $options['totalizerType'] = $totalizer;
        }
        $widgets = [
            $widget('Kpi', $title, 12, 1, 0, 0, ['primaryData' => ['dataSource' => $metric, 'title' => ['value' => $title, 'expression' => null], 'filter' => []], 'secondaryData' => ['dataSource' => '', 'expression' => null, 'filters' => null, 'title' => ['expression' => null, 'value' => ''], 'visual' => $secondaryVisual], 'historicalComparison' => ['column_id' => '', 'comparisonType' => 'previousMonth', 'defaultPeriod' => 'currentMonth', 'evolutionChart' => false, 'variation' => false, 'variationIcon' => 'arrow'], 'visual' => ['numberSize' => 38, 'titleSize' => 15, 'numberColor' => $color('#0861ee'), 'titleColor' => $color('#526783'), 'backgroundColor' => $color('#eff6ff'), 'backgroundChartColor' => $color(), 'chartLine' => $color(), 'alignItems' => $color('center'), 'numberFormat' => $format], 'visualType' => 'highlight']),
            $widget('Barline', $title.' por '.$groupTitle, 7, 4, 0, 1, ['globalType' => $selection['chart_type'], 'dimensionColumns' => [$group], 'valueColumns' => [$metric], 'orderColumns' => [$sort], 'orderColumnsDir' => [$direction], 'orientation' => 'vertical', 'showDataLabels' => ! $date, 'showDataScale' => true, 'showDataLegend' => false, 'gridLineX' => false, 'gridLineY' => true, 'markers' => $date, 'limit' => true, 'limitCount' => 100, 'valueConfig' => (object) ['0' => ['coloringType' => 'byValue', 'color' => '#0861ee', 'dataLabelNumberFormat' => $format]], 'visualType' => 'transparent', 'filter' => []]),
            $widget('Table', 'Detalhamento por '.$groupTitle, 5, 4, 7, 1, ['fields' => [$group, $metric], 'orderColumns' => [$sort], 'orderColumnsDir' => [$direction], 'fieldOptions' => (object) ['0' => ['customLabel' => true, 'label' => $groupTitle], '1' => $options], 'showLineCount' => false, 'showTotal' => $totalizer !== null, 'zebraLines' => true, 'cellPadding' => 'medium', 'fontSize' => 'medium', 'limitRows' => 100, 'filter' => [], 'visualType' => 'transparent']),
        ];

        return json_decode(json_encode(['header' => ['id' => 0], 'body' => ['nome' => $data['name'], 'ordem' => $position, 'widget_columns' => 12, 'widget_float' => false, 'allow_new_filters' => $perDashboard ? true : null, 'filters' => $perDashboard ? [] : null, 'widgets' => $widgets]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    public function preview(array $total, array $groups, array $data, array $selection): array
    {
        $valueKey = $data['metric_id'].'_'.$data['aggregation'];
        $groupKey = $data['group_id'].'_'.$data['grain'];
        if (! is_array($total['rows'] ?? null) || ! array_is_list($total['rows']) || count($total['rows']) !== 1 || ! array_key_exists($valueKey, $total['rows'][0]) || ! is_array($groups['rows'] ?? null) || ! array_is_list($groups['rows']) || count($groups['rows']) > 26) {
            throw new RuntimeException('O BI não devolveu uma amostra reconhecida dos campos escolhidos. Confira o acesso e a modelagem antes de criar o painel.');
        }
        $number = function (mixed $value): int|float|null {
            if ($value === null) {
                return null;
            }
            if ((! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) || ! is_finite((float) $value)) {
                throw new RuntimeException('Um indicador não retornou um valor numérico reconhecido.');
            }

            return is_int($value) ? $value : (float) $value;
        };
        $rows = [];
        foreach (array_slice($groups['rows'], 0, 25) as $row) {
            if (! is_array($row) || ! array_key_exists($groupKey, $row) || ! array_key_exists($valueKey, $row) || (! is_scalar($row[$groupKey]) && $row[$groupKey] !== null)) {
                throw new RuntimeException('Uma categoria da amostra retornou sem o campo esperado.');
            }
            $rows[] = ['label' => $row[$groupKey] === null ? 'Sem valor' : Str::limit((string) $row[$groupKey], 160, '…'), 'value' => $number($row[$valueKey])];
        }

        return ['metric_title' => $selection['metric_title'], 'group_title' => $selection['group_title'], 'format' => $selection['format'], 'chart_type' => $selection['chart_type'], 'value' => $number($total['rows'][0][$valueKey]), 'rows' => $rows, 'truncated' => ($groups['truncated'] ?? false) || count($groups['rows']) > 25, 'checked_at' => now()->toIso8601String(), 'scope' => 'Amostra consultada pelo MCP com a conta autorizada para este ambiente. Os filtros iniciais e as seleções do painel interativo podem mudar os valores exibidos. A amostra mostra até 25 grupos; o gráfico e a tabela do novo painel mostram até 100.'];
    }

    public function digest(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if ($item instanceof stdClass) {
                $fields = get_object_vars($item);
                ksort($fields);

                return (object) array_map($canonical, $fields);
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($canonical, $item);
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }
}
