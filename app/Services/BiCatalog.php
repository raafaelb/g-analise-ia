<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class BiCatalog
{
    public const KINDS = ['app', 'bi-desk', 'dw-desk', 'table', 'flow', 'schedule', 'agent', 'writeback', 'deck', 'presentation', 'investigation', 'credential', 'variables'];

    private const FOLDERS = ['app' => 'apps', 'bi-desk' => 'bi-desks', 'dw-desk' => 'dw-desks', 'table' => 'tables', 'flow' => 'flows', 'schedule' => 'schedules', 'agent' => 'agents', 'writeback' => 'writebacks', 'deck' => 'decks', 'presentation' => 'presentations', 'investigation' => 'investigations'];

    public function __construct(private LumoClient $lumo, private TenantPolicy $policy) {}

    public function connection(User $user): Connection
    {
        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus')->where('status', 'connected')->first();
        abort_unless($connection, 422, 'Conecte sua conta G-Analise BI para abrir o catálogo.');

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
        $key = 'bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$tenant;
        if (! $refresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        return Cache::lock($key.':lock', 200)->block(3, function () use ($connection, $item, $tenant, $key, $refresh) {
            if (! $refresh && ($cached = Cache::get($key))) {
                return $cached;
            }
            $project = $this->context($connection, $item);
            $this->lumo->initialize($connection, $project, true);
            $warnings = [];
            try {
                $this->lumo->run($connection, ['pull'], $project, 150);
            } catch (RuntimeException $e) {
                $warnings[] = 'Os metadados locais não foram atualizados; alguns detalhes serão carregados ao abrir o recurso.';
            }
            $metadata = $this->workingMetadata($project);
            $resources = [];
            $capabilities = [];
            foreach (self::KINDS as $kind) {
                try {
                    $rows = $this->rows($connection, $project, $kind);
                    $resources[$kind] = array_map(fn ($row) => $this->summary($kind, $row, $metadata[$kind.':'.($row['id'] ?? '')] ?? null), $rows);
                    $capabilities[$kind] = ['status' => 'available', 'count' => count($rows), 'reason' => null];
                } catch (RuntimeException $e) {
                    if ($connection->fresh()->status !== 'connected') {
                        throw $e;
                    }
                    $resources[$kind] = [];
                    $capabilities[$kind] = ['status' => $e->getCode() === 6 ? 'access_denied' : 'unavailable', 'count' => null, 'reason' => $e->getMessage()];
                }
            }
            $resources = app(BiResourceRelations::class)->deskCounters($resources, $capabilities);
            $tags = collect($resources)->flatten(1)->flatMap(fn ($row) => $row['tags'] ?? [])->unique()->sort()->values()->all();
            $snapshot = ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resources' => $resources, 'capabilities' => $capabilities, 'tags' => $tags, 'warnings' => $warnings, 'synced_at' => now()->toIso8601String()];
            Cache::put($key, $snapshot, now()->addSeconds(90));

            return $snapshot;
        });
    }

    public function rows(Connection $connection, Project $project, string $kind, bool $remote = true): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Tipo de recurso inválido.');
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
            $data = $this->lumo->run($connection, ['list', $kind, ...($remote ? ['--remote'] : []), '--limit', '500', '--offset', (string) $offset], $project, 60)['data'];
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

    public function workingMetadata(Project $project): array
    {
        $root = app(WorkspaceFiles::class)->path($project);
        $result = [];
        foreach (self::FOLDERS as $folder) {
            if (! is_dir($root.'/'.$folder)) {
                continue;
            }
            foreach (File::allFiles($root.'/'.$folder) as $file) {
                if ($file->isLink() || $file->getSize() > 2_000_000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                    continue;
                }
                try {
                    $parts = preg_split('/^---\s*$/m', File::get($file->getPathname()), 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    $header = Yaml::parse($parts[0]);
                    $body = Yaml::parse($parts[1]);
                    if (! is_array($header) || ! is_array($body) || (string) ($header['tenantId'] ?? '') !== (string) $project->tenant_id) {
                        continue;
                    }
                    $kind = $header['kind'] ?? '';
                    $id = $header['id'] ?? null;
                    if (in_array($kind, self::KINDS, true) && $id) {
                        $result[$kind.':'.$id] = $body;
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

    public function detail(User $user, string $tenant, string $kind, string $id): array
    {
        if ($kind === 'variables') {
            return $this->variableDetail($user, $tenant, $id);
        }
        abort_unless(in_array($kind, self::KINDS, true) && ctype_digit($id) && (int) $id > 0, 422, 'Referência inválida.');
        [$connection,$item] = $this->access($user, $tenant);
        $project = $this->context($connection, $item);
        $this->lumo->initialize($connection, $project, true);
        if (in_array($kind, ['presentation', 'writeback', 'investigation'], true)) {
            // Estes tipos têm list/pull no Lumo, mas não possuem o comando info.
            $row = collect($this->rows($connection, $project, $kind))->first(fn ($row) => (string) $row['id'] === $id);
            abort_unless($row, 404, 'Recurso não disponível para esta sessão.');
            $ref = $kind.':'.$id;
            try {
                $this->lumo->run($connection, ['pull', $ref], $project, 90);
                $body = $this->workingMetadata($project)[$ref] ?? null;
            } catch (RuntimeException $e) {
                if ($connection->fresh()->status !== 'connected') {
                    throw $e;
                }
                // Uma cópia antiga do disco não substitui uma leitura atual autorizada.
                $body = null;
            }

            return ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resource' => $this->summary($kind, $row, $body), 'details' => $this->redact($body ?? ['id' => $id, 'nome' => $row['nome'] ?? $row['name'] ?? $id]), 'metadata_complete' => is_array($body), 'metadata_message' => is_array($body) ? null : 'O recurso está disponível, mas o servidor não forneceu sua configuração de autoria para esta sessão.', 'overview' => app(BiResourceOverview::class)->build($kind, $body ?? []), 'related' => []];
        }
        $row = collect($this->rows($connection, $project, $kind))->first(fn (array $row): bool => (string) $row['id'] === $id);
        abort_unless($row, 404, 'Recurso não disponível neste tenant para sua sessão.');
        $data = $this->lumo->run($connection, ['info', $kind.':'.$id, ...($kind === 'app' ? ['--full'] : [])], $project, 60)['data'];
        $payload = is_array($data) ? ($data['payload'] ?? $data) : null;
        if (is_array($payload) && isset($payload['header']['tenantId']) && (string) $payload['header']['tenantId'] !== $tenant) {
            throw new RuntimeException('O recurso retornou fora do tenant autorizado.');
        }
        $body = is_array($payload) ? ($payload['body'] ?? $payload) : null;
        if (! is_array($body)) {
            throw new RuntimeException('Os detalhes do recurso não retornaram um formato reconhecido.');
        }
        $metadataComplete = true;
        if (in_array($kind, ['flow', 'table', 'schedule', 'agent'], true)) {
            try {
                $this->lumo->run($connection, ['pull', $kind.':'.$id], $project, 90);
                $working = $this->workingMetadata($project)[$kind.':'.$id] ?? null;
                if (is_array($working)) {
                    $body = array_replace($working, $body);
                } else {
                    $metadataComplete = false;
                }
            } catch (RuntimeException $e) {
                if ($connection->fresh()->status !== 'connected') {
                    throw $e;
                }
                $metadataComplete = false;
            }
        }
        $summary = $this->summary($kind, $row, $body);
        if ($kind === 'credential') {
            $body = array_intersect_key($body, array_flip(['id', 'nome', 'name', 'type', 'ativo', 'descricao']));
        }

        $overview = app(BiResourceOverview::class)->build($kind, $body);
        $related = [];
        if (in_array($kind, ['app', 'flow', 'table', 'schedule', 'dw-desk', 'bi-desk', 'credential', 'agent'], true)) {
            $snapshot = $this->snapshot($user, $tenant);
            $metadata = $this->workingMetadata($project);
            $metadata[$kind.':'.$id] = $body;
            $credentialKeys = [];
            if (in_array($kind, ['flow', 'credential'], true)) {
                try {
                    $authorizedIds = array_map(fn (array $credential): string => (string) $credential['id'], $this->rows($connection, $project, 'credential'));
                    foreach ($this->rows($connection, $project, 'credential', false) as $credential) {
                        $key = $credential['extra']['key'] ?? $credential['key'] ?? null;
                        if (in_array((string) $credential['id'], $authorizedIds, true) && is_string($key) && $key !== '') {
                            $credentialKeys[$key] = (string) $credential['id'];
                        }
                    }
                } catch (RuntimeException $e) {
                    if ($connection->fresh()->status !== 'connected') {
                        throw $e;
                    }
                }
            }
            $related = app(BiResourceRelations::class)->build($kind, $id, $body, $snapshot, $metadata, $credentialKeys);
        }

        $management = null;
        if ($kind === 'app' && $summary['state'] === 'draft' && $user->role !== 'viewer' && ! $this->policy->isReadOnly($tenant, $connection)) {
            $managed = ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $tenant)->where('resource', 'app:'.$id)->where('status', 'draft')->whereIn('project_id', Project::where('user_id', $user->id)->where('tenant_id', $tenant)->select('id'))->first();
            if ($managed) {
                $ownerProject = Project::find($managed->project_id);
                $management = ['project_id' => $managed->project_id, 'can_delete_draft' => true, 'can_publish' => $ownerProject?->status !== 'archived' && $ownerProject?->source_ref === 'app:'.$id, 'publication_origin' => null];
                $origin = $body['cloned_from'] ?? $body['_state']['cloned_from'] ?? null;
                $original = collect($snapshot['resources']['app'] ?? [])->first(fn (array $row): bool => (string) $row['id'] === (string) $origin && $row['state'] === 'published' && ! empty($row['desk_id']));
                if ($original) {
                    $originDesk = collect($snapshot['resources']['bi-desk'] ?? [])->first(fn (array $row): bool => (string) $row['id'] === (string) $original['desk_id']);
                    $management['publication_origin'] = ['resource' => $original['ref'], 'name' => $original['name'], 'desk_id' => (int) $original['desk_id'], 'desk_name' => $originDesk['name'] ?? 'Mesa #'.$original['desk_id']];
                }
            }
        }

        return ['tenant' => [...$item, 'read_only' => $this->policy->isReadOnly($tenant, $connection)], 'resource' => $summary, 'management' => $management, 'details' => $this->redact($body), 'overview' => $this->redact($overview), 'related' => $related, 'viewer_available' => $kind === 'app' && config('horus.viewer.enabled') && $user->role !== 'viewer' && ! $this->policy->isReadOnly($tenant, $connection), 'metadata_complete' => $metadataComplete, 'metadata_message' => $metadataComplete ? null : 'O resumo atual está disponível, mas os metadados de autoria não foram fornecidos nesta consulta. Alguns vínculos e configurações podem não aparecer.'];
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
        $this->detail($user, $tenant, 'app', $appId);
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
        $sensitive = ['password', 'senha', 'pass', 'accesstoken', 'refreshtoken', 'authorization', 'apikey', 'clientsecret', 'connectionstring', 'dsn', 'token', 'secret', 'privatekey', 'bearertoken', 'authtoken', 'tokenkey', 'credentialkey', 'credential'];
        foreach ($value as $key => $item) {
            $value[$key] = in_array(str_replace(['_', '-'], '', strtolower((string) $key)), $sensitive, true) ? '[protegido]' : $this->redact($item);
        }

        return $value;
    }
}
