<?php

namespace App\Services;

class BiResourceRelations
{
    /** @param array<string, list<array<string, mixed>>> $resources */
    public function deskCounters(array $resources, array $capabilities): array
    {
        foreach (['bi-desk' => ['app'], 'dw-desk' => ['table', 'flow', 'writeback']] as $kind => $children) {
            foreach ($resources[$kind] ?? [] as $index => $desk) {
                $counters = [];
                foreach ($children as $child) {
                    $available = ($capabilities[$child]['status'] ?? '') === 'available';
                    $members = array_filter($resources[$child] ?? [], fn (array $row): bool => (string) ($row['desk_id'] ?? '') === (string) $desk['id']);
                    $known = collect($resources[$child] ?? [])->every(fn (array $row): bool => $row['desk_known'] ?? array_key_exists('desk_id', $row));
                    $counters[] = ['kind' => $child, 'count' => $available ? count($members) : null, 'complete' => $available && $known];
                }
                $resources[$kind][$index]['contents'] = $counters;
            }
        }

        return $resources;
    }

    /** @return list<array<string, mixed>> */
    public function build(string $kind, string $id, array $body, array $snapshot, array $metadata, array $credentialKeys = []): array
    {
        $resources = $snapshot['resources'];
        $capabilities = $snapshot['capabilities'];
        $groups = [];
        $group = function (string $childKind, string $label, callable $match, bool $complete = true) use (&$groups, $resources, $capabilities): void {
            $capability = $capabilities[$childKind] ?? ['status' => 'unavailable', 'reason' => 'Esta área não retornou informações.'];
            $available = $capability['status'] === 'available';
            $items = $available ? array_values(array_filter($resources[$childKind] ?? [], $match)) : [];
            usort($items, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $groups[] = ['kind' => $childKind, 'label' => $label, 'status' => $capability['status'], 'reason' => $capability['reason'] ?? null, 'count' => $available ? count($items) : null, 'complete' => $available && $complete, 'items' => $items];
        };
        $known = fn (string $type): bool => collect($resources[$type] ?? [])->every(fn (array $row): bool => array_key_exists($type.':'.$row['id'], $metadata));
        $references = function (string $type, string $label, array $ids, bool $metadataComplete = true) use ($group, $resources): void {
            $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn (string $value): bool => ctype_digit($value) && (int) $value > 0)));
            $readableIds = array_map(fn (array $row): string => (string) $row['id'], $resources[$type] ?? []);
            $group($type, $label, fn (array $row): bool => in_array((string) $row['id'], $ids, true), $metadataComplete && array_diff($ids, $readableIds) === []);
        };

        if (in_array($kind, ['dw-desk', 'bi-desk'], true)) {
            $children = $kind === 'dw-desk' ? ['table' => 'Tabelas desta mesa', 'flow' => 'ETLs desta mesa', 'writeback' => 'Cadastros desta mesa'] : ['app' => 'Aplicações desta mesa'];
            foreach ($children as $type => $label) {
                $complete = collect($resources[$type] ?? [])->every(fn (array $row): bool => $row['desk_known'] ?? array_key_exists('desk_id', $row));
                $group($type, $label, fn (array $row): bool => (string) ($row['desk_id'] ?? '') === $id, $complete);
            }

            return $groups;
        }
        if (! empty($body['deskId'])) {
            $references($kind === 'app' ? 'bi-desk' : 'dw-desk', 'Mesa onde está publicado', [(string) $body['deskId']]);
        }
        if ($kind === 'app') {
            $references('table', 'Fontes de dados da aplicação', array_map(fn (array $binding): string => (string) ($binding['tableId'] ?? ''), $body['tables'] ?? []));
        }
        if ($kind === 'table') {
            $flowId = (string) ($body['originFlowId'] ?? '');
            if ($flowId !== '') {
                $references('flow', 'ETL que atualiza esta tabela', [$flowId]);
                $group('schedule', 'Agendas que atualizam esta fonte', fn (array $row): bool => $this->scheduleHasFlow($metadata['schedule:'.$row['id']] ?? [], $flowId), $known('schedule'));
            }
            $group('app', 'Aplicações que usam esta tabela', fn (array $row): bool => collect($metadata['app:'.$row['id']]['tables'] ?? [])->contains(fn (array $binding): bool => (string) ($binding['tableId'] ?? '') === $id), $known('app'));
        }
        if ($kind === 'flow') {
            $group('table', 'Tabelas produzidas por este ETL', fn (array $row): bool => (string) ($metadata['table:'.$row['id']]['originFlowId'] ?? '') === $id, $known('table'));
            $group('schedule', 'Agendas deste ETL', fn (array $row): bool => $this->scheduleHasFlow($metadata['schedule:'.$row['id']] ?? [], $id), $known('schedule'));
            $connections = $this->credentialReferences($body['nodes'] ?? [], $credentialKeys);
            $references('credential', 'Conexões utilizadas', $connections['ids'], $connections['complete']);
        }
        if ($kind === 'schedule') {
            $references('flow', 'ETLs desta agenda', array_map(fn (array $binding): string => (string) ($binding['flow_etl_id'] ?? ''), $body['flows'] ?? []));
        }
        if ($kind === 'credential') {
            $group('flow', 'ETLs que usam esta conexão', fn (array $row): bool => in_array($id, $this->credentialReferences($metadata['flow:'.$row['id']]['nodes'] ?? [], $credentialKeys)['ids'], true), $known('flow'));
        }
        if ($kind === 'agent') {
            foreach (['flow' => 'ETLs deste agente', 'schedule' => 'Agendas deste agente'] as $type => $label) {
                $group($type, $label, fn (array $row): bool => (string) ($metadata[$type.':'.$row['id']]['tokenId'] ?? '') === $id, $known($type));
            }
        } elseif (in_array($kind, ['flow', 'schedule'], true) && ! empty($body['tokenId'])) {
            $references('agent', 'Agente de execução', [(string) $body['tokenId']]);
        }

        return $groups;
    }

    private function scheduleHasFlow(array $schedule, string $id): bool
    {
        return collect($schedule['flows'] ?? [])->contains(fn (array $binding): bool => (string) ($binding['flow_etl_id'] ?? '') === $id);
    }

    /** @return array{ids: list<string>, complete: bool} */
    private function credentialReferences(array $nodes, array $keys): array
    {
        $ids = [];
        $visited = 0;
        $complete = true;
        $walk = function (array $values, int $depth = 0) use (&$walk, &$ids, &$visited, &$complete, $keys): void {
            if ($depth > 15 || $visited > 10000) {
                $complete = false;

                return;
            }
            foreach ($values as $key => $value) {
                $visited++;
                $name = strtolower(str_replace(['_', '-'], '', (string) $key));
                if (in_array($name, ['credential', 'credentialid'], true)) {
                    $candidate = is_array($value) ? ($value['id'] ?? $value['key'] ?? null) : $value;
                    if (is_int($candidate) || is_string($candidate)) {
                        $candidate = (string) $candidate;
                        if (isset($keys[$candidate])) {
                            $ids[] = (string) $keys[$candidate];
                        } else {
                            $reference = preg_replace('/^credential:/', '', $candidate);
                            if (ctype_digit($reference) && (int) $reference > 0) {
                                $ids[] = $reference;
                            } elseif ($candidate !== '') {
                                $complete = false;
                            }
                        }
                    } elseif ($candidate !== null) {
                        $complete = false;
                    }
                }
                if (is_array($value)) {
                    $walk($value, $depth + 1);
                }
            }
        };
        $walk($nodes);

        return ['ids' => array_values(array_unique($ids)), 'complete' => $complete];
    }
}
