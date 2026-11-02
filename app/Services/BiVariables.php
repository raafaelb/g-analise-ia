<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiVariables
{
    public const ACTIONS = ['create_variable', 'update_variable'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private BiVariableDrafts $drafts, private TenantPolicy $policy) {}

    public function options(User $user, string $tenant, ?string $id = null): array
    {
        [$connection, $environment] = $this->access($user, $tenant);
        $key = $id === null ? null : $this->decode($id);
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $key, $generation): array {
            [, , $body] = $this->document($connection, $context);
            $variable = $key === null ? null : $this->find($body, $key);
            abort_if($key !== null && $variable === null, 404, 'Esta variável não está disponível neste tenant.');
            $agents = [];
            $agentsMessage = null;
            try {
                $agents = $this->agents($connection, $context);
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $connection->fresh()?->status !== 'connected') {
                    throw $e;
                }
                $agentsMessage = 'Não foi possível consultar os agentes. O valor padrão e os valores específicos já cadastrados podem ser preservados; novas definições por agente exigem essa consulta.';
            }
            $this->assertGeneration($connection, $generation);

            return ['version' => $this->documentDigest($body, (string) $context->tenant_id), 'keys' => array_map(fn (stdClass $item): string => $item->key, $body->items), 'variable' => $variable === null ? null : $this->metadata($variable), 'agents' => $agents, 'agents_message' => $agentsMessage];
        });
    }

    public function prepare(User $user, string $tenant, array $input): array
    {
        [$connection, $environment] = $this->access($user, $tenant);
        $data = $this->normalize($input);
        $this->policy->assertCommandAllowed($tenant, ['push', 'variables'], $connection);
        $requestHash = $this->drafts->digest([$tenant, $data], $user->id.':'.$data['request_id']);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $connection, $environment, $tenant, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outros valores. Reabra a configuração para preparar outra revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                $revision = $this->withContext($connection, $environment, fn (Project $context): array => $this->revision($connection, $context, $data));
                $arguments = ['action' => $data['action'], 'resource' => 'variables', 'key' => $data['key']];
                $approval = DB::transaction(function () use ($user, $connection, $tenant, $data, $arguments, $revision, $requestHash): Approval {
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => $data['action'] === 'create_variable' ? 'Adicionar variável do tenant' : 'Editar variável do tenant', 'description' => 'Confira o nome, a proteção e os valores por agente. Os valores privados não aparecem nesta revisão e não são enviados à IA.', 'payload' => ['origin' => 'manual', 'action' => $arguments, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $arguments, $revision), 'expires_at' => now()->addMinutes(12)]);
                    $this->drafts->store($approval, $data);
                    Audit::record($user->id, 'bi.variable.review_created', 'Variável preparada para revisão; valores protegidos.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'key' => $data['key'], 'action' => $data['action']]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['variable' => 'Esta revisão ainda está sendo preparada. Aguarde e consulte a mesma solicitação.']);
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        [$arguments, $revision, $tenant] = $this->review($approval, $user);
        $this->assertExecution($approval, $user);
        [$connection, $environment] = $this->access($user, $tenant);
        $this->assertGeneration($connection, $revision['connection_generation']);
        $data = $this->drafts->read($approval);
        if (! hash_equals($approval->payload['request_hash'], $this->drafts->digest([$tenant, $data], $user->id.':'.$data['request_id'])) || $arguments !== ['action' => $data['action'], 'resource' => 'variables', 'key' => $data['key']]) {
            throw new RuntimeException('Os valores privados não correspondem à operação autorizada.');
        }
        $this->policy->assertCommandAllowed($tenant, ['push', 'variables'], $connection);
        try {
            return Cache::lock('bi-variables-write:'.$tenant, 630)->block(3, function () use ($user, $connection, $environment, $data, $revision, $approval, $tenant): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($user, $connection, $environment, $data, $revision, $approval, $tenant): array {
                    if ($this->revision($connection, $context, $data, false) !== $revision) {
                        throw new RuntimeException('As variáveis ou os agentes mudaram após a revisão. Reabra a configuração atual.');
                    }
                    [$path, $header, $body] = $this->workingDocument($context);
                    $updated = $this->apply($body, $data);
                    $this->write($path, $header, $updated);
                    $this->validate($connection, $context);
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $this->assertExecution($approval, $user);
                    $approval->update(['payload' => [...$approval->payload, 'variable_attempt' => ['started_at' => now()->toIso8601String(), 'key' => $data['key']]]]);
                    try {
                        $native = $this->lumo->run($connection, ['push', 'variables'], $context, 90);
                        if (($native['exit_code'] ?? null) !== 0) {
                            throw new RuntimeException('O envio da configuração não foi confirmado.');
                        }
                        $approval->update(['payload' => [...$approval->payload, 'variable_attempt' => [...$approval->payload['variable_attempt'], 'accepted_at' => now()->toIso8601String(), 'exit_code' => 0]]]);
                        $result = $this->withContext($connection, $environment, fn (Project $verification): array => $this->verify($connection, $verification, $approval));
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        Audit::record($user->id, 'bi.variable.configured', 'Configuração de variável conferida; valores protegidos.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'key' => $data['key'], 'secret_values_read_back' => false]);

                        return $result;
                    } catch (Throwable $e) {
                        Audit::record($user->id, 'bi.variable.confirmation_pending', 'A variável precisa de conferência; a alteração não será reenviada automaticamente.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'key' => $data['key'], 'exception_type' => get_class($e), 'exception_code' => (string) $e->getCode()]);
                        throw new RuntimeException('Não foi possível confirmar integralmente esta alteração. Use Conferir resultado para consultar o BI, sem reenviar os valores.');
                    } finally {
                        $this->catalog->invalidate($tenant, $connection);
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['variable' => 'Outra alteração de variáveis está em andamento neste tenant. Aguarde e confira o resultado.']);
        }
    }

    public function reconcile(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->findOrFail($id);
        [, $revision, $tenant] = $this->review($approval, $user);
        if ($approval->status === 'completed') {
            return ['approval' => $approval, 'project' => null];
        }
        abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true) && isset($approval->payload['variable_attempt']['started_at']) && $approval->decided_at !== null, 409, 'Esta revisão ainda não tem uma tentativa encerrada para conferir.');
        [$connection, $environment] = $this->access($user, $tenant);
        $generation = $connection->metadata['session_generation'] ?? null;
        try {
            return Cache::lock('bi-operation:'.$approval->id, 630)->block(3, function () use ($approval, $user, $connection, $environment, $generation, $tenant): array {
                $approval->refresh();
                if ($approval->status === 'completed') {
                    return ['approval' => $approval, 'project' => null];
                }
                abort_unless(in_array($approval->status, ['failed', 'cancelled', 'expired'], true), 409, 'O estado desta operação mudou. Consulte o resultado atual.');
                $result = $this->withContext($connection, $environment, fn (Project $context): array => $this->verify($connection, $context, $approval));
                $this->assertGeneration($connection, $generation);
                DB::transaction(function () use ($approval, $user, $result): void {
                    $current = Approval::where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->id);
                    $this->review($current, $user);
                    abort_unless(in_array($current->status, ['failed', 'cancelled', 'expired'], true), 409, 'O estado desta operação mudou. Consulte o resultado atual.');
                    $current->update(['status' => 'completed', 'payload' => [...$current->payload, 'execution' => ['status' => 'completed', 'finished_at' => now()->toIso8601String(), 'result' => $result], 'reconciliation' => ['remote_read_only' => true, 'previous_status' => $current->status, 'checked_at' => now()->toIso8601String()]]]);
                });
                $this->catalog->invalidate($tenant, $connection);
                Audit::record($user->id, 'bi.variable.reconciled', 'Configuração da variável conferida sem novo envio.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant]);

                return ['approval' => $approval->fresh(), 'project' => null];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['variable' => 'A operação ainda está em uso. Aguarde antes de conferir novamente.']);
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $this->drafts->forget($approval);
            $context = new Project(['user_id' => $approval->user_id]);
            $context->id = 'variables-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function access(User $user, string $tenant): array
    {
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite configurar variáveis.');

        return $this->catalog->access($user, $tenant);
    }

    private function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['action', 'request_id', 'version', 'key', 'value', 'is_secret', 'description', 'agent_overrides']) !== [] || strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 131072) {
            throw ValidationException::withMessages(['variable' => 'Use somente os campos desta configuração, com até 128 KB por revisão.']);
        }
        if (is_string($input['key'] ?? null)) {
            $input['key'] = trim($input['key']);
        }
        $data = Validator::make($input, ['action' => ['required', Rule::in(self::ACTIONS)], 'request_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'key' => ['required', 'string', 'max:256', 'regex:/^[A-Z_][A-Z0-9_]*$/D'], 'is_secret' => ['sometimes', 'boolean'], 'value' => ['sometimes', 'nullable', 'string', 'max:65536', 'not_regex:/\x00/'], 'description' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], 'agent_overrides' => ['sometimes', 'array', 'list', 'max:32'], 'agent_overrides.*' => ['array:agent_id,value'], 'agent_overrides.*.agent_id' => ['required', 'integer', 'min:1', 'distinct'], 'agent_overrides.*.value' => ['sometimes', 'nullable', 'string', 'max:65536', 'not_regex:/\x00/']])->validate();
        if (array_key_exists('value', $data)) {
            $data['value'] = (string) ($data['value'] ?? '');
        }
        if (array_key_exists('description', $data)) {
            $text = trim((string) ($data['description'] ?? ''));
            $data['description'] = $text === '' ? null : $text;
        }
        if (array_key_exists('is_secret', $data)) {
            $data['is_secret'] = (bool) $data['is_secret'];
        }
        foreach ($data['agent_overrides'] ?? [] as $index => $override) {
            if (is_bool($override['agent_id'])) {
                throw ValidationException::withMessages(['agent_overrides' => 'Escolha um identificador numérico de agente.']);
            }
            $data['agent_overrides'][$index]['agent_id'] = (int) $override['agent_id'];
            if (array_key_exists('value', $override)) {
                $data['agent_overrides'][$index]['value'] = (string) ($override['value'] ?? '');
            }
        }
        if ($data['action'] === 'create_variable' && (! array_key_exists('value', $data) || ! array_key_exists('is_secret', $data))) {
            throw ValidationException::withMessages(['variable' => 'Informe o valor e a proteção da nova variável.']);
        }
        $data['request_id'] = strtolower($data['request_id']);

        return $data;
    }

    private function revision(Connection $connection, Project $context, array $data, bool $validate = true): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        [$path, $header, $body] = $this->document($connection, $context);
        abort_unless(hash_equals($data['version'], $this->documentDigest($body, (string) $context->tenant_id)), 409, 'As variáveis mudaram. Reabra a configuração para conferir a versão atual.');
        $previous = $this->find($body, $data['key']);
        abort_if($data['action'] === 'create_variable' && $previous !== null, 409, 'Já existe uma variável com este nome. Abra a configuração dela para editar.');
        abort_if($data['action'] === 'update_variable' && $previous === null, 404, 'Esta variável não está disponível neste tenant.');
        $updated = $this->apply($body, $data);
        $proposed = $this->find($updated, $data['key']);
        $secretSubmitted = $proposed->is_secret && (property_exists($proposed, 'value') || array_any($proposed->agent_overrides ?? [], fn (stdClass $override): bool => property_exists($override, 'value')));
        if (! $secretSubmitted && hash_equals($this->documentDigest($body, (string) $context->tenant_id), $this->documentDigest($updated, (string) $context->tenant_id))) {
            throw ValidationException::withMessages(['variable' => 'Altere a configuração antes de preparar uma revisão.']);
        }
        $agentNames = [];
        if (isset($data['agent_overrides'])) {
            $requiresAgents = array_filter($data['agent_overrides'], fn (array $override): bool => array_key_exists('value', $override) || ! array_any($previous->agent_overrides ?? [], fn (stdClass $old): bool => $old->tokenId === $override['agent_id']));
            if ($requiresAgents !== []) {
                $agentNames = array_column($this->agents($connection, $context), 'name', 'id');
                foreach ($requiresAgents as $override) {
                    if (! array_key_exists($override['agent_id'], $agentNames)) {
                        throw ValidationException::withMessages(['agent_overrides' => 'Escolha somente agentes disponíveis neste tenant para definir novos valores.']);
                    }
                }
            }
        }
        if ($validate) {
            try {
                $this->write($path, $header, $updated);
                $this->validate($connection, $context);
            } finally {
                $this->write($path, $header, $body);
            }
        }
        $this->assertGeneration($connection, $generation);
        $valueUpdate = array_key_exists('value', $data);

        return ['tenant_id' => (string) $context->tenant_id, 'resource' => 'variables', 'connection_generation' => $generation, 'source_digest' => $this->documentDigest($body, (string) $context->tenant_id), 'expected_digest' => $this->documentDigest($updated, (string) $context->tenant_id), 'variable' => ['key' => $data['key'], 'previous' => $previous === null ? null : $this->metadata($previous), 'proposed' => $this->metadata($proposed), 'value_update_requested' => $valueUpdate, 'value_action' => ! $valueUpdate ? 'Manter valor padrão atual' : ($data['value'] === '' ? 'Definir valor padrão vazio' : 'Definir novo valor padrão protegido'), 'secret_values_submitted' => $secretSubmitted, 'overrides' => array_map(fn (array $override): array => ['agent_id' => $override['agent_id'], 'agent_name' => $agentNames[$override['agent_id']] ?? 'Agente #'.$override['agent_id'], 'value_action' => ! array_key_exists('value', $override) ? 'Manter valor atual' : ($override['value'] === '' ? 'Definir valor vazio' : 'Definir novo valor protegido')], $data['agent_overrides'] ?? []), 'overrides_changed' => array_key_exists('agent_overrides', $data)], 'impact' => 'Esta configuração vale no tenant e pode afetar ETLs, conexões e outros recursos que usam a referência {{'.$data['key'].'}}. O valor padrão atende os agentes sem definição própria. A alteração não executa cargas, não reinicia agentes nem publica recursos. Os valores das demais variáveis serão preservados.'];
    }

    private function apply(stdClass $source, array $data): stdClass
    {
        $body = clone $source;
        $body->items = array_map(function (stdClass $sourceItem): stdClass {
            $item = clone $sourceItem;
            if (property_exists($item, 'agent_overrides')) {
                $item->agent_overrides = array_map(fn (stdClass $override): stdClass => clone $override, $item->agent_overrides);
            }
            if ($item->is_secret) {
                unset($item->value);
                foreach ($item->agent_overrides ?? [] as $override) {
                    unset($override->value);
                }
            }

            return $item;
        }, $source->items);
        $item = $this->find($body, $data['key']);
        $previous = $this->find($source, $data['key']);
        if ($item === null) {
            $item = (object) ['key' => $data['key'], 'is_secret' => $data['is_secret'], 'value' => $data['value'], 'description' => $data['description'] ?? null, 'agent_overrides' => []];
            $body->items[] = $item;
        }
        foreach (['is_secret', 'description', 'value'] as $field) {
            if (array_key_exists($field, $data)) {
                $item->{$field} = $data[$field];
            }
        }
        if (array_key_exists('agent_overrides', $data)) {
            $before = $item->agent_overrides ?? [];
            $item->agent_overrides = [];
            foreach ($data['agent_overrides'] as $override) {
                $match = array_find($before, fn (stdClass $old): bool => $old->tokenId === $override['agent_id']);
                if ($match === null && ! array_key_exists('value', $override)) {
                    throw ValidationException::withMessages(['agent_overrides' => 'Informe o valor de cada nova definição por agente.']);
                }
                $entry = $match === null ? (object) ['tokenId' => $override['agent_id']] : clone $match;
                if (array_key_exists('value', $override)) {
                    $entry->value = $override['value'];
                }
                $item->agent_overrides[] = $entry;
            }
        }
        if ($previous?->is_secret === true && ! $item->is_secret && (! array_key_exists('value', $data) || array_any($item->agent_overrides ?? [], fn (stdClass $override): bool => ! property_exists($override, 'value')))) {
            throw ValidationException::withMessages(['variable' => 'Para remover a proteção de segredo, informe um novo valor padrão e novos valores para todos os agentes mantidos, ou remova suas definições. O segredo anterior não será revelado.']);
        }

        return $body;
    }

    private function verify(Connection $connection, Project $context, Approval $approval): array
    {
        $revision = $approval->payload['revision'];
        $variable = $revision['variable'];
        if ($variable['secret_values_submitted'] && ! isset($approval->payload['variable_attempt']['accepted_at'])) {
            throw new RuntimeException('O BI não devolve segredos para leitura, e o recebimento dos novos valores não foi confirmado. Confira a configuração atual; para definir o segredo novamente, prepare outra revisão explícita.');
        }
        [, , $body] = $this->document($connection, $context);
        $item = $this->find($body, $variable['key']);
        $difference = $this->lumo->run($connection, ['diff', 'variables', '--include-remote'], $context, 60)['data'] ?? null;
        if ($item === null || ! hash_equals($revision['expected_digest'], $this->documentDigest($body, (string) $context->tenant_id)) || ! is_array($difference) || ($difference['workingMissing'] ?? null) !== false || ($difference['workingVsState']['equal'] ?? null) !== true || ($difference['workingVsServer']['equal'] ?? null) !== true) {
            throw new RuntimeException('A configuração revisada ainda não foi confirmada integralmente. Consulte a variável atual antes de outra alteração.');
        }

        return ['resource' => 'variables', 'variable_key' => $item->key, 'variable_id' => bin2hex($item->key), 'configuration_verified' => true, 'values_verified' => ! $item->is_secret, 'secret_values_write_acknowledged' => $variable['secret_values_submitted'], 'secret_values_read_back' => false, 'message' => $variable['secret_values_submitted'] ? 'Configuração conferida. O BI aceitou os segredos enviados e não os devolve para leitura.' : ($variable['value_update_requested'] || $variable['overrides_changed'] ? 'Configuração da variável salva e conferida no BI.' : 'Configuração conferida. Os valores atuais foram preservados.')];
    }

    private function document(Connection $connection, Project $context): array
    {
        $names = array_column($this->catalog->rows($connection, $context, 'variables'), 'nome');
        $this->lumo->run($connection, ['pull', 'variables'], $context, 90);
        $status = $this->lumo->run($connection, ['status', '--filter', 'clean'], $context, 60)['data'] ?? null;
        if (! is_array($status) || (string) ($status['tenantId'] ?? '') !== (string) $context->tenant_id || ! is_array($status['clean'] ?? null) || ! in_array('variables:1  (variables/tenant.yaml)', $status['clean'], true)) {
            throw new RuntimeException('A configuração editável das variáveis não foi confirmada. Confira sua permissão de gestão de variáveis no BI; referências mascaradas permitem somente consulta.');
        }
        $document = $this->workingDocument($context);
        $received = array_map(fn (stdClass $item): string => $item->key, $document[2]->items);
        sort($names);
        sort($received);
        if ($names !== $received) {
            throw new RuntimeException('A configuração de variáveis não corresponde integralmente ao inventário autorizado. Reabra a consulta.');
        }

        return $document;
    }

    private function workingDocument(Project $context): array
    {
        $root = app(WorkspaceFiles::class)->path($context);
        $path = $root.'/variables/tenant.yaml';
        if (! is_file($path) || is_link($root.'/variables') || is_link($path) || filesize($path) > 2000000) {
            throw new RuntimeException('Não foi recebido um documento editável válido de variáveis.');
        }
        try {
            $parts = preg_split('/^---\s*$/m', File::get($path), 2);
            $header = count($parts) === 2 ? Yaml::parse($parts[0]) : null;
            $body = count($parts) === 2 ? Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP) : null;
        } catch (Throwable) {
            throw new RuntimeException('O documento de variáveis chegou em formato inválido. Nenhum valor foi exibido.');
        }
        if (! is_array($header) || ($header['kind'] ?? '') !== 'variables' || ($header['lumo'] ?? '') !== 'v2' || array_key_exists('id', $header) || ! (is_int($header['tenantId'] ?? null) || is_string($header['tenantId'] ?? null)) || (string) $header['tenantId'] !== (string) $context->tenant_id || ! $body instanceof stdClass || ! is_array($body->items ?? null) || ! array_is_list($body->items)) {
            throw new RuntimeException('A identidade ou a estrutura das variáveis não foi confirmada neste tenant.');
        }
        $keys = [];
        foreach ($body->items as $item) {
            if (! $item instanceof stdClass || ! is_string($item->key ?? null) || ! preg_match('/^[A-Z_][A-Z0-9_]*$/D', $item->key) || isset($keys[$item->key]) || ! is_bool($item->is_secret ?? null) || (property_exists($item, 'value') && ! is_string($item->value) && ! ($item->is_secret && $item->value === null)) || (isset($item->description) && ! is_string($item->description)) || (property_exists($item, 'agent_overrides') && (! is_array($item->agent_overrides) || ! array_is_list($item->agent_overrides)))) {
                throw new RuntimeException('A estrutura de uma variável não foi reconhecida. Reabra a configuração do BI.');
            }
            $keys[$item->key] = true;
            $agents = [];
            foreach ($item->agent_overrides ?? [] as $override) {
                if (! $override instanceof stdClass || ! is_int($override->tokenId ?? null) || $override->tokenId < 1 || isset($agents[$override->tokenId]) || (property_exists($override, 'value') && ! is_string($override->value) && ! ($item->is_secret && $override->value === null))) {
                    throw new RuntimeException('Uma definição por agente não foi reconhecida. Reabra a configuração do BI.');
                }
                $agents[$override->tokenId] = true;
            }
        }

        return [$path, $header, $body];
    }

    private function metadata(stdClass $item): array
    {
        return ['id' => bin2hex($item->key), 'key' => $item->key, 'reference' => '{{'.$item->key.'}}', 'is_secret' => $item->is_secret, 'description' => $item->description ?? null, 'agent_overrides' => array_map(fn (stdClass $override): array => ['agent_id' => $override->tokenId], $item->agent_overrides ?? [])];
    }

    private function documentDigest(stdClass $source, string $tenant): string
    {
        $body = clone $source;
        $body->items = array_map(function (stdClass $original): stdClass {
            $item = clone $original;
            $item->description ??= null;
            $item->agent_overrides = array_map(fn (stdClass $override): stdClass => clone $override, $item->agent_overrides ?? []);
            if ($item->is_secret) {
                unset($item->value);
                foreach ($item->agent_overrides as $override) {
                    unset($override->value);
                }
            }
            usort($item->agent_overrides, fn (stdClass $a, stdClass $b): int => $a->tokenId <=> $b->tokenId);

            return $item;
        }, $source->items);
        usort($body->items, fn (stdClass $a, stdClass $b): int => strcmp($a->key, $b->key));

        return $this->drafts->digest($body, 'variables:'.$tenant);
    }

    private function find(stdClass $body, string $key): ?stdClass
    {
        return array_find($body->items, fn (stdClass $item): bool => $item->key === $key);
    }

    private function agents(Connection $connection, Project $context): array
    {
        return array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) ($row['nome'] ?? $row['name'] ?? 'Agente #'.$row['id'])], $this->catalog->rows($connection, $context, 'agent'));
    }

    private function decode(string $id): string
    {
        abort_unless(strlen($id) <= 512 && strlen($id) % 2 === 0 && ctype_xdigit($id), 422, 'Identificador de variável inválido.');
        $key = hex2bin($id);
        abort_unless(is_string($key) && preg_match('/^[A-Z_][A-Z0-9_]*$/D', $key), 422, 'Nome de variável inválido.');

        return $key;
    }

    private function write(string $path, array $header, stdClass $body): void
    {
        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($body, 40, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), 0600);
    }

    private function validate(Connection $connection, Project $context): void
    {
        [$path] = $this->workingDocument($context);
        $result = $this->lumo->run($connection, ['lint', $path], $context, 90, true);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (($result['exit_code'] ?? null) !== 0 || ($data['errorCount'] ?? 0) !== 0 || ($data['valid'] ?? true) === false) {
            throw new RuntimeException('A configuração de variáveis não passou na validação do BI. Nenhum valor será enviado.');
        }
    }

    private function review(Approval $approval, User $user): array
    {
        $arguments = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($arguments['action'] ?? '', self::ACTIONS, true) || ($arguments['resource'] ?? '') !== 'variables' || ($arguments['key'] ?? '') !== ($revision['variable']['key'] ?? null) || ! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $arguments, $revision))) {
            throw new RuntimeException('Esta revisão não corresponde à configuração autorizada de variável.');
        }

        return [$arguments, $revision, $tenant];
    }

    private function assertExecution(Approval $approval, User $user): void
    {
        $approval->refresh();
        $this->review($approval, $user);
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || isset($approval->payload['variable_attempt']['started_at'])) {
            throw new RuntimeException('Esta revisão não permite outro envio de valores. Confira o resultado ou prepare outra revisão.');
        }
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou durante a operação. Confira o resultado antes de continuar.');
        }
    }

    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Configuração de variáveis']);
        $context->id = $approval ? 'variables-operation-'.$approval->id : 'variables-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function fingerprint(int $user, string $tenant, array $arguments, array $revision): string
    {
        return $this->drafts->digest([$user, $tenant, $arguments, $revision], 'variable-review-v1');
    }
}
