<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class BiCatalog
{
    public const KINDS = ['app', 'bi-desk', 'dw-desk', 'table', 'flow', 'schedule', 'agent', 'writeback', 'deck', 'presentation', 'investigation', 'credential', 'variables'];

    private const FOLDERS = ['app' => 'apps', 'bi-desk' => 'bi-desks', 'dw-desk' => 'dw-desks', 'table' => 'tables', 'flow' => 'flows', 'schedule' => 'schedules', 'agent' => 'agents', 'writeback' => 'writebacks', 'deck' => 'decks', 'presentation' => 'presentations', 'investigation' => 'investigations', 'credential' => 'credentials'];

    public function __construct(private LumoClient $lumo, private TenantPolicy $policy) {}

    private function snapshotKey(Connection $connection, string $tenant): string
    {
        return 'bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$tenant;
    }

    private function assertSession(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        $user = User::find($connection->user_id);
        if (! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation || ! $user?->canStartWork() || $user->access_version !== $connection->operationAccessVersion) {
            throw new RuntimeException('A sessão ou o acesso ao G-Analise mudou durante a consulta. Atualize seus acessos antes de continuar.');
        }
    }

    /** @return array{status: string, count: null, reason: string} */
    private function failedCapability(RuntimeException $failure): array
    {
        $moduleUnavailable = $failure instanceof BiNativeFailure && $failure->reason === 'module_unavailable';

        return ['status' => $failure->getCode() === 6 && ! $moduleUnavailable ? 'access_denied' : 'unavailable', 'count' => null, 'reason' => $failure->getMessage()];
    }

    public function invalidate(string $tenant, Connection $connection): void
    {
        $key = $this->snapshotKey($connection, $tenant);
        Cache::forget($key);
        foreach (self::KINDS as $kind) {
            Cache::forget($key.':section:'.$kind);
        }
    }

    public function connection(User $user): Connection
    {
        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus')->where('status', 'connected')->first();
        abort_unless($connection, 422, 'Conecte sua conta G-Analise BI para abrir o catálogo.');

        $connection->operationAccessVersion = $user->access_version;

        return $connection;
    }

    public function access(User $user, string $tenant): array
    {
        abort_unless(ctype_digit($tenant) && (int) $tenant > 0, 422, 'Tenant inválido.');
        $connection = $this->connection($user);
        $item = collect($connection->metadata['tenants'] ?? [])->first(fn ($item) => (string) ($item['id'] ?? '') === $tenant);
        abort_unless($item, 403, 'Este tenant não está disponível para sua sessão. Atualize seus acessos.');

        return [$connection, $item];
    }

    public function context(Connection $connection, array $tenant): Project
    {
        $project = new Project;
        $project->forceFill(['id' => 'catalog-'.$tenant['id'].'-'.($connection->metadata['session_generation'] ?? 'initial'), 'user_id' => $connection->user_id, 'tenant_id' => (string) $tenant['id'], 'tenant_name' => $tenant['name'], 'name' => 'Catálogo — '.$tenant['name']]);

        return $project;
    }

    public function snapshot(User $user, string $tenant, bool $refresh = false): array
    {
        [$connection,$item] = $this->access($user, $tenant);
        $generation = $connection->metadata['session_generation'] ?? null;
        $key = $this->snapshotKey($connection, $tenant);
        if (! $refresh && ($cached = Cache::get($key))) {
            $this->assertSession($connection, $generation);

            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 200)->block(3, function () use ($connection, $item, $tenant, $key, $refresh, $generation) {
                if (! $refresh && ($cached = Cache::get($key))) {
                    $this->assertSession($connection, $generation);

                    return $cached;
                }
                if ($refresh) {
                    $this->invalidate($tenant, $connection);
                }
                $project = $this->context($connection, $item);
                $this->lumo->initialize($connection, $project, true);
                $warnings = [];
                $metadata = [];
                $metadataFresh = false;
                try {
                    $this->refreshSnapshotFiles($connection, $project);
                    $metadata = $this->workingMetadata($project);
                    $metadataFresh = true;
                } catch (RuntimeException $e) {
                    if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                        throw $e;
                    }
                    $warnings[] = 'Os metadados locais não foram atualizados; alguns detalhes serão carregados ao abrir o recurso.';
                }
                $resources = [];
                $capabilities = [];
                foreach (self::KINDS as $kind) {
                    try {
                        $rows = $this->rows($connection, $project, $kind);
                        if ($kind === 'agent') {
                            $metadata = $this->agentPresentation($connection, $project, $rows, $metadata);
                        }
                        $resources[$kind] = array_map(fn ($row) => $this->summary($kind, $row, $metadata[$kind.':'.($row['id'] ?? '')] ?? null), $rows);
                        $capabilities[$kind] = ['status' => 'available', 'count' => count($rows), 'reason' => null];
                    } catch (RuntimeException $e) {
                        if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                            throw $e;
                        }
                        $resources[$kind] = [];
                        $capabilities[$kind] = $this->failedCapability($e);
                    }
                }
                $resources = app(BiResourceRelations::class)->deskCounters($resources, $capabilities);
                $tags = collect($resources)->flatten(1)->flatMap(fn ($row) => $row['tags'] ?? [])->unique()->sort()->values()->all();
                $snapshot = ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resources' => $resources, 'capabilities' => $capabilities, 'tags' => $tags, 'warnings' => $warnings, 'metadata_fresh' => $metadataFresh, 'synced_at' => now()->toIso8601String()];
                $this->assertSession($connection, $generation);
                Cache::put($key, $snapshot, now()->addSeconds(90));

                return $snapshot;
            });
        } catch (LockTimeoutException) {
            throw new BiBusy;
        }
    }

    public function section(User $user, string $tenant, string $kind, bool $refresh = false): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Categoria inválida.');
        [$connection, $item] = $this->access($user, $tenant);
        $generation = $connection->metadata['session_generation'] ?? null;
        $key = $this->snapshotKey($connection, $tenant);
        $sectionKey = $key.':section:'.$kind;
        if (! $refresh && ($cached = Cache::get($key) ?? Cache::get($sectionKey))) {
            $this->assertSession($connection, $generation);

            return [...$cached, 'section' => $kind];
        }

        try {
            return Cache::lock($key.':lock', 200)->block(3, function () use ($connection, $item, $tenant, $kind, $key, $sectionKey, $refresh, $generation): array {
                if (! $refresh && ($cached = Cache::get($key) ?? Cache::get($sectionKey))) {
                    $this->assertSession($connection, $generation);

                    return [...$cached, 'section' => $kind];
                }
                if ($refresh) {
                    $this->invalidate($tenant, $connection);
                }
                $project = $this->context($connection, $item);
                $this->lumo->initialize($connection, $project, true);
                $needed = $this->sectionKinds($kind);
                $rows = [];
                $capabilities = [];
                $references = [];
                foreach ($needed as $type) {
                    try {
                        $rows[$type] = $this->rows($connection, $project, $type);
                        if ($type !== 'variables') {
                            foreach ($rows[$type] as $row) {
                                if ((! is_int($row['id']) && ! is_string($row['id'])) || ! ctype_digit((string) $row['id']) || (int) $row['id'] <= 0) {
                                    throw new RuntimeException('A categoria retornou um recurso com identificador inválido. Atualize o catálogo.');
                                }
                            }
                            foreach ($rows[$type] as $row) {
                                $references[] = $type.':'.$row['id'];
                            }
                        }
                        $capabilities[$type] = ['status' => 'available', 'count' => count($rows[$type]), 'reason' => null];
                    } catch (RuntimeException $e) {
                        if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                            throw $e;
                        }
                        $rows[$type] = [];
                        $capabilities[$type] = $this->failedCapability($e);
                    }
                }
                $metadata = $this->sectionMetadata($connection, $project, $references);
                if (isset($rows['agent'])) {
                    $metadata = $this->agentPresentation($connection, $project, $rows['agent'], $metadata);
                }
                $metadataFresh = count($metadata) === count($references);
                $resources = [];
                foreach ($rows as $type => $items) {
                    $resources[$type] = array_map(fn (array $row): array => $this->summary($type, $row, $metadata[$type.':'.$row['id']] ?? null), $items);
                }
                $resources = app(BiResourceRelations::class)->deskCounters($resources, $capabilities);
                $tags = collect($resources)->flatten(1)->flatMap(fn ($row) => $row['tags'] ?? [])->unique()->sort()->values()->all();
                $snapshot = ['section' => $kind, 'tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resources' => $resources, 'capabilities' => $capabilities, 'tags' => $tags, 'warnings' => $metadataFresh ? [] : ['Alguns detalhes, vínculos, nomes de dashboards ou etiquetas não puderam ser atualizados. As informações disponíveis podem ser conferidas ao abrir o recurso.'], 'metadata_fresh' => $metadataFresh, 'synced_at' => now()->toIso8601String()];
                $this->assertSession($connection, $generation);
                $loaded = array_keys($resources);
                $expires = now()->addSeconds(90);
                foreach (self::KINDS as $candidate) {
                    if (array_diff($this->sectionKinds($candidate), $loaded) === []) {
                        Cache::put($key.':section:'.$candidate, $snapshot, $expires);
                    }
                }

                return $snapshot;
            });
        } catch (LockTimeoutException) {
            throw new BiBusy;
        }
    }

    /**
     * Completa apenas a apresentação de agentes listados nesta sessão, sem
     * aceitar WORKING de outro tenant nem copiar segredos da leitura nativa.
     */
    private function agentPresentation(Connection $connection, Project $context, array $rows, array $metadata): array
    {
        foreach ($rows as $row) {
            if ((! is_int($row['id']) && ! is_string($row['id'])) || ! ctype_digit((string) $row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $reference = 'agent:'.$id;
            if (! empty($metadata[$reference])) {
                continue;
            }
            try {
                $data = $this->lumo->run($connection, ['info', $reference], $context, 60)['data'];
                $payload = is_array($data) ? ($data['payload'] ?? $data) : null;
                if (! is_array($payload) || (array_key_exists('header', $payload) && ! is_array($payload['header']))) {
                    continue;
                }
                $header = $payload['header'] ?? [];
                $valid = true;
                foreach (['id' => $id, 'kind' => 'agent', 'tenantId' => (string) $context->tenant_id] as $field => $expected) {
                    if (array_key_exists($field, $header) && ((! is_int($header[$field]) && ! is_string($header[$field])) || (string) $header[$field] !== $expected)) {
                        $valid = false;
                    }
                }
                $body = array_key_exists('body', $payload) ? $payload['body'] : $payload;
                if (! $valid || ! is_array($body) || ((! is_int($body['id'] ?? null) && ! is_string($body['id'] ?? null))) || (string) $body['id'] !== $id) {
                    continue;
                }
                $presentation = [];
                foreach (['nome', 'name', 'descricao', 'description'] as $field) {
                    if (array_key_exists($field, $body) && (is_string($body[$field]) || $body[$field] === null)) {
                        $presentation[$field] = $body[$field];
                    }
                }
                if (is_bool($body['ativo'] ?? null)) {
                    $presentation['ativo'] = $body['ativo'];
                }
                if ($presentation !== []) {
                    $metadata[$reference] = $presentation;
                }
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                    throw $e;
                }
            }
        }

        return $metadata;
    }

    private function sectionKinds(string $kind): array
    {
        return match ($kind) {
            'app' => ['app', 'bi-desk'],
            'bi-desk' => ['bi-desk', 'app'],
            'dw-desk' => ['dw-desk', 'table', 'flow', 'writeback'],
            'table', 'flow', 'writeback' => [$kind, 'dw-desk'],
            default => [$kind],
        };
    }

    private function sectionMetadata(Connection $connection, Project $context, array $references): array
    {
        if ($references === []) {
            return [];
        }
        $this->assertSnapshotContext($connection, $context);
        $wanted = array_fill_keys($references, true);
        $types = array_unique(array_map(fn (string $ref): string => explode(':', $ref, 2)[0], $references));
        $root = app(WorkspaceFiles::class)->path($context);
        foreach (self::FOLDERS as $type => $folder) {
            if (! in_array($type, $types, true) || ! is_dir($root.'/'.$folder)) {
                continue;
            }
            foreach (File::allFiles($root.'/'.$folder) as $file) {
                try {
                    $document = $this->workingDocument($context, $file);
                } catch (\Throwable) {
                    continue;
                }
                if ($document !== null && isset($wanted[$document[0]]) && ! File::delete($file->getPathname())) {
                    throw new RuntimeException('Não foi possível renovar os detalhes desta categoria. Tente novamente.');
                }
            }
        }
        $received = [];
        foreach (array_chunk($references, 100) as $batch) {
            try {
                $this->lumo->run($connection, ['pull', ...$batch, '--discard-local'], $context, 150);
                $received = [...$received, ...$batch];
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                    throw $e;
                }
            }
        }

        return $this->workingMetadata($context, $received);
    }

    public function rows(Connection $connection, Project $project, string $kind, bool $remote = true, ?string $desk = null): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Tipo de recurso inválido.');
        abort_unless($desk === null || (ctype_digit($desk) && (int) $desk > 0 && in_array($kind, ['app', 'table', 'flow', 'writeback'], true)), 422, 'Mesa inválida para esta consulta.');
        if ($kind === 'variables') {
            $data = $this->lumo->run($connection, ['list', 'variables', '--remote'], $project, 60)['data'];
            if (! is_array($data) || ! is_array($data['variables'] ?? null) || ! isset($data['count']) || (int) $data['count'] !== count($data['variables'])) {
                throw new RuntimeException('A lista de variáveis chegou incompleta ou em formato desconhecido.');
            }
            $names = array_keys($data['variables']);
            unset($data);

            return array_map(fn ($name) => ['id' => bin2hex((string) $name), 'nome' => (string) $name, 'reference' => '{{'.$name.'}}', 'value' => '[protegido]'], $names);
        }
        $rows = [];
        $offset = 0;
        do {
            $data = $this->lumo->run($connection, ['list', $kind, ...($remote ? ['--remote'] : []), ...($desk === null ? [] : ['--desk', $desk]), '--limit', '500', '--offset', (string) $offset], $project, 60)['data'];
            $page = is_array($data) ? ($data['results'] ?? (array_is_list($data) ? $data : null)) : null;
            if (! is_array($page) || ! array_is_list($page)) {
                throw new RuntimeException('A listagem de '.$kind.' não retornou um formato reconhecido.');
            }
            foreach ($page as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    throw new RuntimeException('A listagem retornou um recurso sem identificador.');
                }$rows[] = $row;
            }
            $offset += count($page);
            $matched = $data['matched'] ?? null;
            if ($offset > 20000) {
                throw new RuntimeException('O catálogo excedeu o limite de recursos. Refine o escopo.');
            }
        } while (count($page) === 500 && ($matched === null || $offset < (int) $matched));
        if (isset($matched) && $offset < (int) $matched) {
            throw new RuntimeException('A lista de recursos chegou incompleta. Atualize o catálogo.');
        }

        return $rows;
    }

    private function assertSnapshotContext(Connection $connection, Project $context): void
    {
        $expected = 'catalog-'.$context->tenant_id.'-'.($connection->metadata['session_generation'] ?? 'initial');
        if ($context->exists || $context->user_id !== $connection->user_id || $context->id !== $expected) {
            throw new RuntimeException('Somente o cache de leitura do catálogo pode ser reconstruído desta forma.');
        }
    }

    private function refreshSnapshotFiles(Connection $connection, Project $context, ?string $resource = null): void
    {
        $this->assertSnapshotContext($connection, $context);
        // This workspace holds disposable catalog snapshots, never project authorship.
        $this->lumo->run($connection, ['pull', ...($resource === null ? [] : [$resource]), '--discard-local'], $context, $resource === null ? 150 : 90);
    }

    /** @return array{string, string}|null */
    private function workingDocument(Project $project, \SplFileInfo $file): ?array
    {
        if ($file->isLink() || $file->getSize() > 2_000_000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
            return null;
        }
        $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
        if (count($parts) !== 2) {
            return null;
        }
        $header = Yaml::parse($parts[0]);
        if (! is_array($header) || (string) ($header['tenantId'] ?? '') !== (string) $project->tenant_id) {
            return null;
        }
        $kind = $header['kind'] ?? '';
        $id = $header['id'] ?? null;
        if (! in_array($kind, self::KINDS, true) || ! $id) {
            return null;
        }

        return [$kind.':'.$id, $parts[1]];
    }

    public function workingMetadata(Project $project, ?array $references = null): array
    {
        if ($references === []) {
            return [];
        }
        $wanted = $references === null ? null : array_fill_keys($references, true);
        $types = $references === null ? null : array_unique(array_map(fn (string $ref): string => explode(':', $ref, 2)[0], $references));
        $root = app(WorkspaceFiles::class)->path($project);
        $result = [];
        foreach (self::FOLDERS as $type => $folder) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }
            if (! is_dir($root.'/'.$folder)) {
                continue;
            }
            foreach (File::allFiles($root.'/'.$folder) as $file) {
                try {
                    $document = $this->workingDocument($project, $file);
                    if ($document === null || ($wanted !== null && ! isset($wanted[$document[0]]))) {
                        continue;
                    }
                    $body = Yaml::parse($document[1]);
                    if (is_array($body)) {
                        $result[$document[0]] = $body;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $result;
    }

    public function summary(string $kind, array $row, ?array $body = null): array
    {
        $body = $body ?? [];
        if ($kind === 'variables') {
            $body = ['nome' => $row['nome'], 'descricao' => 'Valor protegido. Referência: '.$row['reference']];
        }
        $id = (string) $row['id'];
        $nativeState = $body['_state'] ?? null;
        $stateMetadata = is_array($nativeState) ? $nativeState : [];
        $deskKnown = array_key_exists('deskId', $body) || array_key_exists('deskId', $row) || array_key_exists('deskId', $stateMetadata);
        $desk = array_key_exists('deskId', $body) ? $body['deskId'] : ($row['deskId'] ?? $stateMetadata['deskId'] ?? null);
        $state = is_string($nativeState) ? $nativeState : ($stateMetadata['state'] ?? null);
        if (! is_string($state)) {
            $state = in_array($kind, ['app', 'flow', 'table', 'writeback', 'deck'], true) && $deskKnown ? ($desk ? 'published' : 'draft') : null;
        }
        $dashboards = $kind === 'app' && array_key_exists('dashboards', $body) ? array_map(function ($document) {
            $d = $document['body'] ?? $document;

            return ['id' => $document['header']['id'] ?? $d['id'] ?? null, 'name' => $d['nome'] ?? $d['name'] ?? 'Dashboard', 'widget_count' => count($d['widgets'] ?? [])];
        }, $body['dashboards'] ?? []) : null;

        return ['id' => $id, 'ref' => $kind === 'variables' ? '{{'.$row['nome'].'}}' : $kind.':'.$id, 'kind' => $kind, 'name' => $body['nome'] ?? $body['name'] ?? $row['nome'] ?? $row['name'] ?? ($kind.' '.$id), 'description' => $body['descricao'] ?? $body['description'] ?? null, 'state' => $state, 'desk_id' => $desk === null ? null : (string) $desk, 'desk_known' => $deskKnown, 'tags' => array_values(array_filter($body['tags'] ?? [], is_string(...))), 'dashboard_count' => $dashboards === null ? null : count($dashboards), 'dashboards' => $dashboards, 'table_count' => isset($body['tables']) ? count($body['tables']) : null, 'active' => $body['ativo'] ?? null, 'online' => $body['online'] ?? null, 'metadata_available' => $body !== []];
    }

    public function detail(User $user, string $tenant, string $kind, string $id, bool $includeRelations = true): array
    {
        if ($kind === 'variables') {
            return $this->variableDetail($user, $tenant, $id);
        }
        abort_unless(in_array($kind, self::KINDS, true) && ctype_digit($id) && (int) $id > 0, 422, 'Referência inválida.');
        [$connection,$item] = $this->access($user, $tenant);
        $generation = $connection->metadata['session_generation'] ?? null;
        $authorizedLists = [];
        $project = $this->context($connection, $item);
        $this->lumo->initialize($connection, $project, true);
        if (in_array($kind, ['presentation', 'writeback', 'investigation'], true)) {
            // Estes tipos têm list/pull no Lumo, mas não possuem o comando info.
            $row = collect($this->rows($connection, $project, $kind))->first(fn ($row) => (string) $row['id'] === $id);
            abort_unless($row, 404, 'Recurso não disponível para esta sessão.');
            $ref = $kind.':'.$id;
            try {
                $this->refreshSnapshotFiles($connection, $project, $ref);
                $body = $this->workingMetadata($project, [$ref])[$ref] ?? null;
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                    throw $e;
                }
                // Uma cópia antiga do disco não substitui uma leitura atual autorizada.
                $body = null;
            }

            $this->assertSession($connection, $generation);

            return ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resource' => $this->summary($kind, $row, $body), 'details' => $this->redact($body ?? ['id' => $id, 'nome' => $row['nome'] ?? $row['name'] ?? $id]), 'metadata_complete' => is_array($body), 'metadata_message' => is_array($body) ? null : 'O recurso está disponível, mas o servidor não forneceu sua configuração de autoria para esta sessão.', 'overview' => app(BiResourceOverview::class)->build($kind, $body ?? []), 'related' => []];
        }
        $row = null;
        if ($kind !== 'app') {
            $authorizedLists[$kind] = $this->rows($connection, $project, $kind);
            $row = collect($authorizedLists[$kind])->first(fn (array $row): bool => (string) $row['id'] === $id);
            abort_unless($row, 404, 'Recurso não disponível neste tenant para sua sessão.');
        }
        $data = $this->lumo->run($connection, ['info', $kind.':'.$id, ...($kind === 'app' ? ['--full'] : [])], $project, 60)['data'];
        $payload = is_array($data) ? ($data['payload'] ?? $data) : null;
        if ($kind === 'credential') {
            for ($depth = 0; $depth < 3 && is_array($payload) && ! array_key_exists('body', $payload) && is_array($payload['payload'] ?? null); $depth++) {
                $payload = $payload['payload'];
            }
        }
        if (is_array($payload) && isset($payload['header']['tenantId']) && (string) $payload['header']['tenantId'] !== $tenant) {
            throw new RuntimeException('O recurso retornou fora do tenant autorizado.');
        }
        $body = is_array($payload) ? ($payload['body'] ?? $payload) : null;
        if (! is_array($body)) {
            throw new RuntimeException('Os detalhes do recurso não retornaram um formato reconhecido.');
        }
        if ($kind !== 'app' && array_key_exists('id', $body) && ((! is_int($body['id']) && ! is_string($body['id'])) || (string) $body['id'] !== $id)) {
            throw new RuntimeException($kind === 'table' ? 'O BI retornou detalhes de uma tabela diferente da solicitada.' : 'O BI retornou detalhes de um recurso diferente do solicitado.');
        }
        if ($kind === 'app') {
            $header = is_array($payload['header'] ?? null) ? $payload['header'] : [];
            foreach (['id' => $id, 'kind' => $kind] as $field => $expected) {
                if (array_key_exists($field, $header) && ((! is_int($header[$field]) && ! is_string($header[$field])) || (string) $header[$field] !== $expected)) {
                    throw new RuntimeException('O BI retornou detalhes de uma aplicação diferente da solicitada.');
                }
            }
            // app info --full is a live read; its complete identity avoids another full tenant listing.
            $confirmed = isset($header['tenantId'], $header['id'], $header['kind']) && (string) $header['tenantId'] === $tenant;
            $row = $confirmed ? ['id' => $id] : collect($this->rows($connection, $project, $kind))->first(fn (array $row): bool => (string) $row['id'] === $id);
            abort_unless($row, 404, 'Recurso não disponível neste tenant para sua sessão.');
        }
        $metadataComplete = true;
        if (in_array($kind, ['flow', 'table', 'schedule', 'agent', 'credential'], true)) {
            try {
                $this->refreshSnapshotFiles($connection, $project, $kind.':'.$id);
                $working = $this->workingMetadata($project, [$kind.':'.$id])[$kind.':'.$id] ?? null;
                if (is_array($working)) {
                    $body = array_replace($working, $body);
                    if ($kind === 'table' && is_array($body['columns'] ?? null) && array_is_list($body['columns'])) {
                        foreach ($body['columns'] as $index => $column) {
                            if (! is_array($column) || ! is_string($column['column_name'] ?? null) || ! is_string($column['data_type'] ?? null)) {
                                $metadataComplete = false;

                                continue;
                            }
                            $key = $column['column_name'].' ('.$column['data_type'].')';
                            $authoring = $working['columns'][$key] ?? null;
                            if (! is_array($authoring)) {
                                $metadataComplete = false;

                                continue;
                            }
                            $presentation = array_intersect_key($authoring, array_flip(['label', 'description', 'human_description', 'mask', 'role', 'semantic_role', 'defaultBehavior']));
                            $body['columns'][$index] = array_replace($column, $presentation);
                        }
                    }
                } else {
                    $metadataComplete = false;
                }
            } catch (RuntimeException $e) {
                if ($e instanceof BiBusy || $connection->fresh()->status !== 'connected') {
                    throw $e;
                }
                $metadataComplete = false;
            }
        }
        $summary = $this->summary($kind, $row, $body);
        $credentialManagement = null;
        if ($kind === 'credential') {
            $driver = app(BiCredentialTypes::class)->driver((string) ($body['type'] ?? ''));
            $rawSqlServer = $driver === 'sqlserver' && filter_var($body['config']['useconnectionstring'] ?? false, FILTER_VALIDATE_BOOL);
            $credentialManagement = ['can_edit' => $driver !== null && ! $rawSqlServer && $user->role !== 'viewer' && ! $this->policy->isReadOnly($tenant, $connection), 'message' => $rawSqlServer ? 'Conexão SQL Server em formato avançado; o teste e a consulta de estrutura estão disponíveis.' : ($driver === null ? 'O formulário de edição ainda não está disponível para este tipo. Use as consultas para conferir a fonte.' : null)];
        }
        if ($kind === 'credential') {
            $body = array_intersect_key($body, array_flip(['id', 'nome', 'name', 'type', 'ativo', 'descricao']));
        }

        $overview = app(BiResourceOverview::class)->build($kind, $body);
        $related = [];
        if ($includeRelations && in_array($kind, ['app', 'flow', 'table', 'schedule', 'dw-desk', 'bi-desk', 'credential', 'agent'], true)) {
            $origin = $body['cloned_from'] ?? $body['_state']['cloned_from'] ?? null;
            $publicationOrigin = in_array($kind, ['app', 'flow'], true) && $summary['state'] === 'draft' && (is_string($origin) || is_int($origin)) && ctype_digit((string) $origin) && (int) $origin > 0 ? (string) $origin : null;
            $scope = app(BiRelationCatalog::class)->read($this, $connection, $project, $kind, $id, $body, Cache::get($this->snapshotKey($connection, $tenant)), $authorizedLists, $publicationOrigin);
            $snapshot = $scope['snapshot'];
            $metadata = $scope['metadata'];
            $metadata[$kind.':'.$id] = $body;
            $related = app(BiResourceRelations::class)->build($kind, $id, $body, $snapshot, $metadata, $scope['credential_keys']);
        }

        $management = null;
        if ($includeRelations && in_array($kind, ['app', 'flow'], true) && $summary['state'] === 'draft' && $user->role !== 'viewer' && ! $this->policy->isReadOnly($tenant, $connection)) {
            $ref = $kind.':'.$id;
            $managed = ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $tenant)->where('resource', $ref)->where('status', 'draft')->whereIn('project_id', Project::where('user_id', $user->id)->where('tenant_id', $tenant)->select('id'))->first();
            $deletionPending = app(BiMutationGuard::class)->reason($user->id, $tenant, $ref) !== null;
            if ($managed || $deletionPending || $kind === 'flow') {
                $ownerProject = $managed ? Project::find($managed->project_id) : Project::where('user_id', $user->id)->where('tenant_id', $tenant)->where('source_ref', $ref)->where('status', '!=', 'archived')->first();
                $management = ['project_id' => $ownerProject?->id, 'deletion_pending' => $deletionPending, 'can_delete_draft' => $managed !== null && ! $deletionPending, 'can_publish' => ! $deletionPending && $metadataComplete && $ownerProject?->status !== 'archived' && ($kind === 'flow' || $ownerProject?->source_ref === $ref), 'publication_origin' => null];
                $origin = $body['cloned_from'] ?? $body['_state']['cloned_from'] ?? null;
                $original = collect($snapshot['resources'][$kind] ?? [])->first(fn (array $row): bool => (string) $row['id'] === (string) $origin && $row['state'] === 'published' && ! empty($row['desk_id']));
                if ($original && ($kind === 'app' || (isset($body['originalTableId']) && (string) $body['originalTableId'] === (string) ($metadata[$original['ref']]['table']['id'] ?? '')))) {
                    $originDesk = collect($snapshot['resources'][$kind === 'flow' ? 'dw-desk' : 'bi-desk'] ?? [])->first(fn (array $row): bool => (string) $row['id'] === (string) $original['desk_id']);
                    $management['publication_origin'] = ['resource' => $original['ref'], 'name' => $original['name'], 'desk_id' => (int) $original['desk_id'], 'desk_name' => $originDesk['name'] ?? 'Mesa #'.$original['desk_id']];
                }
            }
        }

        $this->assertSession($connection, $generation);

        return ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resource' => $summary, 'management' => $management, 'credential_management' => $credentialManagement, 'details' => $this->redact($body), 'overview' => $this->redact($overview), 'related' => $related, 'viewer_available' => $kind === 'app' && config('horus.viewer.enabled') && $user->role !== 'viewer' && ! $this->policy->isReadOnly($tenant, $connection), 'metadata_complete' => $metadataComplete, 'metadata_message' => $metadataComplete ? null : 'O resumo atual está disponível, mas os metadados de autoria não foram fornecidos nesta consulta. Alguns vínculos e configurações podem não aparecer.'];
    }

    private function variableDetail(User $user, string $tenant, string $id): array
    {
        abort_unless(strlen($id) <= 512 && strlen($id) % 2 === 0 && ctype_xdigit($id), 422, 'Identificador de variável inválido.');
        [$connection,$item] = $this->access($user, $tenant);
        $project = $this->context($connection, $item);
        $this->lumo->initialize($connection, $project, true);
        $row = collect($this->rows($connection, $project, 'variables'))->firstWhere('id', $id);
        abort_unless($row, 404, 'Variável não disponível para esta sessão.');

        return ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resource' => $this->summary('variables', $row), 'details' => ['name' => $row['nome'], 'reference' => $row['reference'], 'value' => '[protegido]', 'message' => 'Valores não são enviados ao navegador ou à IA. Use a referência no recurso do BI.']];
    }

    public function preview(User $user, string $tenant, string $appId, ?int $dashboard = null): array
    {
        abort_unless(ctype_digit($appId) && (int) $appId > 0, 422, 'Aplicação inválida.');
        [$connection,$item] = $this->access($user, $tenant);
        $project = $this->context($connection, $item);
        $this->lumo->initialize($connection, $project, true);

        return $this->lumo->run($connection, ['app', 'dashboard-preview', 'app:'.$appId, '--limit', '20', ...($dashboard ? ['--dashboard', (string) $dashboard] : [])], $project, 120);
    }

    public function image(User $user, string $tenant, string $appId, ?int $dashboard = null): string
    {
        $this->detail($user, $tenant, 'app', $appId, includeRelations: false);
        [$connection,$item] = $this->access($user, $tenant);
        $project = $this->context($connection, $item);
        $directory = app(WorkspaceFiles::class)->path($project).'/exports/previews';
        File::ensureDirectoryExists($directory, 0700, true);
        $target = $directory.'/app-'.$appId.'-'.($dashboard ?? 'main').'.png';
        if (is_file($target) && filemtime($target) > time() - 120) {
            return $target;
        }
        $temporary = $directory.'/render-'.bin2hex(random_bytes(12)).'.png';
        try {
            $this->lumo->run($connection, ['app', 'export', 'app:'.$appId, '--image-format', 'png', '--output', $temporary, '--timeout', '90s', ...($dashboard ? ['--dashboard', (string) $dashboard] : [])], $project, 100);
            $size = is_file($temporary) ? getimagesize($temporary) : false;
            if (! $size || ($size['mime'] ?? '') !== 'image/png' || filesize($temporary) > 20_000_000) {
                throw new RuntimeException('O G-Analise não retornou uma imagem PNG válida para esta prévia.');
            }
            chmod($temporary, 0600);
            rename($temporary, $target);

            return $target;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $sensitive = ['password', 'senha', 'pass', 'accesstoken', 'refreshtoken', 'authorization', 'apikey', 'clientsecret', 'connectionstring', 'dsn', 'token', 'secret', 'privatekey', 'jsonkey', 'odbcconnection', 'bearertoken', 'authtoken', 'tokenkey', 'credentialkey', 'credential'];
        foreach ($value as $key => $item) {
            $value[$key] = in_array(str_replace(['_', '-'], '', strtolower((string) $key)), $sensitive, true) ? '[protegido]' : $this->redact($item);
        }

        return $value;
    }
}
