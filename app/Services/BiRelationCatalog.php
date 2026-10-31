<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use RuntimeException;

class BiRelationCatalog
{
    public function __construct(private LumoClient $lumo) {}

    /** @return array{snapshot: array, metadata: array, credential_keys: array} */
    public function read(BiCatalog $catalog, Connection $connection, Project $context, string $kind, string $id, array $body, ?array $warm, array $authorizedLists = [], ?string $publicationOrigin = null): array
    {
        $expected = 'catalog-'.$context->tenant_id.'-'.($connection->metadata['session_generation'] ?? 'initial');
        if ($context->exists || $context->user_id !== $connection->user_id || $context->id !== $expected) {
            throw new RuntimeException('Somente o cache de leitura do catálogo pode carregar estes vínculos.');
        }
        $types = $this->types($kind, $body, $publicationOrigin);
        $metadata = [];
        if ($warm !== null) {
            $snapshot = $warm;
            if ($warm['metadata_fresh'] ?? false) {
                $refs = $this->metadataReferences($kind, $body, array_intersect_key($snapshot['resources'], array_fill_keys($types, true)), true, $publicationOrigin);
                $metadata = $refs === [] ? [] : $catalog->workingMetadata($context, $refs);
            }
        } else {
            $resources = [];
            $capabilities = [];
            $desk = in_array($kind, ['dw-desk', 'bi-desk'], true) ? $id : null;
            foreach ($types as $type) {
                try {
                    $resources[$type] = $authorizedLists[$type] ?? ($desk === null ? $catalog->rows($connection, $context, $type) : $this->deskRows($catalog, $connection, $context, $type, $desk));
                    $capabilities[$type] = ['status' => 'available', 'count' => count($resources[$type]), 'reason' => null];
                } catch (RuntimeException $e) {
                    $this->assertUsable($connection, $e);
                    $resources[$type] = [];
                    $capabilities[$type] = ['status' => $e->getCode() === 6 ? 'access_denied' : 'unavailable', 'count' => null, 'reason' => $e->getMessage()];
                }
            }
            $authorizedLists = [...$authorizedLists, ...$resources];
            $members = null;
            if (in_array($kind, ['dw-desk', 'bi-desk'], true)) {
                $members = $this->deskMembers($connection, $context, $kind.':'.$id, $types, $resources, $capabilities);
                if ($members !== null) {
                    foreach ($types as $type) {
                        $resources[$type] = array_values(array_filter($resources[$type], fn (array $row): bool => in_array((string) $row['id'], $members[$type], true)));
                    }
                }
            }
            $refs = $this->metadataReferences($kind, $body, $resources, false, $publicationOrigin);
            [$metadata, $fresh] = $this->documents($catalog, $connection, $context, $refs);
            if ($fresh && $members !== null && ! $this->confirmedDeskMembers($catalog, $id, $resources, $metadata)) {
                foreach ($types as $type) {
                    try {
                        $resources[$type] = $catalog->rows($connection, $context, $type);
                        $capabilities[$type] = ['status' => 'available', 'count' => count($resources[$type]), 'reason' => null];
                    } catch (RuntimeException $e) {
                        $this->assertUsable($connection, $e);
                        $resources[$type] = [];
                        $capabilities[$type] = ['status' => $e->getCode() === 6 ? 'access_denied' : 'unavailable', 'count' => null, 'reason' => $e->getMessage()];
                    }
                }
                $refs = $this->metadataReferences($kind, $body, $resources, false, $publicationOrigin);
                [$metadata, $fresh] = $this->documents($catalog, $connection, $context, $refs);
            }
            foreach ($resources as $type => $rows) {
                $resources[$type] = array_map(fn (array $row): array => $catalog->summary($type, $row, $metadata[$type.':'.$row['id']] ?? null), $rows);
            }
            $resources = $this->referencedDeskCounters($catalog, $connection, $context, $kind, $id, $body, $resources, $capabilities, $metadata, $authorizedLists);
            $snapshot = ['resources' => $resources, 'capabilities' => $capabilities, 'metadata_fresh' => $fresh];
        }
        $keys = [];
        if (in_array($kind, ['flow', 'credential'], true) && ($warm !== null || ($snapshot['capabilities']['credential']['status'] ?? null) === 'available')) {
            try {
                $readable = $authorizedLists['credential'] ?? null;
                if ($readable === null) {
                    $readable = $catalog->rows($connection, $context, 'credential');
                }
                $ids = array_map(fn (array $row): string => (string) $row['id'], $readable);
                $snapshot['resources']['credential'] = array_values(array_filter($snapshot['resources']['credential'] ?? [], fn (array $row): bool => in_array((string) $row['id'], $ids, true)));
                foreach ($catalog->rows($connection, $context, 'credential', false) as $credential) {
                    $key = $credential['extra']['key'] ?? $credential['key'] ?? null;
                    if (in_array((string) $credential['id'], $ids, true) && is_string($key) && $key !== '') {
                        $keys[$key] = (string) $credential['id'];
                    }
                }
            } catch (RuntimeException $e) {
                $this->assertUsable($connection, $e);
                $snapshot['resources']['credential'] = [];
                $snapshot['capabilities']['credential'] = ['status' => $e->getCode() === 6 ? 'access_denied' : 'unavailable', 'count' => null, 'reason' => $e->getMessage()];
            }
        }

        return ['snapshot' => $snapshot, 'metadata' => $metadata, 'credential_keys' => $keys];
    }

