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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiDesks
{
    public const ACTIONS = ['create_desk', 'update_desk'];

    public const KINDS = ['bi-desk', 'dw-desk'];

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    public function describe(User $user, string $tenant, string $kind, string $id): array
    {
        [$connection, $item] = $this->access($user, $tenant, $kind);
        abort_unless(ctype_digit($id) && (int) $id > 0, 422, 'Mesa inválida.');

        return $this->withContext($connection, $item, function (Project $context) use ($connection, $kind, $id): array {
            $this->assertListed($connection, $context, $kind, $id);
            [, $body] = $this->document($connection, $context, $kind.':'.$id);

            return ['resource' => $kind.':'.$id, 'name' => (string) ($body['nome'] ?? ''), 'description' => (string) ($body['descricao'] ?? '')];
        });
    }

    public function prepare(User $user, string $tenant, string $kind, array $input): array
    {
        [$connection, $item] = $this->access($user, $tenant, $kind);
        $data = $this->normalize($kind, $input);
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_desk' ? 'new' : 'push', $data['resource'] ?? $kind], $connection);
        $requestHash = $this->digest([$tenant, $data]);
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$data['request_id'], 660)->block(3, function () use ($user, $connection, $item, $tenant, $data, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada com outros dados. Abra outra configuração.');

                    return ['approval' => $existing, 'project' => null, 'reused' => true];
                }
                $revision = $this->withContext($connection, $item, fn (Project $context): array => $this->revision($connection, $context, $data));
                $approval = DB::transaction(function () use ($user, $connection, $tenant, $data, $revision, $requestHash): Approval {
                    $this->assertGeneration($connection, $revision['connection_generation']);
                    $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'agent_run_id' => null, 'type' => 'manual', 'title' => $data['action'] === 'create_desk' ? 'Criar mesa' : 'Editar mesa', 'description' => 'Confira o tenant, o tipo da mesa e as alterações. Esta ação não publica aplicações ou fontes.', 'payload' => ['origin' => 'manual', 'action' => $data, 'revision' => $revision, 'request_id' => $data['request_id'], 'request_hash' => $requestHash], 'fingerprint' => $this->fingerprint($user->id, $tenant, $data, $revision), 'expires_at' => now()->addMinutes(12)]);
                    Audit::record($user->id, 'bi.desk.review_created', 'Configuração de mesa preparada para revisão.', null, ['approval_id' => $approval->id, 'tenant_id' => $tenant, 'resource' => $data['resource'], 'action' => $data['action']]);

                    return $approval;
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return ['approval' => $approval->refresh(), 'project' => null, 'reused' => false];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['desk' => 'Esta revisão ainda está sendo preparada. Aguarde e tente novamente com a mesma solicitação.']);
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
            throw new RuntimeException('A revisão da mesa não está autorizada para execução.');
        }
        if (! hash_equals($approval->fingerprint, $this->fingerprint($user->id, $tenant, $data, $revision)) || ! hash_equals((string) ($approval->payload['request_hash'] ?? ''), $this->digest([$tenant, $data]))) {
            throw new RuntimeException('A revisão da mesa foi alterada. Prepare outra autorização.');
        }
        if (isset($approval->payload['desk_attempt']['started_at'])) {
            throw new RuntimeException('Uma tentativa desta operação já foi iniciada. Confira o catálogo antes de preparar outra revisão.');
        }
        [$connection, $item] = $this->access($user, $tenant, $data['kind']);
        $this->assertGeneration($connection, $revision['connection_generation'] ?? null);
        $this->policy->assertCommandAllowed($tenant, [$data['action'] === 'create_desk' ? 'new' : 'push', $data['resource'] ?? $data['kind']], $connection);
        try {
            return Cache::lock('bi-desk-write:'.$tenant, 630)->block(3, function () use ($user, $connection, $item, $data, $revision, $approval): array {
                return $this->withContext($connection, $item, function (Project $context) use ($user, $connection, $data, $revision, $approval): array {
                    if ($this->revision($connection, $context, $data) !== $revision) {
                        throw new RuntimeException('A mesa mudou após a revisão. Confira a versão atual e prepare outra autorização.');
                    }
                    $ref = $data['resource'];
                    $started = false;
                    $created = false;
                    try {
                        if ($data['action'] === 'create_desk') {
                            $this->assertGeneration($connection, $revision['connection_generation']);
                            $approval->update(['payload' => [...$approval->payload, 'desk_attempt' => ['started_at' => now()->toIso8601String()]]]);
                            $started = true;
                            $this->lumo->run($connection, ['new', $data['kind'], '--name', $data['name']], $context, 90);
                            [$path, $body, $header] = $this->workingDocument($context, $data['kind']);
                            $ref = $data['kind'].':'.$header['id'];
                            $created = true;
                            $approval->update(['payload' => [...$approval->payload, 'desk_attempt' => [...$approval->payload['desk_attempt'], 'created_resource' => $ref]]]);
                            ManagedBiResource::create(['user_id' => $user->id, 'project_id' => null, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'origin' => 'manual_desk', 'status' => 'config_pending', 'receipt' => ['approval_id' => $approval->id, 'created_at' => now()->toIso8601String()]]);
                        } else {
                            [$path, $body, $header] = $this->workingDocument($context, $data['kind'], $ref);
                        }
                        if (! $created && $this->digest($body) !== $revision['source_digest']) {
                            throw new RuntimeException('A mesa mudou durante a preparação. Nenhuma edição foi enviada.');
                        }
                        $body['nome'] = $data['name'];
                        $body['descricao'] = $data['description'];
                        File::replace($path, Yaml::dump($header, 8, 2)."---\n".Yaml::dump($body, 30, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), 0600);
                        $this->lumo->run($connection, ['lint', $path], $context, 60);
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        $approval->update(['payload' => [...$approval->payload, 'desk_attempt' => [...($approval->payload['desk_attempt'] ?? []), 'started_at' => now()->toIso8601String(), 'resource' => $ref, 'push_started' => true]]]);
                        $started = true;
                        $this->lumo->run($connection, ['push', $ref], $context, 90);
                        [, $saved] = $this->document($connection, $context, $ref);
                        if (($saved['nome'] ?? null) !== $data['name'] || trim((string) ($saved['descricao'] ?? '')) !== (string) $data['description']) {
                            throw new RuntimeException('A configuração enviada não foi confirmada no BI.');
                        }
                        $this->assertGeneration($connection, $revision['connection_generation']);
                        ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $context->tenant_id)->where('resource', $ref)->update(['status' => 'configured']);
                        Audit::record($user->id, 'bi.desk.configured', 'Configuração de mesa salva e conferida no BI.', null, ['approval_id' => $approval->id, 'tenant_id' => $context->tenant_id, 'resource' => $ref, 'created' => $created]);

                        return ['resource' => $ref, 'confirmed_name' => $saved['nome'], 'configuration_verified' => true, 'created' => $created, 'message' => 'Mesa salva e conferida no G-Analise BI.'];
                    } catch (Throwable $e) {
                        if ($created) {
                            throw new RuntimeException('A mesa '.$ref.' foi criada, mas sua configuração não foi confirmada. Confira esse recurso no catálogo antes de preparar outra operação.', previous: $e);
                        }
                        if ($started) {
                            throw new RuntimeException('O resultado da operação não foi confirmado. Confira a mesa no catálogo antes de repetir; não houve nova tentativa automática.', previous: $e);
                        }
                        throw $e;
                    } finally {
                        if ($started) {
                            Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$context->tenant_id);
                        }
                    }
                }, $approval);
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Outra configuração de mesa está em andamento neste tenant. Confira o resultado antes de preparar outra revisão.');
        }
    }

    public function cleanup(Approval $approval): void
    {
        if ($approval->project_id === null && in_array($approval->payload['action']['action'] ?? '', self::ACTIONS, true) && Str::isUuid($approval->id)) {
            $context = new Project(['user_id' => $approval->user_id, 'tenant_id' => (string) ($approval->payload['revision']['tenant_id'] ?? '')]);
            $context->id = 'desk-operation-'.$approval->id;
            File::deleteDirectory(app(WorkspaceFiles::class)->path($context));
        }
    }

    private function normalize(string $kind, array $input): array
    {
        if (array_diff(array_keys($input), ['action', 'request_id', 'resource', 'name', 'description']) !== []) {
            throw ValidationException::withMessages(['desk' => 'Esta configuração aceita somente nome e descrição da mesa.']);
        }
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $data = Validator::make($input, ['action' => ['required', 'in:create_desk,update_desk'], 'request_id' => ['required', 'uuid'], 'resource' => ['required_if:action,update_desk', 'nullable', 'string', 'regex:/^'.preg_quote($kind, '/').':[1-9][0-9]*$/D'], 'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'description' => ['nullable', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/']])->validate();
        if ($data['action'] === 'create_desk' && ! empty($data['resource'])) {
            throw ValidationException::withMessages(['resource' => 'Uma nova mesa não pode usar a referência de uma mesa existente.']);
        }

        $description = trim((string) ($data['description'] ?? ''));

        return ['action' => $data['action'], 'kind' => $kind, 'resource' => $data['resource'] ?? null, 'name' => $data['name'], 'description' => $description === '' ? null : $description, 'request_id' => strtolower($data['request_id'])];
    }

    private function access(User $user, string $tenant, string $kind): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Tipo de mesa inválido.');
        $current = $user->fresh();
        abort_if(! $current?->canStartWork() || $current->role === 'viewer' || $current->access_version !== $user->access_version, 403, 'Seu acesso não permite configurar mesas.');

        return $this->catalog->access($user, $tenant);
    }

    private function revision(Connection $connection, Project $context, array $data): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $rows = $this->catalog->rows($connection, $context, $data['kind']);
        $source = null;
        if ($data['action'] === 'update_desk') {
            $id = substr($data['resource'], strlen($data['kind']) + 1);
            abort_unless(collect($rows)->contains(fn (array $row): bool => (string) $row['id'] === $id), 404, 'Esta mesa não está disponível neste tenant para sua sessão.');
            [, $source] = $this->document($connection, $context, $data['resource']);
        }
        if ($source === null || ($source['nome'] ?? '') !== $data['name']) {
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) ($row['nome'] ?? $row['name'] ?? ''))) === mb_strtolower($data['name']) && $data['resource'] !== $data['kind'].':'.$row['id']) {
                    throw new RuntimeException('Já existe uma mesa deste tipo com esse nome. Escolha outro nome ou edite a mesa existente.');
                }
            }
        }
        $this->assertGeneration($connection, $generation);

        return ['tenant_id' => (string) $context->tenant_id, 'resource' => $data['resource'], 'connection_generation' => $generation, 'source_digest' => $source === null ? null : $this->digest($source), 'desk' => ['kind' => $data['kind'], 'label' => $data['kind'] === 'bi-desk' ? 'Mesa de aplicações' : 'Mesa de dados', 'name' => $data['name'], 'description' => $data['description'], 'previous' => $source === null ? null : ['name' => $source['nome'] ?? '', 'description' => $source['descricao'] ?? null]], 'impact' => $source === null ? 'A mesa começará vazia. Aplicações e fontes precisam de publicação própria para serem compartilhadas nela. A configuração de membros não faz parte desta operação.' : 'Somente o nome e a descrição da mesa serão alterados. Os recursos vinculados e a configuração de acesso serão preservados.'];
    }

    private function assertListed(Connection $connection, Project $context, string $kind, string $id): void
    {
        abort_unless(collect($this->catalog->rows($connection, $context, $kind))->contains(fn (array $row): bool => (string) $row['id'] === $id), 404, 'Esta mesa não está disponível neste tenant para sua sessão.');
    }

    private function assertGeneration(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ! $user?->canStartWork() || $user->role === 'viewer' || $user->access_version !== $connection->operationAccessVersion || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('Sua conexão ou seu acesso mudou durante a operação. Prepare outra revisão.');
        }
    }

    private function document(Connection $connection, Project $context, string $ref): array
    {
        $this->lumo->run($connection, ['pull', $ref], $context, 90);

        return $this->workingDocument($context, explode(':', $ref, 2)[0], $ref);
    }

    private function workingDocument(Project $context, string $kind, ?string $ref = null): array
    {
        $folder = app(WorkspaceFiles::class)->path($context).'/'.($kind === 'bi-desk' ? 'bi-desks' : 'dw-desks');
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
            if (is_array($header) && is_array($body) && ($header['kind'] ?? '') === $kind && (string) ($header['tenantId'] ?? '') === (string) $context->tenant_id && ctype_digit((string) ($header['id'] ?? '')) && (int) $header['id'] > 0 && ($ref === null || $ref === $kind.':'.$header['id'])) {
                $documents[] = [$file->getPathname(), $body, $header];
            }
        }
        if (count($documents) !== 1) {
            throw new RuntimeException('Não foi possível identificar uma configuração canônica única desta mesa.');
        }

        return $documents[0];
    }

    private function withContext(Connection $connection, array $tenant, callable $operation, ?Approval $approval = null): array
    {
        $context = new Project(['user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Configuração de mesa']);
        $context->id = $approval ? 'desk-operation-'.$approval->id : 'desk-query-'.Str::uuid();
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

    private function fingerprint(int $user, string $tenant, array $arguments, array $revision): string
    {
        return $this->digest(['desk-review-v1', $user, $tenant, $arguments, $revision]);
    }
}
