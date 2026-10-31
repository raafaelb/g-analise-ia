<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use RuntimeException;

class BiFlowPublications
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo, private TenantPolicy $policy) {}

    public function plan(Connection $connection, Project $project, array $arguments): array
    {
        $ref = $arguments['resource'] ?? '';
        $desk = $arguments['desk'] ?? null;
        $mode = $arguments['publish_mode'] ?? 'new';
        $copyData = $arguments['copy_data'] ?? false;
        if (! is_string($ref) || ! preg_match('/^flow:[1-9][0-9]*$/D', $ref) || ! is_int($desk) || $desk < 1 || ! in_array($mode, ['new', 'replace'], true) || ! is_bool($copyData) || ($copyData && $mode !== 'replace')) {
            throw new RuntimeException('Escolha o ETL, uma mesa de dados e o modo de publicação. Copiar dados só é permitido ao substituir a versão original.');
        }
        $generation = $connection->metadata['session_generation'] ?? null;
        $this->policy->assertCommandAllowed((string) $project->tenant_id, ['flow', 'publish', $ref], $connection);
        $context = $this->context($connection, $project);
        $destination = collect($this->catalog->rows($connection, $context, 'dw-desk'))->first(fn (array $row): bool => (string) $row['id'] === (string) $desk);
        if (! $destination) {
            throw new RuntimeException('A mesa de dados não está disponível neste tenant. Escolha uma mesa de dados autorizada; mesas de aplicações têm outra finalidade.');
        }
        $source = $this->body($connection, $project, $context, $ref);
        if ($this->desk($source) !== null) {
            throw new RuntimeException('Este ETL já está publicado. Trabalhe em uma cópia antes de preparar outra publicação.');
        }
        $tableRefs = $this->tableReferences($source);
        $target = ['resource' => $ref, 'name' => $this->name($source), 'sha256' => null];
        $tableMap = array_combine($tableRefs, $tableRefs);
        if ($mode === 'replace') {
            $origin = $this->id($source['cloned_from'] ?? null);
            $originalTable = $this->id($source['originalTableId'] ?? null);
            $primary = $this->id($source['table']['id'] ?? null);
            if ($origin === null || $originalTable === null || $primary === null || count($tableRefs) !== 1 || $tableRefs[0] !== 'table:'.$primary || 'flow:'.$origin === $ref) {
                throw new RuntimeException('O BI não informou uma associação completa entre esta cópia e os destinos originais. Confira a origem ou publique como novo ETL.');
            }
            $original = $this->body($connection, $project, $context, 'flow:'.$origin);
            if ($this->desk($original) !== $desk || $this->id($original['table']['id'] ?? null) !== $originalTable || ! in_array('table:'.$originalTable, $this->tableReferences($original), true)) {
                throw new RuntimeException('A substituição exige a mesa e a tabela da versão original confirmadas no BI. Atualize o catálogo e confira o destino.');
            }
            $target = ['resource' => 'flow:'.$origin, 'name' => $this->name($original), 'sha256' => $this->digest($original)];
            $tableMap = ['table:'.$primary => 'table:'.$originalTable];
        }
        $tables = [];
        foreach ($tableRefs as $tableRef) {
            $table = $this->body($connection, $project, $context, $tableRef);
            $owner = $this->id($table['originFlowId'] ?? null);
            $primary = $this->id($source['table']['id'] ?? null);
            if ($this->desk($table) !== null || ($owner !== null && 'flow:'.$owner !== $ref) || ($owner === null && $tableRef !== 'table:'.$primary)) {
                throw new RuntimeException('Um destino não foi confirmado como tabela privada deste ETL. Confira o vínculo antes de publicar.');
            }
            $expectedRef = $tableMap[$tableRef];
            $previous = $expectedRef === $tableRef ? null : $this->body($connection, $project, $context, $expectedRef);
            if ($previous !== null && ($this->desk($previous) !== $desk || ($this->id($previous['originFlowId'] ?? null) !== null && 'flow:'.$this->id($previous['originFlowId']) !== $target['resource']))) {
                throw new RuntimeException('A tabela original não foi confirmada na mesa de dados esperada.');
            }
            $schema = $this->tableConfiguration($table);
            $tables[] = ['source_ref' => $tableRef, 'target_ref' => $expectedRef, 'name' => $this->name($table), 'target_name' => $previous === null ? $this->name($table) : $this->name($previous), 'source_sha256' => $this->digest($table), 'target_sha256' => $previous === null ? null : $this->digest($previous), 'schema_sha256' => $this->digest($schema), 'column_count' => count($schema['columns']), 'schema_changes' => $previous === null ? null : $this->schemaChanges($this->tableConfiguration($previous), $schema)];
        }
        $this->assertGeneration($connection, $generation, $project);

        return [
            'kind' => 'flow', 'mode' => $mode, 'copy_data' => $copyData,
            'source' => ['resource' => $ref, 'name' => $this->name($source), 'node_count' => count($source['nodes']['Processors']), 'sha256' => $this->digest($source)],
            'destination' => ['id' => $desk, 'resource' => 'dw-desk:'.$desk, 'name' => (string) ($destination['nome'] ?? $destination['name'] ?? 'Mesa #'.$desk)],
            'target' => $target, 'tables' => $tables,
            'structure_sha256' => $this->digest($this->structure($source, $tableMap)),
            'connection_generation' => $generation,
            'impact' => $mode === 'replace' ? 'A versão original receberá as etapas e o esquema da cópia. As aplicações que usam a tabela da cópia podem voltar à tabela original. Mudanças de estrutura podem exigir nova carga e afetar rótulos e formatos.' : 'O ETL e suas tabelas ficarão compartilhados na mesa escolhida como uma nova versão. As versões existentes não serão substituídas.',
            'data_impact' => $copyData ? 'Confira se a tabela de teste contém os dados pretendidos. A confirmação desta publicação não comprova a conclusão da cópia dos dados.' : 'Esta revisão não solicita copiar dados da versão de teste. A publicação não executa carga, não cria agenda e não comprova atualização dos dados.',
        ];
    }

    public function verify(Connection $connection, Project $project, array $plan, bool $requireOriginalSession = true): array
    {
        $generation = $connection->metadata['session_generation'] ?? null;
        $context = $this->context($connection, $project);
        $ref = $plan['target']['resource'] ?? '';
        $body = $this->body($connection, $project, $context, $ref);
        if (($plan['kind'] ?? null) !== 'flow' || $this->desk($body) !== ($plan['destination']['id'] ?? null) || $this->name($body) !== ($plan['source']['name'] ?? null) || ! hash_equals((string) ($plan['structure_sha256'] ?? ''), $this->digest($this->structure($body)))) {
            throw new RuntimeException('O destino ou as etapas do ETL publicado não correspondem à revisão autorizada. Confira o resultado sem reenviar a publicação.');
        }
        $replaced = ($plan['mode'] ?? '') === 'replace';
        if ($replaced && $this->listed($connection, $context, $plan['source']['resource'] ?? '')) {
            throw new RuntimeException('O BI ainda mantém a cópia separada; a substituição do ETL não foi confirmada.');
        }
        $expected = array_column($plan['tables'] ?? [], 'target_ref');
        sort($expected);
        if ($this->tableReferences($body) !== $expected) {
            throw new RuntimeException('As tabelas do ETL publicado não correspondem aos destinos revisados.');
        }
        foreach ($plan['tables'] ?? [] as $table) {
            $saved = $this->body($connection, $project, $context, $table['target_ref']);
            if ($this->desk($saved) !== $plan['destination']['id'] || ! hash_equals($table['schema_sha256'], $this->digest($this->tableConfiguration($saved))) || ($this->id($saved['originFlowId'] ?? null) !== null && 'flow:'.$this->id($saved['originFlowId']) !== $ref)) {
                throw new RuntimeException('O esquema ou o vínculo de uma tabela publicada ainda precisa de conferência.');
            }
            if ($replaced && $table['source_ref'] !== $table['target_ref'] && $this->listed($connection, $context, $table['source_ref'])) {
                throw new RuntimeException('A tabela da cópia ainda consta separadamente; confira a incorporação no BI.');
            }
        }
        $this->assertGeneration($connection, $generation, $project);
        if ($requireOriginalSession && $generation !== ($plan['connection_generation'] ?? null)) {
            throw new RuntimeException('A sessão mudou durante a confirmação da publicação.');
        }

        return ['verified' => true, 'kind' => 'flow', 'mode' => $plan['mode'], 'resource' => $ref, 'name' => $this->name($body), 'node_count' => count($body['nodes']['Processors']), 'table_refs' => $expected, 'destination' => $plan['destination'], 'copy_data' => $plan['copy_data'], 'data_verified' => false, 'requires_data_check' => true, 'message' => 'Publicação e configuração conferidas. Confira separadamente os dados, os rótulos e formatos, a agenda e uma execução concluída antes de considerar a fonte atualizada.', 'checked_at' => now()->toIso8601String()];
    }

    private function context(Connection $connection, Project $project): Project
    {
        $user = User::find($project->user_id);
        if (! $user?->canStartWork() || $user->role === 'viewer' || $connection->user_id !== $project->user_id) {
            throw new RuntimeException('A permissão atual não permite publicar este ETL.');
        }
        [$current, $tenant] = $this->catalog->access($user, (string) $project->tenant_id);
        if ($current->id !== $connection->id || ($current->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A sessão BI mudou. Prepare uma nova revisão.');
        }
        $context = $this->catalog->context($current, $tenant);
        $this->lumo->initialize($current, $context, true);

        return $context;
    }

    private function body(Connection $connection, Project $project, Project $context, string $ref): array
    {
        if (! preg_match('/^(flow|table):([1-9][0-9]*)$/D', $ref, $match)) {
            throw new RuntimeException('Recurso inválido para a conferência da publicação de ETL.');
        }
        $detail = $this->catalog->detail(User::findOrFail($project->user_id), (string) $project->tenant_id, $match[1], $match[2], false);
        $body = $this->catalog->workingMetadata($context, [$ref])[$ref] ?? null;
        if (($detail['metadata_complete'] ?? false) !== true || ! is_array($body) || (string) ($detail['resource']['id'] ?? '') !== $match[2] || (string) ($detail['tenant']['id'] ?? '') !== (string) $project->tenant_id || ($detail['resource']['state'] ?? null) !== ($this->desk($body) === null ? 'draft' : 'published')) {
            throw new RuntimeException('O BI não retornou a configuração completa e atual de um recurso da publicação.');
        }

        return $body;
    }

    private function listed(Connection $connection, Project $context, string $ref): bool
    {
        if (! preg_match('/^(flow|table):([1-9][0-9]*)$/D', $ref, $match)) {
            throw new RuntimeException('Referência inválida no plano de publicação.');
        }

        return collect($this->catalog->rows($connection, $context, $match[1]))->contains(fn (array $row): bool => (string) $row['id'] === $match[2]);
    }

    private function tableReferences(array $body): array
    {
        $processors = $body['nodes']['Processors'] ?? null;
        if (! is_array($processors) || ! array_is_list($processors) || $processors === []) {
            throw new RuntimeException('Confira as etapas do ETL antes de publicar.');
        }
        $refs = [];
        foreach ($processors as $node) {
            if (! is_array($node) || ! is_string($node['kind'] ?? null)) {
                throw new RuntimeException('Uma etapa do ETL não foi reconhecida.');
            }
            if ($node['kind'] === 'InsertDatawarehouse') {
                $id = $this->id($node['options']['TableID'] ?? null);
                if ($id === null) {
                    throw new RuntimeException('Crie e confira a tabela de destino do ETL antes de publicar.');
                }
                $refs[] = 'table:'.$id;
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs);
        if (isset($body['table']['id']) && ! in_array('table:'.$this->id($body['table']['id']), $refs, true)) {
            throw new RuntimeException('A tabela principal não corresponde às etapas do ETL.');
        }

        return $refs;
    }

    private function structure(array $body, array $tableMap = []): array
    {
        $this->tableReferences($body);
        $processors = $body['nodes']['Processors'];
        $ids = [];
        foreach ($processors as $index => $node) {
            $id = $node['internalId'] ?? null;
            if (! is_string($id) || $id === '' || isset($ids[$id])) {
                throw new RuntimeException('O ETL contém etapas sem identificação ou duplicadas.');
            }
            $ids[$id] = $index;
            if (isset($node['complete_id']) && is_string($node['complete_id']) && $node['complete_id'] !== '') {
                if (isset($ids[$node['complete_id']]) && $ids[$node['complete_id']] !== $index) {
                    throw new RuntimeException('Os vínculos das etapas do ETL são ambíguos.');
                }
                $ids[$node['complete_id']] = $index;
            }
        }
        foreach ($processors as &$node) {
            $inputs = $node['inputs'] ?? [];
            if (! is_array($inputs) || ! array_is_list($inputs)) {
                throw new RuntimeException('Os vínculos das etapas do ETL não foram reconhecidos.');
            }
            $node['inputs'] = array_map(function (mixed $input) use ($ids): int {
                if (! is_string($input) || ! array_key_exists($input, $ids)) {
                    throw new RuntimeException('Uma etapa referencia outra etapa inexistente.');
                }

                return $ids[$input];
            }, $inputs);
            if ($node['kind'] === 'InsertDatawarehouse') {
                $ref = 'table:'.$this->id($node['options']['TableID']);
                $node['options']['TableID'] = (int) substr($tableMap[$ref] ?? $ref, 6);
            }
            unset($node['metadata'], $node['internalId'], $node['complete_id']);
        }
        unset($node);
        $structure = array_intersect_key($body, array_flip(['nome', 'tokenId', 'load_type', 'load_type_column', 'write_mode', 'development_variables', 'key_sweep', 'tags']));
        $structure['nodes'] = [...$body['nodes'], 'Processors' => $processors];

        return $structure;
    }

    private function tableConfiguration(array $body): array
    {
        if (! is_array($body['columns'] ?? null) || $body['columns'] === []) {
            throw new RuntimeException('O esquema de uma tabela não está disponível para conferência.');
        }
        $columns = [];
        foreach ($body['columns'] as $key => $column) {
            if (! is_string($key) || ! preg_match('/^(.+) \((.+)\)$/uD', $key, $match) || ! is_array($column) || isset($columns[$match[1]])) {
                throw new RuntimeException('O esquema de uma tabela não foi reconhecido.');
            }
            $columns[$match[1]] = $match[2];
        }
        ksort($columns);

        return ['columns' => $columns, 'table_type' => $body['table_type'] ?? null, 'key_type' => $body['key_type'] ?? null, 'key_columns' => $body['key_columns'] ?? null, 'partition_column' => $body['partition_column'] ?? null];
    }

    private function schemaChanges(array $previous, array $next): array
    {
        $changed = [];
        foreach (array_intersect_key($next['columns'], $previous['columns']) as $name => $type) {
            if ($previous['columns'][$name] !== $type) {
                $changed[] = ['name' => $name, 'previous' => $previous['columns'][$name], 'next' => $type];
            }
        }

        return ['added' => array_keys(array_diff_key($next['columns'], $previous['columns'])), 'removed' => array_keys(array_diff_key($previous['columns'], $next['columns'])), 'changed_types' => $changed, 'storage_changed' => array_diff_key($previous, ['columns' => true]) !== array_diff_key($next, ['columns' => true])];
    }

    private function desk(array $body): ?int
    {
        if (! array_key_exists('deskId', $body) || ($body['deskId'] !== null && $this->id($body['deskId']) === null)) {
            throw new RuntimeException('O estado de publicação do recurso não foi confirmado.');
        }

        return $body['deskId'] === null ? null : (int) $body['deskId'];
    }

    private function id(mixed $id): ?string
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id) ? (string) $id : null;
    }

    private function name(array $body): string
    {
        if (! is_string($body['nome'] ?? null) || trim($body['nome']) === '') {
            throw new RuntimeException('O nome do recurso não foi confirmado pelo BI.');
        }

        return $body['nome'];
    }

    private function assertGeneration(Connection $connection, ?string $generation, Project $project): void
    {
        $current = $connection->fresh();
        $user = User::find($project->user_id);
        if ($current?->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation || ! $user?->canStartWork() || $user->role === 'viewer' || ! collect($current->metadata['tenants'] ?? [])->contains(fn (array $tenant): bool => (string) ($tenant['id'] ?? '') === (string) $project->tenant_id)) {
            throw new RuntimeException('A sessão mudou durante a consulta de publicação. Confira seus acessos e tente a conferência novamente.');
        }
    }

    private function digest(array $value): string
    {
        $sort = function (mixed $items) use (&$sort): mixed {
            if (! is_array($items)) {
                return $items;
            }
            if (! array_is_list($items)) {
                ksort($items);
            }

            return array_map($sort, $items);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