    private function deskRows(BiCatalog $catalog, Connection $connection, Project $context, string $kind, string $desk): array
    {
        $rows = $catalog->rows($connection, $context, $kind, true, $desk);
        foreach ($rows as $row) {
            if (! array_key_exists('deskId', $row) || (! is_int($row['deskId']) && ! is_string($row['deskId'])) || (string) $row['deskId'] !== $desk) {
                return $catalog->rows($connection, $context, $kind);
            }
        }

        return $rows;
    }

    private function types(string $kind, array $body, ?string $origin): array
    {
        $types = match ($kind) {
            'app' => ['table', 'bi-desk', ...($origin !== null ? ['app'] : [])],
            'table' => ['flow', 'schedule', 'app', 'dw-desk'],
            'flow' => ['table', 'schedule', 'credential', 'agent', 'dw-desk', ...($origin !== null ? ['flow'] : [])],
            'schedule' => ['flow', 'agent', 'dw-desk'],
            'credential' => ['flow', 'credential'],
            'agent' => ['flow', 'schedule'],
            'dw-desk' => ['table', 'flow', 'writeback'],
            'bi-desk' => ['app'],
            default => [],
        };
        if (empty($body['deskId']) && ! (in_array($kind, ['app', 'flow'], true) && $origin !== null)) {
            $types = array_values(array_diff($types, ['bi-desk', 'dw-desk']));
        }
        if (in_array($kind, ['flow', 'schedule'], true) && empty($body['tokenId'])) {
            $types = array_values(array_diff($types, ['agent']));
        }
        if ($kind === 'table' && empty($body['originFlowId'])) {
            $types = array_values(array_diff($types, ['flow', 'schedule']));
        }

        return $types;
    }

