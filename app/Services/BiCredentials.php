<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiCredentials
{
    public const ACTIONS = ['create_credential', 'update_credential'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private BiCredentialTypes $types, private BiCredentialDrafts $drafts, private TenantPolicy $policy) {}

    /** @return array<string, mixed> */
    public function options(User $user, string $tenant): array
    {
        [$connection, $item] = $this->access($user, $tenant);
        $context = $this->catalog->context($connection, $item);
        $this->lumo->initialize($connection, $context, true);

        return ['types' => $this->types->choices(), 'agents' => $this->agents($connection, $context), 'tenant' => $item];
    }

    /** @return array<string, mixed> */
    public function describe(User $user, string $tenant, string $id): array
    {
        [$connection, $item] = $this->access($user, $tenant);

        return $this->withContext($connection, $item, function (Project $context) use ($connection, $id): array {
            $ref = 'credential:'.$id;
            $this->assertListed($connection, $context, $ref);
            [, $body] = $this->document($connection, $context, $ref);
            $driver = $this->types->driver((string) ($body['type'] ?? ''));
            $rawSqlServer = $driver === 'sqlserver' && filter_var($body['config']['useconnectionstring'] ?? false, FILTER_VALIDATE_BOOL);

            return ['resource' => $ref, 'name' => $body['nome'] ?? '', 'driver' => $driver, 'native_type' => $body['type'] ?? null, 'agent_id' => $this->agentId($body), 'editable' => $driver !== null && ! $rawSqlServer, 'values' => $driver !== null && ! $rawSqlServer ? $this->types->editableValues($driver, $body) : [], 'message' => $rawSqlServer ? 'Esta conexão usa o formato avançado de string SQL Server. O teste e a consulta de estrutura continuam disponíveis; a edição deste formato precisa de um formulário específico.' : ($driver === null ? 'Este tipo pode ser consultado e testado. O formulário de configuração ainda não está disponível.' : null)];
        });
    }

    /** @return array{approval: Approval, project: null, reused: bool} */
    public function prepare(User $user, string $tenant, array $input): array
    {
        [$connection, $item] = $this->access($user, $tenant);
        $data = $this->types->normalize($input);
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_credential' ? 'new' : 'push', $data['resource'] ?? 'credential'], $connection);
        $requestHash = $this->drafts->digest([$tenant, $data], $user->id.':'.$data['request_id']);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $connection, $item, $tenant, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outros dados. Prepare uma nova revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                $revision = $this->withContext($connection, $item, fn (Project $context): array => $this->revision($user, $connection, $context, $data));
                $arguments = ['action' => $data['action'], 'resource' => $data['resource'], 'name' => $data['name'], 'driver' => $data['driver'], 'agent' => $data['agent']];
                $approval = DB::transaction(function () use ($user, $tenant, $data, $arguments, $revision, $requestHash): Approval {
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => $data['action'] === 'create_credential' ? 'Adicionar conexão de dados' : 'Atualizar conexão de dados', 'description' => 'Confira o tenant, o agente e a configuração. Os campos protegidos não aparecem nesta revisão e não são enviados à IA.', 'payload' => ['origin' => 'manual', 'action' => $arguments, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $arguments, $revision), 'expires_at' => now()->addMinutes(12)]);
                    $this->drafts->store($approval, $data);
                    Audit::record($user->id, 'bi.credential.review_created', 'Configuração de conexão preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'action' => $data['action']]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['operation' => 'Esta revisão está sendo preparada. Aguarde e tente novamente com a mesma solicitação.']);
        }
    }

    /** @return array<string, mixed> */
    public function executeApproved(Approval $approval): array
    {
        $user = User::findOrFail($approval->user_id);
        if (! $approval->permitsUserAccess($user)) {
            throw new RuntimeException('A autorização pertence a uma versão anterior do seu acesso. Prepare uma nova revisão.');
        }
        $arguments = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if ($approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($arguments['action'] ?? '', self::ACTIONS, true)) {
            throw new RuntimeException('A revisão de conexão não está autorizada para execução.');
        }
        if (! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $arguments, $revision))) {
            throw new RuntimeException('A revisão foi alterada. Prepare outra autorização.');
        }
        [$connection, $item] = $this->access($user, $tenant);
        if (($connection->metadata['session_generation'] ?? null) !== ($revision['connection_generation'] ?? null)) {
            throw new RuntimeException('Sua conexão BI mudou. Prepare uma nova revisão de configuração.');
        }
        $data = $this->drafts->read($approval);
        $requestHash = $this->drafts->digest([$tenant, $data], $user->id.':'.$data['request_id']);
        if (! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $requestHash) || $arguments !== ['action' => $data['action'], 'resource' => $data['resource'], 'name' => $data['name'], 'driver' => $data['driver'], 'agent' => $data['agent']]) {
            throw new RuntimeException('Os dados privados não correspondem à operação autorizada.');
        }
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_credential' ? 'new' : 'push', $data['resource'] ?? 'credential'], $connection);
        $operation = function () use ($user, $connection, $item, $data, $approval, $revision): array {
            return $this->withContext($connection, $item, function (Project $context) use ($user, $connection, $data, $approval, $revision): array {
                $current = $this->revision($user, $connection, $context, $data);
                if ($current !== $revision) {
                    throw new RuntimeException('A conexão ou seu contexto mudou após a revisão. Prepare outra autorização.');
                }
                $ref = $data['resource'];
                $created = false;
                $accepted = false;
                try {
                    if ($data['action'] === 'create_credential') {
                        $approval->update(['payload' => [...$approval->payload, 'credential_attempt' => ['started_at' => now()->toIso8601String(), 'action' => $data['action']]]]);
                        $this->lumo->run($connection, ['new', 'credential', '--type', $data['driver'], '--name', $data['name']], $context, 90);
                        [$path, $body, $header] = $this->workingDocument($context);
                        $ref = 'credential:'.$header['id'];
                        $created = true;
                        $approval->update(['payload' => [...$approval->payload, 'credential_attempt' => [...$approval->payload['credential_attempt'], 'created_resource' => $ref]]]);
                        ManagedBiResource::create(['user_id' => $user->id, 'project_id' => null, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'origin' => 'manual_credential', 'status' => 'config_pending', 'receipt' => ['approval_id' => $approval->id, 'created_at' => now()->toIso8601String()]]);
                        if ($this->agentId($body) !== $data['agent']) {
                            $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
                            $this->lumo->run($connection, ['credential', 'transfer', $ref, '--to-agent', (string) $data['agent']], $context, 90);
                            [$path, $body, $header] = $this->document($connection, $context, $ref);
                            if ($this->agentId($body) !== $data['agent']) {
                                throw new RuntimeException('O agente selecionado não foi confirmado na nova conexão.');
                            }
                        }
                    } else {
                        [$path, $body, $header] = $this->workingDocument($context, $ref);
                    }
                    if (! $created && $this->drafts->digest($body, 'credential:'.$context->tenant_id) !== $revision['credential']['source_digest']) {
                        throw new RuntimeException('A conexão mudou durante a preparação. Nenhum envio será realizado.');
                    }
                    $body['nome'] = $data['name'];
                    $body['agentId'] = $data['agent'];
                    $body['config'] = array_replace(is_array($body['config'] ?? null) ? $body['config'] : [], $data['config']);
                    if ($data['driver'] === 'postgres') {
                        $body['config']['hostname'] = $data['config']['host'];
                        if (is_string($body['config']['extra'] ?? null)) {
                            $body['config']['extra'] = preg_replace('/(\bport\s*=\s*)[0-9]+/i', '${1}'.$data['config']['port'], $body['config']['extra']);
                        }
                    }
                    unset($body['password']);
                    if ($data['password'] !== null && $data['password'] !== '') {
                        $body['password'] = $data['password'];
                    }
                    File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($body, 40, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), 0600);
                    $this->lumo->run($connection, ['lint', $path], $context, 60);
                    $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
                    $this->lumo->run($connection, ['push', $ref], $context, 90);
                    $accepted = true;
                    [, $saved] = $this->document($connection, $context, $ref);
                    $this->assertConfiguration($saved, $data);
                    $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
                    ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $context->tenant_id)->where('resource', $ref)->update(['status' => 'configured']);
                    Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$context->tenant_id);
                    Audit::record($user->id, 'bi.credential.configured', 'Configuração de conexão salva e conferida; teste de acesso ainda necessário.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'created' => $created]);

                    return ['resource' => $ref, 'confirmed_name' => $saved['nome'], 'configuration_verified' => true, 'connection_tested' => false, 'created' => $created, 'message' => 'Configuração salva no BI. Use Testar conexão para conferir o acesso pelo agente.'];
                } catch (Throwable $e) {
                    if ($ref) {
                        Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$context->tenant_id);
                    }
                    Audit::record($user->id, 'bi.credential.configuration_pending', 'A configuração da conexão requer conferência; a criação não será repetida automaticamente.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'created' => $created, 'native_accepted' => $accepted, 'exception_type' => get_class($e), 'exception_code' => (string) $e->getCode()]);
                    throw new RuntimeException($created ? 'A conexão '.$ref.' foi criada, mas a configuração ainda precisa ser conferida. Abra essa conexão para continuar; não crie outra cópia.' : ($accepted ? 'O BI recebeu a configuração, mas a conferência final ficou pendente. Atualize os detalhes antes de solicitar outra alteração.' : 'Não foi possível confirmar o envio desta configuração. Confira permissões, agente e diferenças no BI antes de preparar outra revisão.'));
                }
            }, $approval);
        };
        if ($data['resource'] !== null) {
            return Cache::lock('bi-credential:'.$tenant.':'.$data['resource'], 630)->block(3, $operation);
        }

        return $operation();
    }

    /** @return array<string, mixed> */
    public function inspect(User $user, string $tenant, string $id, string $action, ?string $schema = null, ?string $search = null, int $offset = 0): array
    {
        [$connection, $item] = $this->access($user, $tenant);
        abort_unless(in_array($action, ['test', 'schema', 'relationships'], true), 422);
        if ($schema !== null && ! preg_match('/^[\pL\pN_][\pL\pN_. -]{0,127}$/uD', $schema)) {
            throw ValidationException::withMessages(['schema' => 'Escolha um nome de schema válido.']);
        }
        if (($search !== null && ! preg_match('/^[\pL\pN_ .-]{1,100}$/uD', $search)) || $offset < 0 || $offset > 1000000 || (! in_array($action, ['schema', 'relationships'], true) && ($search !== null || $offset !== 0))) {
            throw ValidationException::withMessages(['search' => 'Use um termo de estrutura válido e uma posição de consulta permitida.']);
        }
        $this->policy->assertCommandAllowed($tenant, ['credential', $action === 'relationships' ? 'schema' : $action, 'credential:'.$id], $connection);

        return $this->withContext($connection, $item, function (Project $context) use ($connection, $id, $action, $schema, $search, $offset): array {
            $ref = 'credential:'.$id;
            $this->assertListed($connection, $context, $ref);
            $generation = $connection->metadata['session_generation'] ?? null;
            try {
                if ($action === 'relationships') {
                    [, $document] = $this->document($connection, $context, $ref);
                    $report = app(BiSourceRelationships::class)->inspect($connection, $context, $ref, $this->types->driver((string) ($document['type'] ?? '')), $schema, $search, $offset);
                    $this->assertGeneration($connection, $generation);
                    [$current] = $this->catalog->access(User::findOrFail($connection->user_id), (string) $context->tenant_id);
                    if ($current->id !== $connection->id || ($current->metadata['session_generation'] ?? null) !== $generation) {
                        throw new RuntimeException('O acesso mudou durante a consulta de relacionamentos.');
                    }
                    Audit::record($connection->user_id, 'bi.credential.relationships_read', 'Relacionamentos declarados consultados sem leitura de linhas de negócio.', null, ['tenant_id' => $context->tenant_id, 'resource' => $ref, 'verified' => $report['verified'], 'relationship_count' => count($report['relationships'] ?? []), 'truncated' => $report['truncated'] ?? null]);

                    return $report;
                }
                if ($action === 'schema' && ($search !== null || $offset > 0)) {
                    [, $document] = $this->document($connection, $context, $ref);
                    if ($this->types->driver((string) ($document['type'] ?? '')) !== 'postgres') {
                        return ['verified' => false, 'state' => 'unsupported', 'message' => 'A busca refinada e a paginação da estrutura estão disponíveis para PostgreSQL nesta versão. A consulta geral continua disponível para este conector.'];
                    }
                    $report = $this->postgresSchema($connection, $context, $ref, $schema, $search, $offset);
                    $this->assertGeneration($connection, $generation);
                    Audit::record($connection->user_id, 'bi.credential.schema_read', 'Consulta refinada da estrutura da fonte.', null, ['tenant_id' => $context->tenant_id, 'resource' => $ref, 'verified' => $report['verified'], 'method' => 'postgres_information_schema']);

                    return [...$report, 'method' => 'postgres_information_schema'];
                }
                $result = $this->lumo->run($connection, ['credential', $action, $ref, ...($action === 'schema' && $schema !== null ? ['--schema', $schema] : []), ...($action === 'schema' ? ['--timeout', '45s'] : [])], $context, 60);
                $data = $result['data'] ?? null;
                $this->assertGeneration($connection, $generation);
                if ($action === 'test') {
                    $verified = is_array($data) && ($data['ok'] ?? null) === true;
                    Audit::record($connection->user_id, 'bi.credential.tested', $verified ? 'Agente confirmou o teste de conectividade.' : 'O teste de conectividade não foi confirmado.', null, ['tenant_id' => $context->tenant_id, 'resource' => $ref, 'verified' => $verified]);

                    return ['verified' => $verified, 'state' => $verified ? 'connected' : 'unconfirmed', 'message' => $verified ? 'O agente conseguiu estabelecer a conexão com a fonte de dados.' : 'A resposta do BI não confirmou a conexão. Confira os dados e o agente selecionado.', 'checked_at' => now()->toIso8601String()];
                }

                $method = 'native';
                if (is_array($data) && ($data['Success'] ?? null) === false && is_string($data['Error'] ?? null) && str_contains($data['Error'], '42601') && str_contains(strtoupper($data['Error']), 'UNION')) {
                    [, $body] = $this->document($connection, $context, $ref);
                    if ($this->types->driver((string) ($body['type'] ?? '')) === 'postgres') {
                        $report = $this->postgresSchema($connection, $context, $ref, $schema);
                        $method = 'postgres_information_schema';
                    }
                }
                $report ??= $this->schema($data);
                $this->assertGeneration($connection, $generation);
                Audit::record($connection->user_id, 'bi.credential.schema_read', $report['verified'] ? 'Estrutura da fonte consultada pela conexão autorizada.' : 'A estrutura da fonte ainda não pôde ser confirmada.', null, ['tenant_id' => $context->tenant_id, 'resource' => $ref, 'verified' => $report['verified'], 'method' => $method]);

                return [...$report, 'method' => $method];
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $e instanceof HttpExceptionInterface || $connection->fresh()->status !== 'connected' || $e->getCode() === 6) {
                    throw $e;
                }
                $this->assertGeneration($connection, $generation);

                return ['verified' => false, 'state' => 'failed', 'message' => $action === 'test' ? 'O agente não confirmou o acesso. Confira endereço, porta, banco, usuário e senha, e se o agente está disponível.' : 'O agente não forneceu a estrutura da fonte. Confira o suporte do conector, as permissões e a disponibilidade do agente.', 'checked_at' => now()->toIso8601String()];
            }
        });
    }

    public function cleanup(Approval $approval): void
    {
        $this->drafts->forget($approval);
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'credential-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    /** @return array{Connection, array<string, mixed>} */
    private function access(User $user, string $tenant): array
    {
        abort_if(! User::find($user->id)?->canStartWork() || User::find($user->id)?->role === 'viewer', 403, 'Seu perfil permite somente leitura.');

        return $this->catalog->access($user, $tenant);
    }

    /** @return list<array{id: int, name: string}> */
    private function agents(Connection $connection, Project $context): array
    {
        return array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) ($row['nome'] ?? $row['name'] ?? 'Agente #'.$row['id'])], $this->catalog->rows($connection, $context, 'agent'));
    }

    private function assertListed(Connection $connection, Project $context, string $ref): void
    {
        if (! preg_match('/^credential:([1-9][0-9]*)$/D', $ref, $match)) {
            throw new RuntimeException('Referência de conexão inválida.');
        }
        abort_unless(collect($this->catalog->rows($connection, $context, 'credential'))->contains(fn (array $row): bool => (string) $row['id'] === $match[1]), 404, 'Esta conexão não está disponível no tenant para sua sessão.');
    }

    /** @return array<string, mixed> */
    private function revision(User $user, Connection $connection, Project $context, array $data): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $agent = collect($this->agents($connection, $context))->first(fn (array $row): bool => $row['id'] === $data['agent']);
        if (! $agent) {
            throw new RuntimeException('Escolha um agente disponível neste tenant para sua sessão.');
        }
        $sourceDigest = null;
        if ($data['action'] === 'update_credential') {
            $this->assertListed($connection, $context, $data['resource']);
            [, $source] = $this->document($connection, $context, $data['resource']);
            if ($this->types->driver((string) ($source['type'] ?? '')) !== $data['driver']) {
                throw new RuntimeException('O tipo da conexão mudou. Atualize os detalhes antes de editar.');
            }
            if ($data['driver'] === 'sqlserver' && filter_var($source['config']['useconnectionstring'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new RuntimeException('Esta conexão usa string SQL Server; o formulário estruturado não pode substituí-la.');
            }
            if ($this->agentId($source) !== $data['agent']) {
                throw new RuntimeException('A mudança de agente exige uma transferência específica. Esta edição mantém o agente atual.');
            }
            $sourceDigest = $this->drafts->digest($source, 'credential:'.$context->tenant_id);
        }
        $this->assertGeneration($connection, $generation);

        return ['tenant_id' => (string) $context->tenant_id, 'resource' => $data['resource'], 'connection_generation' => $generation, 'credential' => ['name' => $data['name'], 'type' => $this->types->definitions()[$data['driver']]['label'], 'agent' => $agent, 'configuration' => $this->types->publicConfiguration($data), 'protected_fields' => $data['driver'] === 'bigquery' ? ['Chave JSON: será enviada ao BI'] : ($data['driver'] === 'odbc' ? ['String ODBC: será enviada ao BI com campos protegidos'] : ['Senha: '.($data['password'] !== null ? 'será enviada ao BI' : ($data['action'] === 'update_credential' ? 'será mantida' : 'não informada'))]), 'source_digest' => $sourceDigest, 'impact' => $data['action'] === 'create_credential' ? 'Uma nova conexão será criada no tenant. Ela só poderá ser considerada operacional após um teste de acesso pelo agente.' : 'Os ETLs que usam esta conexão passarão a usar a configuração alterada. Nenhuma carga será iniciada por esta operação.']];
    }

    private function fingerprint(int $user, string $tenant, array $arguments, array $revision): string
    {
        return hash('sha256', json_encode(['credential-review-v1', $user, $tenant, $arguments, $revision], JSON_THROW_ON_ERROR));
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão BI mudou durante a operação. Confira os dados antes de continuar.');
        }
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>} */
    private function document(Connection $connection, Project $context, string $ref): array
    {
        $this->lumo->run($connection, ['pull', $ref], $context, 90);

        return $this->workingDocument($context, $ref);
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>} */
    private function workingDocument(Project $context, ?string $ref = null): array
    {
        $folder = app(WorkspaceFiles::class)->path($context).'/credentials';
        $documents = [];
        foreach (is_dir($folder) ? File::files($folder) : [] as $file) {
            if ($file->isLink() || $file->getSize() > 1000000 || $file->getExtension() !== 'yaml') {
                continue;
            }
            $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $header = Yaml::parse($parts[0]);
            $body = Yaml::parse($parts[1]);
            if (is_array($header) && is_array($body) && ($header['kind'] ?? '') === 'credential' && (string) ($header['tenantId'] ?? '') === (string) $context->tenant_id && is_numeric($header['id'] ?? null) && (int) $header['id'] > 0 && ($ref === null || $ref === 'credential:'.$header['id'])) {
                $documents[] = [$file->getPathname(), $body, $header];
            }
        }
        if (count($documents) !== 1) {
            throw new RuntimeException('Não foi possível identificar um arquivo canônico único desta conexão.');
        }

        return $documents[0];
    }

    private function agentId(array $body): ?int
    {
        $value = $body['agentId'] ?? $body['agent_id'] ?? $body['tokenId'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function assertConfiguration(array $saved, array $data): void
    {
        if (($saved['nome'] ?? '') !== $data['name'] || $this->types->driver((string) ($saved['type'] ?? '')) !== $data['driver'] || $this->agentId($saved) !== $data['agent']) {
            throw new RuntimeException('Os metadados salvos não correspondem à configuração autorizada.');
        }
        foreach ($data['config'] as $key => $value) {
            if (in_array($key, ['JsonKey', 'odbcconnection'], true)) {
                continue;
            }
            $actual = $saved['config'][$key] ?? ($data['driver'] === 'postgres' && $key === 'host' ? ($saved['config']['hostname'] ?? null) : null);
            if ($actual !== $value) {
                throw new RuntimeException('O BI retornou uma configuração diferente da autorizada.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function postgresSchema(Connection $connection, Project $context, string $ref, ?string $schema, ?string $search = null, int $offset = 0): array
    {
        $where = $schema === null ? "table_schema NOT IN ('pg_catalog','information_schema')" : "table_schema = '".str_replace("'", "''", $schema)."'";
        if ($search !== null) {
            $literal = str_replace(['!', '%', '_', "'"], ['!!', '!%', '!_', "''"], $search);
            $where .= " AND (table_name ILIKE '%".$literal."%' ESCAPE '!' OR column_name ILIKE '%".$literal."%' ESCAPE '!')";
        }
        $sql = 'SELECT table_schema AS schema_name, table_name, column_name, data_type, is_nullable, ordinal_position FROM information_schema.columns WHERE '.$where.' ORDER BY table_schema, table_name, ordinal_position LIMIT 5001'.($offset > 0 ? ' OFFSET '.$offset : '');
        $result = $this->lumo->run($connection, ['credential', 'query', $ref, '--sql', $sql, '--limit', '5001', '--timeout', '45s'], $context, 60)['data'] ?? null;
        $rows = is_array($result) ? ($result['rows'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return ['verified' => false, 'state' => 'unconfirmed', 'message' => 'A consulta não retornou metadados suficientes para confirmar a estrutura. Confira as permissões da fonte; um resultado vazio não comprova ausência de tabelas.', 'checked_at' => now()->toIso8601String()];
        }
        $tables = [];
        $truncated = count($rows) > 5000;
        foreach (array_slice($rows, 0, 5000) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Formato de metadados não reconhecido.');
            }
            $row = array_change_key_case($row, CASE_LOWER);
            foreach (['schema_name', 'table_name', 'column_name', 'data_type'] as $key) {
                if (! is_string($row[$key] ?? null) || $row[$key] === '') {
                    throw new RuntimeException('Formato de metadados não reconhecido.');
                }
            }
            $key = json_encode([$row['schema_name'], $row['table_name']], JSON_THROW_ON_ERROR);
            $tables[$key] ??= ['schema' => $row['schema_name'], 'name' => $row['table_name'], 'columns' => []];
            $tables[$key]['columns'][] = ['name' => $row['column_name'], 'type' => $row['data_type'], 'nullable' => ($row['is_nullable'] ?? '') === 'YES'];
        }

        return ['verified' => true, 'state' => 'available', 'tables' => array_values($tables), 'truncated' => $truncated, 'next_offset' => $truncated ? $offset + 5000 : null, 'offset' => $offset, 'search' => $search, 'total_tables' => count($tables), 'message' => count($tables).' tabela(s) identificada(s) na consulta de estrutura.', 'checked_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function schema(mixed $data): array
    {
        if (! is_array($data) || ($data['Success'] ?? null) === false || ($data['ok'] ?? null) === false || ($data['success'] ?? null) === false) {
            return ['verified' => false, 'state' => 'failed', 'message' => 'O BI não forneceu uma estrutura válida para esta fonte. Isso não significa que o banco esteja vazio.', 'checked_at' => now()->toIso8601String()];
        }
        $tables = $data['tables'] ?? $data['Tables'] ?? null;
        if (! is_array($tables) || ! array_is_list($tables)) {
            return ['verified' => false, 'state' => 'unconfirmed', 'message' => 'O formato da estrutura retornada ainda não pôde ser confirmado. Nenhuma tabela vazia será presumida.', 'checked_at' => now()->toIso8601String()];
        }
        $result = [];
        foreach (array_slice($tables, 0, 500) as $table) {
            if (! is_array($table) || ! is_string($table['name'] ?? $table['Name'] ?? null)) {
                throw new RuntimeException('Estrutura de tabelas não reconhecida.');
            }
            $columns = $table['columns'] ?? $table['Columns'] ?? [];
            $result[] = ['name' => $table['name'] ?? $table['Name'], 'schema' => $table['schema'] ?? $table['Schema'] ?? null, 'columns' => array_map(fn (array $column): array => ['name' => $column['name'] ?? $column['Name'] ?? '', 'type' => $column['type'] ?? $column['Type'] ?? null], array_slice(is_array($columns) ? $columns : [], 0, 300))];
        }

        return ['verified' => true, 'state' => 'available', 'tables' => $result, 'truncated' => count($tables) > 500, 'total_tables' => count($tables), 'message' => count($tables).' tabela(s) retornada(s) pela fonte.', 'checked_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Configuração privada de conexão']);
        $context->id = $approval ? 'credential-operation-'.$approval->id : 'credential-query-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }
}
