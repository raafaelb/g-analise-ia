<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BiScheduleDefinition
{
    public const ACTIONS = ['create_schedule', 'update_schedule'];

    public const TRIGGERS = ['Day', 'Hour', 'Minute', 'Week', 'Month', 'CDC'];

    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['action', 'request_id', 'resource', 'version', 'name', 'active', 'agent', 'triggers', 'flows', 'notifications', 'parallel_lane']) !== [] || strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 200000) {
            $this->invalid('schedule', 'Envie somente os campos disponíveis no formulário da agenda.');
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim($input['name']);
        }
        $data = Validator::make($input, [
            'action' => ['required', 'in:create_schedule,update_schedule'],
            'request_id' => ['required', 'uuid'],
            'resource' => ['required_if:action,update_schedule', 'nullable', 'regex:/^schedule:[1-9][0-9]{0,17}$/D'],
            'version' => ['required_if:action,update_schedule', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'name' => ['required_if:action,create_schedule', 'filled', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'active' => ['sometimes', 'required', 'boolean'],
            'agent' => ['required_if:action,create_schedule', 'filled', 'integer', 'min:1', 'max:999999999999999999'],
            'triggers' => ['required_if:action,create_schedule', 'filled', 'array', 'min:1', 'max:100'],
            'triggers.*' => ['required', 'array:key,type,period,time,anchor,window_start,window_end,probe'],
            'triggers.*.key' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/D', 'distinct:strict'],
            'triggers.*.type' => ['required_without:triggers.*.key', 'filled', 'in:Day,Hour,Minute,Week,Month,CDC'],
            'triggers.*.period' => ['sometimes', 'required', 'integer', 'min:0', 'max:3600'],
            'triggers.*.time' => ['sometimes', 'required', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'triggers.*.anchor' => ['sometimes', 'required', 'string', 'max:40'],
            'triggers.*.window_start' => ['sometimes', 'nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'triggers.*.window_end' => ['sometimes', 'nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'triggers.*.probe' => ['sometimes', 'required', 'array:credential_id,sql,timeout_seconds'],
            'triggers.*.probe.credential_id' => ['required_with:triggers.*.probe', 'integer', 'min:1', 'max:999999999999999999'],
            'triggers.*.probe.sql' => ['required_with:triggers.*.probe', 'string', 'max:8192', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'triggers.*.probe.timeout_seconds' => ['required_with:triggers.*.probe', 'integer', 'min:1', 'max:300'],
            'flows' => ['required_if:action,create_schedule', 'filled', 'array', 'min:1', 'max:300'],
            'flows.*' => ['required', 'array:key,flow_id,context'],
            'flows.*.key' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/D', 'distinct:strict'],
            'flows.*.flow_id' => ['required_without:flows.*.key', 'filled', 'integer', 'min:1', 'max:999999999999999999'],
            'flows.*.context' => ['required_without:flows.*.key', 'filled', 'string', 'max:70', 'regex:/^(Total|Incremental|KeySweep|Temporal:(Days:[1-9][0-9]{0,4}|Future:[1-9][0-9]{0,4}|Month:(?:[1-9]|1[0-2])-\d{4}|Year:(?:\d{4}|Current)))$/D'],
            'notifications' => ['sometimes', 'required', 'array:on_error,on_success,emails'],
            'notifications.on_error' => ['sometimes', 'required', 'boolean'],
            'notifications.on_success' => ['sometimes', 'required', 'boolean'],
            'notifications.emails' => ['sometimes', 'array', 'max:30'],
            'notifications.emails.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'parallel_lane' => ['sometimes', 'required', 'boolean'],
        ])->validate();
        $creating = $data['action'] === 'create_schedule';
        if ($creating && (! empty($data['resource']) || ! empty($data['version']))) {
            $this->invalid('resource', 'Uma agenda nova não pode usar a referência ou a versão de uma agenda existente.');
        }
        foreach (['triggers', 'flows'] as $field) {
            if (isset($data[$field]) && ! array_is_list($data[$field])) {
                $this->invalid($field, 'Os itens da agenda precisam formar uma lista na ordem desejada.');
            }
            if ($creating && collect($data[$field] ?? [])->contains(fn (array $row): bool => isset($row['key']))) {
                $this->invalid($field, 'Uma agenda nova precisa de itens novos; referências de outra agenda não podem ser reutilizadas.');
            }
        }
        $values = array_intersect_key($data, array_fill_keys(['name', 'active', 'agent', 'triggers', 'flows', 'notifications', 'parallel_lane'], true));
        foreach (['active', 'parallel_lane'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = (bool) $values[$field];
            }
        }
        if ($creating && ! array_key_exists('active', $values)) {
            $values['active'] = false;
        }
        if ($creating) {
            $values['notifications'] = [...['on_error' => false, 'on_success' => false, 'emails' => []], ...($values['notifications'] ?? [])];
            $values['parallel_lane'] ??= false;
        }
        if ($values === []) {
            $this->invalid('schedule', 'Altere pelo menos um campo antes de conferir a agenda.');
        }

        return ['action' => $data['action'], 'resource' => $data['resource'] ?? null, 'source_version' => $data['version'] ?? null, 'request_id' => strtolower($data['request_id']), 'values' => $values];
    }

    public function defaults(): array
    {
        return ['nome' => '', 'ativo' => false, 'timezone' => 'America/Sao_Paulo', 'tokenId' => null, 'flows' => [], 'triggers' => []];
    }

    /** @param array<string, string> $credentialKeys Keys stay on the server. */
    public function apply(array $source, array $data, array $credentialKeys = []): array
    {
        $body = $source;
        $values = $data['values'];
        foreach (['name' => 'nome', 'active' => 'ativo', 'agent' => 'tokenId', 'parallel_lane' => 'parallel_lane'] as $input => $native) {
            if (array_key_exists($input, $values)) {
                $body[$native] = in_array($input, ['active', 'parallel_lane'], true) ? (bool) $values[$input] : ($input === 'agent' ? (int) $values[$input] : $values[$input]);
            }
        }
        if (array_key_exists('triggers', $values)) {
            $original = $this->keyed($source['triggers'] ?? [], 'trigger');
            $body['triggers'] = array_map(fn (array $row): array => $this->trigger($row, $original, $credentialKeys), $values['triggers']);
        }
        if (array_key_exists('flows', $values)) {
            $original = $this->keyed($source['flows'] ?? [], 'flow');
            $body['flows'] = [];
            $loads = [];
            $pairs = [];
            foreach ($values['flows'] as $row) {
                $binding = $this->original($row, $original, 'flows');
                if (isset($row['flow_id'])) {
                    if (isset($row['key']) && (string) ($binding['flow_etl_id'] ?? '') !== (string) $row['flow_id']) {
                        $this->invalid('flows', 'Para trocar a fonte, remova o vínculo anterior e adicione a nova fonte.');
                    }
                    $binding['flow_etl_id'] = (int) $row['flow_id'];
                }
                if (isset($row['context'])) {
                    if (($binding['context'] ?? null) !== $row['context']) {
                        unset($binding['temporal_value']);
                    }
                    $binding['context'] = $row['context'];
                }
                $id = (string) ($binding['flow_etl_id'] ?? '');
                $context = $binding['context'] ?? null;
                if (! ctype_digit($id) || (int) $id < 1 || ! is_string($context) || $context === '') {
                    $this->invalid('flows', 'Cada fonte precisa de uma identificação e uma forma de carga.');
                }
                if (isset($pairs[$id.':'.$context]) || ($context !== 'KeySweep' && isset($loads[$id]))) {
                    $this->invalid('flows', 'Uma fonte não pode ter cargas repetidas na mesma agenda.');
                }
                $pairs[$id.':'.$context] = true;
                if ($context !== 'KeySweep') {
                    $loads[$id] = true;
                }
                $body['flows'][] = $binding;
            }
        }
        foreach (['on_error' => 'sendmail_error', 'on_success' => 'sendmail_success', 'emails' => 'sendmail_emails'] as $input => $native) {
            if (array_key_exists($input, $values['notifications'] ?? [])) {
                $body[$native] = $input === 'emails' ? array_map(trim(...), $values['notifications'][$input]) : (bool) $values['notifications'][$input];
            }
        }
        if (empty($body['nome']) || ! is_bool($body['ativo'] ?? null) || ! is_int($body['tokenId'] ?? null) || $body['tokenId'] < 1 || ! is_array($body['flows'] ?? null) || $body['flows'] === [] || ! is_array($body['triggers'] ?? null) || $body['triggers'] === []) {
            $this->invalid('schedule', 'Confira nome, agente, fontes e horários antes de continuar.');
        }

        return $body;
    }

    /** @return array<string, array<string, mixed>> */
    public function keyed(array $rows, string $kind): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if ($row instanceof \stdClass) {
                $row = (array) $row;
            }
            if (! is_array($row)) {
                throw new RuntimeException('A agenda contém itens em um formato não reconhecido.');
            }
            $result[$this->digest([$kind, $index, $row])] = $row;
        }

        return $result;
    }

    public function version(array $body): string
    {
        return $this->digest(array_diff_key($body, array_fill_keys(['last_log', 'last_log_date', 'criado_em', 'atualizado_em'], true)));
    }

    public function digest(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if ($item instanceof \stdClass) {
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

    public function canonicalInstant(string $instant): string
    {
        $date = Carbon::parse($instant)->utc();
        $fraction = rtrim($date->format('u'), '0');

        return $date->format('Y-m-d\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }

    private function trigger(array $row, array $original, array $credentialKeys): array
    {
        $trigger = $this->original($row, $original, 'triggers');
        if (isset($row['key']) && array_keys($row) === ['key']) {
            return $trigger;
        }
        $type = $row['type'] ?? $trigger['type'] ?? null;
        if (! in_array($type, self::TRIGGERS, true)) {
            $this->invalid('triggers', 'Este gatilho da origem pode ser preservado ou removido; use um tipo disponível para criar outro horário.');
        }
        if (($trigger['type'] ?? null) !== $type) {
            unset($trigger['config']);
        }
        $trigger['type'] = $type;
        foreach (['period' => 'period', 'time' => 'start_when', 'anchor' => 'start_when', 'window_start' => 'start_time', 'window_end' => 'end_time'] as $input => $native) {
            if (array_key_exists($input, $row)) {
                $trigger[$native] = $input === 'period' ? (int) $row[$input] : $row[$input];
            }
        }
        if (isset($row['probe'])) {
            if ($type !== 'CDC') {
                $this->invalid('triggers', 'Uma consulta de detecção só pode ser usada no gatilho de mudanças de dados.');
            }
            $probe = $row['probe'];
            $this->assertProbeSql($probe['sql']);
            $key = $credentialKeys[(string) $probe['credential_id']] ?? null;
            if (! is_string($key) || $key === '') {
                $this->invalid('triggers', 'A conexão da detecção não está disponível para esta sessão.');
            }
            $trigger['config'] = ['kind' => 'query-scalar', 'credential' => $key, 'sql' => trim($probe['sql']), 'timeout_seconds' => (int) $probe['timeout_seconds']];
        }
        if (in_array($type, ['Day', 'Week', 'Month'], true)) {
            if (isset($row['anchor']) || ! $this->clock($trigger['start_when'] ?? null)) {
                $this->invalid('triggers', 'Informe o horário no formato hora e minuto para os gatilhos diário, semanal ou mensal.');
            }
        } elseif (isset($row['time']) || ! $this->instant($trigger['start_when'] ?? null)) {
            $this->invalid('triggers', 'Informe uma data de início com fuso para os intervalos e a detecção de mudanças.');
        }
        if (in_array($type, ['Hour', 'Minute', 'CDC'], true)) {
            $trigger['start_when'] = $this->canonicalInstant($trigger['start_when']);
        }
        $period = $trigger['period'] ?? null;
        $range = match ($type) {
            'Hour' => [1, 24], 'Minute' => [1, 60], 'Week' => [0, 6], 'Month' => [1, 31], 'CDC' => [5, 3600], default => null,
        };
        if ($range !== null && ((! is_int($period) && ! (is_string($period) && ctype_digit($period))) || (int) $period < $range[0] || (int) $period > $range[1])) {
            $this->invalid('triggers', 'Confira o intervalo ou o dia escolhido para este gatilho.');
        }
        if (in_array($type, ['Hour', 'Minute'], true) || ($type === 'CDC' && (! empty($trigger['start_time']) || ! empty($trigger['end_time'])))) {
            if (! $this->clock($trigger['start_time'] ?? null) || ! $this->clock($trigger['end_time'] ?? null) || $trigger['start_time'] >= $trigger['end_time']) {
                $this->invalid('triggers', 'Escolha uma janela com início anterior ao fim no mesmo dia. Para atravessar a meia-noite, configure duas janelas.');
            }
        }
        if ($type === 'CDC') {
            $config = $trigger['config'] ?? null;
            $config = $config instanceof \stdClass ? (array) $config : $config;
            if (! is_array($config) || ($config['kind'] ?? null) !== 'query-scalar' || empty($config['credential']) || ! is_string($config['sql'] ?? null) || (int) ($config['timeout_seconds'] ?? 5) >= (int) $period || (int) ($config['timeout_seconds'] ?? 5) < 1) {
                $this->invalid('triggers', 'A detecção precisa de uma consulta configurada e um tempo limite menor que seu intervalo de sondagem.');
            }
        }

        return $trigger;
    }

    private function original(array $row, array $original, string $field): array
    {
        if (! isset($row['key'])) {
            return [];
        }
        if (! isset($original[$row['key']])) {
            $this->invalid($field, 'Um item selecionado não pertence à versão atual desta agenda. Reabra a configuração.');
        }

        return $original[$row['key']];
    }

    private function clock(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1;
    }

    private function instant(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.\d{1,6})?(?:Z|[+-](\d{2}):([0-5][0-9]))$/D', $value, $match)) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) && (! isset($match[5]) || ((int) $match[5] < 14 || ((int) $match[5] === 14 && (int) $match[6] === 0)));
    }

    private function assertProbeSql(string $sql): void
    {
        if (strlen($sql) > 8192 || ! preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            $this->invalid('triggers', 'A detecção aceita uma única consulta SELECT ou WITH de até 8 KB.');
        }
        $plain = preg_replace('/\x27(?:\x27\x27|[^\x27])*\x27|"(?:""|[^"])*"|`(?:``|[^`])*`|\[(?:\]\]|[^\]])*\]|--[^\r\n]*|\/\*[\s\S]*?\*\//', ' ', $sql);
        $plain = trim((string) $plain);
        if (str_ends_with($plain, ';')) {
            $plain = rtrim(substr($plain, 0, -1));
        }
        if (str_contains($plain, ';') || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|COPY|CALL|EXEC|EXECUTE|ATTACH|DETACH|INTO|OUTFILE|DUMPFILE)\b/i', $plain)) {
            $this->invalid('triggers', 'A consulta de detecção deve apenas ler um resultado, sem comandos de alteração ou instruções adicionais.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