    /** @return list<string> */
    private function metadataReferences(string $kind, array $body, array $resources, bool $warm, ?string $origin): array
    {
        $plan = match ($kind) {
            'app' => $warm ? [] : ['table' => array_column($body['tables'] ?? [], 'tableId'), 'bi-desk' => [$body['deskId'] ?? null], 'app' => [$origin]],
            'table' => ['app' => null, 'schedule' => null, ...($warm ? [] : ['flow' => [$body['originFlowId'] ?? null], 'dw-desk' => [$body['deskId'] ?? null]])],
            'flow' => ['table' => null, 'schedule' => null, 'flow' => [$origin], ...($warm ? [] : ['credential' => null, 'agent' => [$body['tokenId'] ?? null], 'dw-desk' => [$body['deskId'] ?? null]])],
            'schedule' => $warm ? [] : ['flow' => array_column($body['flows'] ?? [], 'flow_etl_id'), 'agent' => [$body['tokenId'] ?? null], 'dw-desk' => [$body['deskId'] ?? null]],
            'credential' => ['flow' => null, ...($warm ? [] : ['credential' => null])],
            'agent' => ['flow' => null, 'schedule' => null],
            'dw-desk' => $warm ? [] : ['table' => null, 'flow' => null, 'writeback' => null],
            'bi-desk' => $warm ? [] : ['app' => null],
            default => [],
        };
        $refs = [];
        foreach ($plan as $type => $wanted) {
            $ids = $wanted === null ? null : array_map('strval', array_filter($wanted, fn (mixed $value): bool => (is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value > 0));
            foreach ($resources[$type] ?? [] as $row) {
                if ($ids === null || in_array((string) $row['id'], $ids, true)) {
                    $refs[] = $type.':'.$row['id'];
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /** @return array<string, list<string>>|null */
    private function deskMembers(Connection $connection, Project $context, string $reference, array $types, array $resources, array $capabilities): ?array
    {
        $desk = explode(':', $reference, 2)[1];
        $direct = array_fill_keys($types, []);
        $complete = true;
        foreach ($types as $type) {
            if (($capabilities[$type]['status'] ?? null) !== 'available') {
                return null;
            }
            foreach ($resources[$type] as $row) {
                if (! array_key_exists('deskId', $row) || ($row['deskId'] !== null && ! is_int($row['deskId']) && ! is_string($row['deskId']))) {
                    $complete = false;
                    break;
                }
                if ((string) ($row['deskId'] ?? '') === $desk) {
                    $direct[$type][] = (string) $row['id'];
                }
            }
        }
        if ($complete) {
            return $direct;
        }
        try {
            $data = $this->lumo->run($connection, ['info', $reference, '--dependents'], $context, 60)['data'];
            $payload = is_array($data) ? ($data['payload'] ?? null) : null;
            if (! is_array($payload) || ($data['dependents'] ?? null) !== true || ($payload['coverage'] ?? null) !== 'complete' || ! is_array($payload['items'] ?? null) || ! array_is_list($payload['items']) || count($payload['items']) > 20000) {
                return null;
            }
            $members = array_fill_keys($types, []);
            foreach ($types as $type) {
                if (($capabilities[$type]['status'] ?? null) !== 'available') {
                    return null;
                }
            }
            foreach ($payload['items'] as $item) {
                if (! is_array($item) || ! in_array($item['kind'] ?? null, $types, true) || ! (is_int($item['id'] ?? null) || is_string($item['id'] ?? null)) || ! ctype_digit((string) $item['id']) || (int) $item['id'] < 1 || ($item['reason'] ?? null) !== 'published to desk') {
                    return null;
                }
                $type = $item['kind'];
                $id = (string) $item['id'];
                if (! in_array($id, array_map(fn (array $row): string => (string) $row['id'], $resources[$type]), true)) {
                    return null;
                }
                $members[$type][] = $id;
            }

            return $members;
        } catch (RuntimeException $e) {
            $this->assertUsable($connection, $e);

            return null;
        }
    }

    private function referencedDeskCounters(BiCatalog $catalog, Connection $connection, Project $context, string $kind, string $id, array $body, array $resources, array $capabilities, array $metadata, array $authorizedLists): array
    {
        $desk = $body['deskId'] ?? null;
        if ((! is_int($desk) && ! is_string($desk)) || ! ctype_digit((string) $desk) || (int) $desk < 1) {
            return $resources;
        }
        $desk = (string) $desk;
        $deskType = $kind === 'app' ? 'bi-desk' : 'dw-desk';
        $metadata[$kind.':'.$id] = $body;
        foreach ($resources[$deskType] ?? [] as $index => $row) {
            if ((string) $row['id'] !== $desk) {
                continue;
            }
            $children = $deskType === 'bi-desk' ? ['app'] : ['table', 'flow', 'writeback'];
            $counters = [];
            foreach ($children as $child) {
                if (isset($capabilities[$child]) && $capabilities[$child]['status'] !== 'available') {
                    $counters[] = ['kind' => $child, 'count' => null, 'complete' => false];

                    continue;
                }
                try {
                    $all = array_key_exists($child, $authorizedLists);
                    $members = $all ? $authorizedLists[$child] : $catalog->rows($connection, $context, $child, true, $desk);
                    $summaries = $this->counterSummaries($catalog, $connection, $context, $child, $members, $metadata);
                    if (! $all && collect($summaries)->contains(fn (array $item): bool => $item['desk_known'] && $item['desk_id'] !== $desk)) {
                        $members = $catalog->rows($connection, $context, $child);
                        $summaries = $this->counterSummaries($catalog, $connection, $context, $child, $members, $metadata);
                    }
                    $counters[] = ['kind' => $child, 'count' => count(array_filter($summaries, fn (array $item): bool => $item['desk_id'] === $desk)), 'complete' => collect($summaries)->every(fn (array $item): bool => $item['desk_known'])];
                } catch (RuntimeException $e) {
                    $this->assertUsable($connection, $e);
                    $counters[] = ['kind' => $child, 'count' => null, 'complete' => false];
                }
            }
            $resources[$deskType][$index]['contents'] = $counters;
        }

        return $resources;
    }

    private function counterSummaries(BiCatalog $catalog, Connection $connection, Project $context, string $kind, array $rows, array &$metadata): array
    {
        $missing = [];
        foreach ($rows as $row) {
            $reference = $kind.':'.$row['id'];
            $summary = $catalog->summary($kind, $row, $metadata[$reference] ?? null);
            if (! $summary['desk_known']) {
                $missing[] = $reference;
            }
        }
        if ($missing !== []) {
            [$current] = $this->documents($catalog, $connection, $context, $missing);
            $metadata = [...$metadata, ...$current];
        }

        return array_map(fn (array $row): array => $catalog->summary($kind, $row, $metadata[$kind.':'.$row['id']] ?? null), $rows);
    }

    /** @return array{array, bool} */
    private function documents(BiCatalog $catalog, Connection $connection, Project $context, array $references): array
    {
        if ($references === []) {
            return [[], true];
        }
        try {
            foreach (array_chunk($references, 100) as $batch) {
                $this->lumo->run($connection, ['pull', ...$batch, '--discard-local'], $context, 150);
            }

            return [$catalog->workingMetadata($context, $references), true];
        } catch (RuntimeException $e) {
            $this->assertUsable($connection, $e);

            return [[], false];
        }
    }

    private function confirmedDeskMembers(BiCatalog $catalog, string $desk, array $resources, array $metadata): bool
    {
        foreach ($resources as $type => $rows) {
            foreach ($rows as $row) {
                $summary = $catalog->summary($type, $row, $metadata[$type.':'.$row['id']] ?? null);
                if (! $summary['desk_known'] || $summary['desk_id'] !== $desk) {
                    return false;
                }
            }
        }

        return true;
    }

    private function assertUsable(Connection $connection, RuntimeException $failure): void
    {
        if ($failure instanceof BiBusy || $connection->fresh()?->status !== 'connected') {
            throw $failure;
        }
    }
}
