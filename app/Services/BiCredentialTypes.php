<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BiCredentialTypes
{
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $text = fn (string $label, string $key, string $hint = ''): array => ['label' => $label, 'key' => $key, 'kind' => 'text', 'required' => true, 'hint' => $hint];
        $port = fn (int $default): array => ['label' => 'Porta', 'key' => 'port', 'kind' => 'number', 'required' => true, 'default' => $default, 'min' => 1, 'max' => 65535];
        $password = ['label' => 'Senha do banco', 'key' => 'password', 'kind' => 'password', 'secret' => true, 'required' => false, 'hint' => 'Na edição, deixe em branco para manter a senha atual.'];
        $user = $text('Usuário do banco', 'username');
        $database = $text('Nome do banco', 'database');
        $host = fn (string $key): array => $text('Endereço do servidor', $key, 'Endereço acessível pelo agente selecionado.');

        return [
            'postgres' => ['label' => 'PostgreSQL', 'native_type' => 'postgresql', 'aliases' => ['postgres', 'postgresql', 'extractpostgresql'], 'fields' => [$host('host'), $port(5432), $database, $user, $password]],
            'mysql' => ['label' => 'MySQL', 'native_type' => 'mysql', 'aliases' => ['mysql', 'extractmysql'], 'fields' => [$host('hostname'), $port(3306), $database, $user, $password]],
            'sqlserver' => ['label' => 'SQL Server', 'native_type' => 'sqlserver', 'aliases' => ['sqlserver', 'extractsqlserver'], 'fields' => [$host('hostname'), $database, $user, ['label' => 'Validar o certificado do servidor', 'key' => 'validateCertificate', 'kind' => 'checkbox', 'default' => true], $password]],
            'oracle' => ['label' => 'Oracle', 'native_type' => 'oracle', 'aliases' => ['oracle', 'oracledb', 'extractoracledb'], 'fields' => [$text('Nome TNS ou endereço do serviço', 'datasource', 'Ex.: servidor:1521/servico'), $user, $password]],
            'firebird' => ['label' => 'Firebird', 'native_type' => 'firebird', 'aliases' => ['firebird', 'extractfirebird'], 'fields' => [$text('Caminho ou endereço do banco .fdb', 'database'), $port(3050), $user, ['label' => 'Conjunto de caracteres', 'key' => 'charset', 'kind' => 'text', 'required' => true, 'default' => 'UTF8'], ['label' => 'Forma de acesso', 'key' => 'serverType', 'kind' => 'select', 'default' => 'Default', 'options' => [['value' => 'Default', 'label' => 'Servidor'], ['value' => 'Embedded', 'label' => 'Banco local do agente']]], ['label' => 'Dialeto SQL', 'key' => 'dialect', 'kind' => 'select', 'default' => 3, 'options' => [['value' => 3, 'label' => '3'], ['value' => 1, 'label' => '1']]], $password]],
            'bigquery' => ['label' => 'Google BigQuery', 'native_type' => 'bigquery', 'aliases' => ['bigquery', 'extractbigquery'], 'fields' => [$text('Projeto do Google Cloud', 'ProjectId'), ['label' => 'Chave JSON da conta de serviço', 'key' => 'JsonKey', 'kind' => 'textarea', 'secret' => true, 'required' => true, 'hint' => 'Cole a chave completa. Ela não será exibida no histórico nem enviada à IA.']]],
            'informix' => ['label' => 'Informix', 'native_type' => 'informix', 'aliases' => ['informix', 'extractinformix'], 'fields' => [$host('hostname'), $port(9088), $database, $text('Nome do servidor Informix', 'informixserver'), $user, $password]],
            'odbc' => ['label' => 'ODBC', 'native_type' => 'odbc', 'aliases' => ['odbc', 'extractodbc'], 'fields' => [['label' => 'String de conexão ODBC', 'key' => 'odbcconnection', 'kind' => 'textarea', 'secret' => true, 'required' => true, 'hint' => 'Use o DSN ou os parâmetros reconhecidos pelo agente. Todos os valores serão marcados como protegidos.']]],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function choices(): array
    {
        $result = [];
        foreach ($this->definitions() as $id => $definition) {
            $result[] = ['id' => $id, 'label' => $definition['label'], 'fields' => $definition['fields']];
        }

        return $result;
    }

    public function driver(string $nativeType): ?string
    {
        $nativeType = strtolower($nativeType);
        foreach ($this->definitions() as $id => $definition) {
            if (in_array($nativeType, $definition['aliases'], true)) {
                return $id;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['action', 'request_id', 'resource', 'driver', 'name', 'agent', 'values']) !== []) {
            throw ValidationException::withMessages(['connection' => 'A solicitação contém campos não permitidos.']);
        }
        $base = Validator::make($input, ['action' => ['required', Rule::in(['create_credential', 'update_credential'])], 'request_id' => ['required', 'uuid'], 'resource' => ['required_if:action,update_credential', 'nullable', 'regex:/^credential:[1-9][0-9]*$/D'], 'driver' => ['required', Rule::in(array_keys($this->definitions()))], 'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'agent' => ['required', 'integer', 'min:1'], 'values' => ['required', 'array']], ['required' => 'Informe :attribute.', 'required_if' => 'Informe :attribute.', 'in' => 'Escolha uma opção válida para :attribute.', 'regex' => 'Informe uma referência de conexão válida.', 'uuid' => 'A identificação desta solicitação é inválida.', 'not_regex' => ':attribute contém caracteres não permitidos.'], ['name' => 'o nome da conexão', 'agent' => 'o agente de execução', 'driver' => 'o tipo de banco', 'values' => 'os dados da conexão', 'resource' => 'a conexão'])->validate();
        $definition = $this->definitions()[$base['driver']];
        $rules = ['values' => ['required', 'array:'.implode(',', array_column($definition['fields'], 'key'))]];
        $attributes = [];
        foreach ($definition['fields'] as $field) {
            $key = 'values.'.$field['key'];
            $attributes[$key] = $field['label'];
            $required = ($field['required'] ?? false) && ! array_key_exists('default', $field);
            $rules[$key] = [$required ? 'required' : 'nullable'];
            if ($field['kind'] === 'number') {
                $rules[$key] = [...$rules[$key], 'integer', 'min:1', 'max:65535'];
            } elseif ($field['kind'] === 'checkbox') {
                $rules[$key][] = 'boolean';
            } elseif ($field['kind'] === 'select') {
                $rules[$key][] = Rule::in(array_column($field['options'], 'value'));
            } else {
                $rules[$key] = [...$rules[$key], 'string', 'max:'.(($field['secret'] ?? false) ? ($field['key'] === 'JsonKey' ? 20000 : 8000) : 500)];
                if (! ($field['secret'] ?? false)) {
                    $rules[$key][] = 'not_regex:/[\x00-\x1F\x7F]/';
                }
            }
        }
        Validator::make(['values' => $base['values']], $rules, ['required' => 'Informe :attribute.', 'array' => 'Os campos da conexão não correspondem ao tipo de banco escolhido.', 'integer' => ':attribute deve ser um número inteiro.', 'boolean' => 'Escolha uma opção válida para :attribute.', 'in' => 'Escolha uma opção válida para :attribute.', 'not_regex' => ':attribute contém caracteres não permitidos.'], $attributes)->validate();
        $values = [];
        foreach ($definition['fields'] as $field) {
            $value = $base['values'][$field['key']] ?? $field['default'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $values[$field['key']] = match ($field['kind']) {
                'number' => (int) $value,
                'checkbox' => (bool) $value,
                default => ($field['secret'] ?? false) ? $value : trim((string) $value),
            };
        }
        if ($base['driver'] === 'bigquery') {
            $key = json_decode($values['JsonKey'], true);
            if (! is_array($key) || ($key['type'] ?? '') !== 'service_account' || ! filter_var($key['client_email'] ?? '', FILTER_VALIDATE_EMAIL) || ! is_string($key['private_key'] ?? null) || ! str_contains($key['private_key'], '-----BEGIN PRIVATE KEY-----')) {
                throw ValidationException::withMessages(['values.JsonKey' => 'Informe uma chave JSON válida de conta de serviço do Google Cloud.']);
            }
        }
        if ($base['driver'] === 'odbc' && ! preg_match('/(?:^|;)\s*[^;={}]+\s*=/', $values['odbcconnection'])) {
            throw ValidationException::withMessages(['values.odbcconnection' => 'Informe os parâmetros da conexão no formato chave=valor.']);
        }
        $config = array_diff_key($values, ['password' => true, 'validateCertificate' => true]);
        if ($base['driver'] === 'sqlserver') {
            $config['trustServerCertificate'] = ($values['validateCertificate'] ?? true) ? 'false' : 'true';
        }
        if ($base['driver'] === 'firebird') {
            $config['dialect'] = (int) $config['dialect'];
        }
        if ($base['driver'] === 'odbc') {
            preg_match_all('/(?:^|;)\s*([^;={}]+?)\s*=/', $values['odbcconnection'], $keys);
            $config['secureFields'] = array_values(array_unique(array_map(fn (string $key): string => strtolower(trim($key)), $keys[1])));
        }

        return ['action' => $base['action'], 'request_id' => strtolower($base['request_id']), 'resource' => $base['action'] === 'update_credential' ? $base['resource'] : null, 'driver' => $base['driver'], 'name' => trim($base['name']), 'agent' => (int) $base['agent'], 'native_type' => $definition['native_type'], 'config' => $config, 'password' => $values['password'] ?? null];
    }

    /** @return array<string, mixed> */
    public function publicConfiguration(array $normalized): array
    {
        $result = [];
        foreach ($this->definitions()[$normalized['driver']]['fields'] as $field) {
            if ($field['secret'] ?? false) {
                continue;
            }
            if ($field['key'] === 'validateCertificate') {
                $result[$field['label']] = ($normalized['config']['trustServerCertificate'] ?? 'false') === 'false' ? 'Sim' : 'Não';
            } elseif (array_key_exists($field['key'], $normalized['config'])) {
                $result[$field['label']] = $normalized['config'][$field['key']];
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function editableValues(string $driver, array $body): array
    {
        $values = [];
        $config = is_array($body['config'] ?? null) ? $body['config'] : [];
        foreach ($this->definitions()[$driver]['fields'] as $field) {
            if ($field['secret'] ?? false) {
                $values[$field['key']] = '';
            } elseif ($field['key'] === 'validateCertificate') {
                $values[$field['key']] = strtolower((string) ($config['trustServerCertificate'] ?? 'false')) !== 'true';
            } elseif ($driver === 'postgres' && $field['key'] === 'host') {
                $values[$field['key']] = $config['host'] ?? $config['hostname'] ?? '';
            } elseif ($driver === 'postgres' && $field['key'] === 'port' && ! isset($config['port']) && preg_match('/\bport\s*=\s*([0-9]+)/i', (string) ($config['extra'] ?? ''), $port)) {
                $values[$field['key']] = (int) $port[1];
            } else {
                $values[$field['key']] = $config[$field['key']] ?? $field['default'] ?? '';
            }
        }

        return $values;
    }
}
