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
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiTablePresentation
{
    public const ACTIONS = ['update_table_presentation'];

    public const MASKS = [
        ['value' => null, 'label' => 'Padrão do BI'],
        ['value' => 'N0', 'label' => 'Número inteiro'],
        ['value' => 'N2', 'label' => 'Número com 2 casas decimais'],
        ['value' => 'MOEDA', 'label' => 'Valor monetário'],
        ['value' => 'PCT1', 'label' => 'Percentual com 1 casa decimal'],
    ];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    public function describe(User $user, string $tenant, string $id): array
    {
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $generation = $connection->metadata['session_generation'] ?? null;

        return $this->withContext($connection, $environment, function (Project $context) use ($connection, $id, $generation): array {
            $this->assertListed($connection, $context, $id);
            [, $body] = $this->document($connection, $context, 'table:'.$id);
            $columns = [];
            foreach ($body['columns'] as $key => $column) {
                [$name, $type] = $this->columnIdentity($key);
                $columns[] = [
                    'key' => $key, 'name' => $name, 'type' => $type,
                    'label' => is_string($column['label'] ?? null) && trim($column['label']) !== '' ? $column['label'] : $name,
                    'description' => $this->text($column['description'] ?? null),
                    'mask' => is_string($column['mask'] ?? null) ? $this->value($column['mask'], 'mask') : null,
                    'mask_editable' => $type === 'Number' && (! isset($column['mask']) || is_string($column['mask'])),
                    'has_custom_mask' => isset($column['mask']) && ! in_array($this->value($column['mask'], 'mask'), array_column(self::MASKS, 'value'), true),
                ];
            }
            $this->assertGeneration($connection, $generation);

            return ['resource' => 'table:'.$id, 'name' => (string) ($body['nome'] ?? ''), 'description' => $this->text($body['description'] ?? null), 'version' => $this->digest($body), 'columns' => $columns, 'mask_options' => self::MASKS];
        });
    }

    public function prepare(User $user, string $tenant, string $id, array $input): array
    {
        $data = $this->normalize('table:'.$id, $input);
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        $requestHash = $this->digest([$tenant, $data]);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $tenant, $connection, $environment, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outra configuração. Abra outra revisão.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, $data['resource']);
                $revision = $this->withContext($connection, $environment, fn (Project $context): array => $this->revision($connection, $context, $data));
                $approval = DB::transaction(function () use ($user, $tenant, $connection, $data, $revision, $requestHash): Approval {
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => 'Organizar nomes e formatos da tabela', 'description' => 'Confira os campos e seus valores antes de salvar. A operação altera configurações de apresentação da tabela.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.table_presentation.review_created', 'Configuração de apresentação da tabela preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'changed_fields' => count($revision['changes'])]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['table' => 'Esta revisão ainda está sendo preparada. Aguarde e tente novamente com a mesma solicitação.']);
        }
    }

    public function executeApproved(Approval $approval): array
    {
        $approval->refresh();
        $user = User::findOrFail($approval->user_id);
        $data = $approval->payload['action'] ?? [];
        $revision = $approval->payload['revision'] ?? [];
        $tenant = (string) ($revision['tenant_id'] ?? '');
        if (! $approval->permitsUserAccess($user) || $approval->status !== 'executing' || $approval->expires_at->isPast() || $approval->decided_at === null || $approval->type !== 'manual' || $approval->project_id !== null || $approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual' || ! in_array($data['action'] ?? '', self::ACTIONS, true)) {
            throw new RuntimeException('A revisão da tabela não está autorizada para execução.');
        }
        if (! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->digest([$tenant, $data]))) {
            throw new RuntimeException('A revisão da tabela foi alterada. Prepare outra autorização.');
        }
        if (isset($approval->payload['table_presentation_attempt']['started_at'])) {
            throw new RuntimeException('Uma tentativa desta alteração já foi iniciada. Confira a tabela antes de preparar outra revisão.');
        }
        $id = substr((string) ($data['resource'] ?? ''), 6);
        [$connection, $environment] = $this->access($user, $tenant, $id);
        $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
        $this->policy->assertCommandAllowed($tenant, ['push', $data['resource']], $connection);
        app(BiMutationGuard::class)->assertAvailable($user->id, $tenant, $data['resource']);
        try {
            return Cache::lock('bi-table-presentation:'.$tenant.':'.$id, 630)->block(3, function () use ($user, $connection, $environment, $data, $revision, $approval): array {
                return $this->withContext($connection, $environment, function (Project $context) use ($user, $connection, $data, $revision, $approval): array {
                    $currentRevision = $this->revision($connection, $context, $data, false);
                    if ($currentRevision !== $revision) {
                        throw new RuntimeException('A tabela mudou após a revisão. Reabra a configuração e prepare outra autorização.');
                    }
                    [$path, $source, $header] = $this->workingDocument($context, $data['resource']);
                    $updated = $this->apply($source, $data);
                    File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($this->serializable($updated), 40, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP), 0600);
                    $this->validateCandidate($connection, $context, $data['resource']);
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $started = false;
                    try {
                        $approval->update(['payload' => [...$approval->payload, 'table_presentation_attempt' => ['started_at' => now()->toIso8601String(), 'resource' => $data['resource']]]]);
                        $started = true;
                        $this->lumo->run($connection, ['push', $data['resource']], $context, 90);
                        [, $saved] = $this->document($connection, $context, $data['resource']);
                        if ($this->preservedDigest($saved, $data) !== $revision['preserved_digest'] || ! $this->matches($saved, $data)) {
                            throw new RuntimeException('O BI não confirmou todos os campos solicitados ou retornou alterações fora da revisão.');
                        }
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        Audit::record($user->id, 'bi.table_presentation.saved', 'Apresentação da tabela salva e conferida no BI.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $data['resource'], 'changed_fields' => count($revision['changes'])]);

                        return ['resource' => $data['resource'], 'configuration_verified' => true, 'confirmed_name' => (string) $saved['nome'], 'changed_fields' => count($revision['changes']), 'column_count' => count($saved['columns']), 'message' => 'Nomes e formatos salvos e conferidos no G-Analise BI.'];
                    } catch (Throwable $e) {
                        if ($started) {
                            throw new RuntimeException('O resultado da alteração não foi confirmado. Confira a tabela antes de preparar outra revisão; a operação não será reenviada automaticamente.', previous: $e);
                        }
                        throw $e;
                    } finally {
                        if ($started) {
                            $this->catalog->invalidate((string) $context->tenant_id, $connection);
                        }
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Outra alteração desta tabela está em andamento. Aguarde a conclusão antes de preparar outra revisão.');
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'table-presentation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function normalize(string $resource, array $input): array
    {
        if (array_diff(array_keys($input), ['request_id', 'version', 'name', 'description', 'columns']) !== [] || strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 120000) {
            throw ValidationException::withMessages(['table' => 'Envie somente nomes, descrições e formatos. Para muitas alterações, divida a edição em revisões menores.']);
        }
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'name' => ['sometimes', 'required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'columns' => ['sometimes', 'array', 'max:500'], 'columns.*' => ['required', 'array:key,label,description,mask'],
            'columns.*.key' => ['required', 'string', 'min:1', 'max:400', 'distinct:strict'],
            'columns.*.label' => ['sometimes', 'required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            'columns.*.description' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'columns.*.mask' => ['sometimes', 'nullable', Rule::in(array_filter(array_column(self::MASKS, 'value')))],
        ])->validate();
        if (isset($data['columns']) && ! array_is_list($data['columns'])) {
            throw ValidationException::withMessages(['columns' => 'As alterações de colunas precisam formar uma lista.']);
        }
        $table = [];
        if (array_key_exists('name', $data)) {
            $table['nome'] = trim($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $table['description'] = $this->text($data['description']);
        }
        $columns = [];
        foreach ($data['columns'] ?? [] as $column) {
            $fields = array_intersect_key($column, array_flip(['label', 'description', 'mask']));
            if (array_key_exists('label', $fields)) {
                $fields['label'] = trim($fields['label']);
            }
            if (array_key_exists('description', $fields)) {
                $fields['description'] = $this->text($fields['description']);
            }
            if ($fields === []) {
                throw ValidationException::withMessages(['columns' => 'Informe a alteração desejada para cada coluna selecionada.']);
            }
            $columns[$column['key']] = $fields;
        }
        if ($table === [] && $columns === []) {
            throw ValidationException::withMessages(['table' => 'Altere pelo menos um nome, descrição ou formato antes de conferir.']);
        }

        return ['action' => self::ACTIONS[0], 'resource' => $resource, 'source_version' => $data['version'], 'table_changes' => $table, 'column_changes' => $columns, 'request_id' => strtolower($data['request_id'])];
    }

    private function revision(Connection $connection, Project $context, array $data, bool $validate = true): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $this->assertListed($connection, $context, substr($data['resource'], 6));
        [$path, $source, $header] = $this->document($connection, $context, $data['resource']);
        abort_unless(hash_equals($data['source_version'], $this->digest($source)), 409, 'A configuração desta tabela mudou. Reabra o formulário para conferir a versão atual.');
        $updated = $this->apply($source, $data);
        $changes = [];
        foreach ($data['table_changes'] as $field => $value) {
            if ($this->value($source[$field] ?? null, $field) !== $value) {
                $changes[] = ['target' => 'Tabela', 'field' => $field === 'nome' ? 'Nome exibido' : 'Descrição', 'before' => $source[$field] ?? null, 'after' => $value];
            }
        }
        foreach ($data['column_changes'] as $key => $fields) {
            foreach ($fields as $field => $value) {
                if ($this->value($source['columns'][$key][$field] ?? null, $field) !== $value) {
                    $changes[] = ['target' => $source['columns'][$key]['label'] ?? $this->columnIdentity($key)[0], 'column_key' => $key, 'field' => ['label' => 'Nome exibido', 'description' => 'Descrição', 'mask' => 'Formato'][$field], 'before' => $source['columns'][$key][$field] ?? null, 'after' => $value];
                }
            }
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['table' => 'Nenhuma alteração foi identificada em relação à versão atual.']);
        }
        if ($this->preservedDigest($source, $data) !== $this->preservedDigest($updated, $data)) {
            throw new RuntimeException('A configuração alteraria campos fora da apresentação permitida.');
        }
        if ($validate) {
            File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($this->serializable($updated), 40, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP), 0600);
            $this->validateCandidate($connection, $context, $data['resource']);
        }
        $this->assertGeneration($connection, $generation);

        return ['tenant_id' => (string) $context->tenant_id, 'resource' => $data['resource'], 'connection_generation' => $generation, 'source_digest' => $this->digest($source), 'preserved_digest' => $this->preservedDigest($source, $data), 'table' => ['name' => (string) $source['nome'], 'column_count' => count($source['columns'])], 'changes' => $changes, 'impact' => 'Os nomes, descrições e formatos definem padrões de apresentação. Aplicações podem manter suas próprias configurações. Estrutura, linhas, ETL e agendas não fazem parte desta alteração.'];
    }

    private function apply(array $body, array $data): array
    {
        foreach ($data['table_changes'] as $field => $value) {
            $body[$field] = $value;
        }
        foreach ($data['column_changes'] as $key => $fields) {
            if (! array_key_exists($key, $body['columns'])) {
                throw ValidationException::withMessages(['columns' => 'Uma coluna selecionada não está mais disponível nesta tabela. Reabra a configuração.']);
            }
            [, $type] = $this->columnIdentity($key);
            if (array_key_exists('mask', $fields) && ($type !== 'Number' || (isset($body['columns'][$key]['mask']) && ! is_string($body['columns'][$key]['mask'])))) {
                throw ValidationException::withMessages(['columns' => 'Este campo mantém o formato da origem. Os formatos disponíveis podem ser usados em colunas numéricas compatíveis.']);
            }
            foreach ($fields as $field => $value) {
                $body['columns'][$key][$field] = $field === 'mask' && $value === null ? '' : $value;
            }
        }

        return $body;
    }

    private function matches(array $body, array $data): bool
    {
        foreach ($data['table_changes'] as $field => $value) {
            if ($this->value($body[$field] ?? null, $field) !== $value) {
                return false;
            }
        }
        foreach ($data['column_changes'] as $key => $fields) {
            if (! array_key_exists($key, $body['columns'])) {
                return false;
            }
            foreach ($fields as $field => $value) {
                if ($this->value($body['columns'][$key][$field] ?? null, $field) !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    private function value(mixed $value, string $field): mixed
    {
        if ($field === 'description') {
            return $this->text($value);
        }
        if ($field === 'mask' && $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }

    private function preservedDigest(array $body, array $data): string
    {
        foreach (array_keys($data['table_changes']) as $field) {
            unset($body[$field]);
        }
        foreach ($data['column_changes'] as $key => $fields) {
            foreach (array_keys($fields) as $field) {
                unset($body['columns'][$key][$field]);
            }
        }

        return $this->digest($body);
    }

    private function validateCandidate(Connection $connection, Project $context, string $resource): void
    {
        $validation = app(BiResourceValidation::class)->inspect($connection, $context, $resource);
        if (! $validation['passed']) {
            $messages = array_column(array_filter($validation['issues'], fn (array $issue): bool => $issue['severity'] === 'error'), 'message');
            throw ValidationException::withMessages(['table' => 'A configuração precisa de ajuste antes da revisão. '.implode(' ', array_slice($messages, 0, 3))]);
        }
    }

    private function access(User $user, string $tenant, string $id): array
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/D', $id), 422, 'Tabela inválida.');
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite configurar tabelas.');

        return $this->catalog->access($user, $tenant);
    }

    private function assertListed(Connection $connection, Project $context, string $id): void
    {
        abort_unless(collect($this->catalog->rows($connection, $context, 'table'))->contains(fn (array $row): bool => (string) $row['id'] === $id), 404, 'Esta tabela não está disponível neste ambiente para sua sessão.');
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! is_string($generation) || $generation === '' || ! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou durante a operação. Prepare outra revisão.');
        }
    }

    private function document(Connection $connection, Project $context, string $resource): array
    {
        $this->lumo->run($connection, ['pull', $resource], $context, 90);

        return $this->workingDocument($context, $resource);
    }

    private function workingDocument(Project $context, string $resource): array
    {
        $folder = app(WorkspaceFiles::class)->path($context).'/tables';
        $documents = [];
        foreach (is_dir($folder) ? File::files($folder) : [] as $file) {
            if ($file->isLink() || $file->getSize() > 2_000_000 || $file->getExtension() !== 'yaml') {
                continue;
            }
            $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $header = Yaml::parse($parts[0]);
            $body = Yaml::parse($parts[1], Yaml::PARSE_OBJECT_FOR_MAP);
            if ($body instanceof \stdClass) {
                $body = (array) $body;
            }
            if (is_array($body) && ($body['columns'] ?? null) instanceof \stdClass) {
                $body['columns'] = (array) $body['columns'];
            }
            if (is_array($header) && is_array($body) && ($header['kind'] ?? '') === 'table' && (string) ($header['tenantId'] ?? '') === (string) $context->tenant_id && $resource === 'table:'.($header['id'] ?? '')) {
                if (! is_string($body['nome'] ?? null) || ! is_array($body['columns'] ?? null) || ($body['columns'] !== [] && array_is_list($body['columns']))) {
                    throw new RuntimeException('O BI não retornou uma configuração completa da tabela.');
                }
                foreach ($body['columns'] as $key => $column) {
                    if ($column instanceof \stdClass) {
                        $column = (array) $column;
                        $body['columns'][$key] = $column;
                    }
                    if (! is_string($key) || ! is_array($column) || ($column !== [] && array_is_list($column))) {
                        throw new RuntimeException('A configuração de uma coluna chegou em formato não reconhecido.');
                    }
                }
                $documents[] = [$file->getPathname(), $body, $header];
            }
        }
        if (count($documents) !== 1) {
            throw new RuntimeException('Não foi possível identificar uma configuração canônica única desta tabela.');
        }

        return $documents[0];
    }

    private function serializable(array $body): array
    {
        foreach ($body['columns'] as $key => $column) {
            if ($column === []) {
                $body['columns'][$key] = new \stdClass;
            }
        }
        if ($body['columns'] === []) {
            $body['columns'] = new \stdClass;
        }

        return $body;
    }

    private function columnIdentity(string $key): array
    {
        return preg_match('/^(.+) \(([^()]*)\)$/D', $key, $match) ? [trim($match[1]), $match[2]] : [$key, 'Unknown'];
    }

    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Apresentação da tabela']);
        $context->id = $approval ? 'table-presentation-'.$approval->id : 'table-presentation-read-'.Str::uuid();
        try {
            $this->lumo->initialize($connection, $context, true);

            return $operation($context);
        } finally {
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function digest(mixed $value): string
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

    private function fingerprint(int $user, string $tenant, array $data, array $revision): string
    {
        return $this->digest(['table-presentation-v1', $user, $tenant, $data, $revision]);
    }
}
