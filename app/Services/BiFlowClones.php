<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use RuntimeException;

class BiFlowClones
{
    public function __construct(private BiCatalog $catalog) {}

    public function revision(Connection $connection, Project $project, string $ref): array
    {
        [$body, $detail] = $this->snapshot($connection, $project, $ref);
        $nodes = $this->nodes($body);

        return [
            'tenant_id' => $project->tenant_id,
            'resource' => $ref,
            'info' => ['data' => ['id' => $detail['resource']['id'], 'nome' => $detail['resource']['name']]],
            'changes' => [],
            'source_sha256' => $this->digest($body),
            'flow_clone' => [
                'node_count' => count($nodes),
                'node_kinds' => $this->kinds($nodes),
                'source_node_ids' => array_column($nodes, 'internalId'),
                'source_table_refs' => $this->tables($nodes),
                'load_type' => $body['load_type'] ?? null,
                'agent_id' => $body['tokenId'] ?? null,
                'source_state' => $detail['resource']['state'] ?? null,
                'impact' => 'Será duplicada a definição do ETL em Minha Mesa, com novas tabelas de destino quando houver. A cópia precisa de teste de carga antes de usar seus dados. Esta revisão não publica o ETL nem autoriza executar etapas ou substituir fontes de aplicações.',
            ],
        ];
    }

    public function confirm(Connection $connection, Project $project, string $ref, array $revision): array
    {
        [$body, $detail] = $this->snapshot($connection, $project, $ref);
        $nodes = $this->nodes($body);
        $expected = $revision['flow_clone'] ?? [];
        if (($detail['resource']['state'] ?? null) !== 'draft' || $ref === ($revision['resource'] ?? null)) {
            throw new RuntimeException('O BI não confirmou uma nova cópia em rascunho. Confira o catálogo antes de repetir a duplicação.');
        }
        if ($this->kinds($nodes) !== ($expected['node_kinds'] ?? null) || count($nodes) !== ($expected['node_count'] ?? null) || array_intersect(array_column($nodes, 'internalId'), $expected['source_node_ids'] ?? []) !== []) {
            throw new RuntimeException('As etapas da cópia precisam de conferência. Não reutilize os identificadores do ETL original nem repita a duplicação.');
        }
        $tables = $this->tables($nodes);
        if (count($tables) !== count($expected['source_table_refs'] ?? []) || array_intersect($tables, $expected['source_table_refs'] ?? []) !== []) {
            throw new RuntimeException('Os novos destinos do ETL não foram confirmados. Confira as tabelas antes de executar qualquer carga.');
        }
        [$source] = $this->snapshot($connection, $project, $revision['resource']);
        if (! hash_equals((string) ($revision['source_sha256'] ?? ''), $this->digest($source))) {
            throw new RuntimeException('A origem mudou durante a duplicação. A cópia já foi criada; confira ambas antes de continuar.');
        }

        return ['verified' => true, 'name' => $detail['resource']['name'], 'resource' => $ref, 'node_count' => count($nodes), 'table_refs' => $tables, 'nodes' => array_map(fn (array $node): array => ['id' => $node['internalId'], 'kind' => $node['kind'], 'description' => $node['description'] ?? ''], $nodes), 'requires_load_test' => true, 'message' => 'Cópia em rascunho confirmada, com novos identificadores de etapas e destinos. Confira e teste a carga no projeto antes de considerar os dados disponíveis.'];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function snapshot(Connection $connection, Project $project, string $ref): array
    {
        if (! preg_match('/^flow:([1-9][0-9]*)$/D', $ref, $match) || $connection->user_id !== $project->user_id) {
            throw new RuntimeException('Escolha um ETL válido do ambiente atual.');
        }
        $user = User::findOrFail($project->user_id);
        [$current, $tenant] = $this->catalog->access($user, (string) $project->tenant_id);
        if ($current->id !== $connection->id || ($current->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A sessão BI mudou. Prepare uma nova revisão.');
        }
        $detail = $this->catalog->detail($user, (string) $project->tenant_id, 'flow', $match[1], false);
        $body = $this->catalog->workingMetadata($this->catalog->context($current, $tenant), [$ref])[$ref] ?? null;
        if (($detail['metadata_complete'] ?? false) !== true || ! is_array($body) || (string) ($detail['resource']['id'] ?? '') !== $match[1] || (string) ($detail['tenant']['id'] ?? '') !== (string) $project->tenant_id) {
            throw new RuntimeException('O BI não retornou a configuração completa e atual deste ETL. Nenhuma nova duplicação foi autorizada.');
        }

        return [$body, $detail];
    }

    private function nodes(array $body): array
    {
        $nodes = $body['nodes']['Processors'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new RuntimeException('A estrutura das etapas deste ETL não foi reconhecida.');
        }
        $seen = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['internalId'] ?? null) || $node['internalId'] === '' || isset($seen[$node['internalId']]) || ! is_string($node['kind'] ?? null) || $node['kind'] === '') {
                throw new RuntimeException('O ETL contém etapas sem identificação ou duplicadas. Confira a origem antes de criar a cópia.');
            }
            $seen[$node['internalId']] = true;
        }

        return $nodes;
    }

    private function tables(array $nodes): array
    {
        $tables = [];
        foreach ($nodes as $node) {
            if ($node['kind'] !== 'InsertDatawarehouse') {
                continue;
            }
            $id = $node['options']['TableID'] ?? null;
            if ($id === null || $id === '' || $id === 0) {
                continue;
            }
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                throw new RuntimeException('Uma tabela de destino do ETL não foi identificada com segurança.');
            }
            $tables[] = 'table:'.$id;
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    private function kinds(array $nodes): array
    {
        $kinds = array_count_values(array_column($nodes, 'kind'));
        ksort($kinds);

        return $kinds;
    }

    private function digest(array $body): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($body), JSON_THROW_ON_ERROR));
    }
}
