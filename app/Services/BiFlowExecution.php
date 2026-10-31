<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use RuntimeException;

class BiFlowExecution
{
    public function __construct(private LumoClient $lumo, private BiCatalog $catalog) {}

    public function revision(Connection $connection, Project $project, array $arguments): array
    {
        [$body, $node] = $this->snapshot($connection, $project, $arguments);
        if (($body['deskId'] ?? null) !== null || ($body['_state'] ?? null) === 'published') {
            throw new RuntimeException('O fluxo está publicado. Crie uma cópia em Minha Mesa para testar nós sem alterar a fonte compartilhada.');
        }
        $diff = $this->lumo->run($connection, ['diff', $arguments['resource'], '--include-remote'], $project);
        if (($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
            throw new RuntimeException('Sincronize a configuração do fluxo antes de solicitar o teste desta etapa.');
        }
        $steps = $this->chain($body, $node);

        return ['tenant_id' => $project->tenant_id, 'resource' => $arguments['resource'], 'info' => ['data' => ['nome' => $body['nome'] ?? $arguments['resource'], 'deskId' => null, 'tokenId' => $body['tokenId'] ?? null]], 'changes' => [], 'connection_generation' => $connection->metadata['session_generation'] ?? null, 'flow_sha256' => $this->digest($body), 'etl' => ['node' => $node['internalId'], 'kind' => $node['kind'] ?? 'Etapa ETL', 'agent_id' => $body['tokenId'] ?? null, 'load_type' => $body['load_type'] ?? null, 'writes_datawarehouse' => collect($steps)->contains(fn (array $step): bool => $step['kind'] === 'InsertDatawarehouse'), 'steps' => $steps, 'impact' => 'O agente executará esta etapa e suas dependências com a conexão da fonte. A execução pode atualizar metadados e arquivos temporários; etapas de carga também gravam dados na tabela indicada. Esta autorização não publica o fluxo nem cria uma agenda.']];
    }

    public function assertTableCreation(Connection $connection, Project $project, array $arguments): void
    {
        [$body, $node] = $this->snapshot($connection, $project, ['resource' => $arguments['flow'], 'node' => $arguments['node']]);
        if (($body['deskId'] ?? null) !== null || ($body['_state'] ?? null) === 'published') {
            throw new RuntimeException('Crie a tabela somente a partir de um fluxo em rascunho.');
        }
        $destinations = collect($body['nodes']['Processors'])->filter(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['kind'] ?? '') === 'InsertDatawarehouse');
        if (($node['kind'] ?? '') !== 'InsertDatawarehouse') {
            $references = array_filter([$node['internalId'] ?? null, $node['complete_id'] ?? null], is_string(...));
            $matches = $destinations->filter(fn (array $candidate): bool => is_array($candidate['inputs'] ?? null) && count(array_intersect($references, $candidate['inputs'])) > 0);
            if ($matches->count() !== 1 || count($matches->first()['inputs']) !== 1 || ! is_array($node['metadata']['Fields'] ?? null) || $node['metadata']['Fields'] === []) {
                throw new RuntimeException('A etapa precisa de um esquema capturado e de uma única inserção diretamente vinculada. Confira o fluxo e teste a etapa de origem antes de criar a tabela.');
            }
        }
        if ($destinations->contains(fn (array $candidate): bool => ! empty($candidate['options']['TableID'])) || ! empty($body['table']['id']) || ! empty($body['tableId'])) {
            throw new RuntimeException('Já existe uma tabela vinculada a este fluxo ou etapa. Consulte info, columns e table_preview para continuar sem recriar a tabela ou perder dados.');
        }
    }

    public function preview(Connection $connection, Project $project, array $arguments): array
    {
        [, $node] = $this->snapshot($connection, $project, $arguments);
        if (empty($node['metadata']['File'])) {
            throw new RuntimeException('Esta etapa ainda não tem resultado para visualizar. Use run_flow_node para preparar o teste e obter autorização na conversa. Após a conclusão, use flow_preview. Não crie outro fluxo para repetir o diagnóstico.');
        }

        $result = $this->lumo->run($connection, ['flow', 'preview-node', $arguments['resource'], '--node', $arguments['node']], $project, 120);
        Audit::record($project->user_id, 'horus.flow_preview', 'Resultado de etapa ETL consultado.', $project->id, ['resource' => $arguments['resource'], 'node' => $arguments['node'], 'preview_available' => ($result['data']['data'] ?? null) !== null]);
        if (($result['data']['data'] ?? null) === null) {
            return ['preview_available' => false, 'schema' => $node['metadata']['Fields'] ?? [], 'message' => 'O BI não forneceu as linhas da prévia desta etapa. O esquema capturado continua disponível. Para investigar a estrutura original use credential_schema; para conferir uma carga no DW use table_preview. Um retorno nulo não prova que a fonte esteja vazia.'];
        }

        return ['preview_available' => true, ...$result];
    }

    public function run(Connection $connection, Project $project, array $arguments, Approval $approval): array
    {
        $before = $this->catalog->workingMetadata($project)[$arguments['resource']] ?? null;
        $approval->update(['payload' => [...$approval->payload, 'etl_attempt' => ['started_at' => now()->toIso8601String(), 'node' => $arguments['node']]]]);
        try {
            $result = $this->lumo->run($connection, ['flow', 'run-node', $arguments['resource'], '--node', $arguments['node']], $project, 420, false, fn () => AgentRun::whereKey($approval->agent_run_id)->where('status', 'running')->update(['heartbeat_at' => now()]));
        } catch (BiBusy $error) {
            $this->recordFinishedReceipt($approval, ['resource' => $arguments['resource'], 'node' => $arguments['node'], 'state' => 'not_dispatched', 'received_at' => now()->toIso8601String(), 'before_sha256' => is_array($before) ? $this->stateFingerprint($before) : null]);
            $approval->update(['payload' => [...$approval->payload, 'etl_attempt' => [...$approval->payload['etl_attempt'], 'not_dispatched' => true]]]);
            throw $error;
        }
        if (($result['data']['state'] ?? null) !== 'finished' || ($result['data']['node'] ?? null) !== $arguments['node']) {
            throw new RuntimeException('O BI não confirmou o término desta etapa. Confira o estado antes de repetir; uma resposta enfileirada não é sucesso.');
        }
        $receipt = ['resource' => $arguments['resource'], 'node' => $arguments['node'], 'state' => 'finished', 'received_at' => now()->toIso8601String(), 'before_sha256' => is_array($before) ? $this->stateFingerprint($before) : null];
        $this->recordFinishedReceipt($approval, $receipt);
        $result['execution'] = ['node' => $arguments['node'], 'native_command_finished' => true, 'message' => 'O comando oficial aguardou o resultado final da etapa. Confira a prévia, o esquema e a tabela de destino antes de afirmar que os dados solicitados estão corretos.'];
        try {
            $after = $this->catalog->workingMetadata($project)[$arguments['resource']] ?? null;
            $diff = $this->lumo->run($connection, ['diff', $arguments['resource'], '--include-remote'], $project);
            if (($diff['data']['workingVsServer']['equal'] ?? false) === true) {
                $result['workspace_refresh'] = ['verified' => true];
            } elseif (is_array($before) && is_array($after) && $this->digest($before) === $this->digest($after)) {
                $this->lumo->run($connection, ['pull', $arguments['resource']], $project, 90);
                $check = $this->lumo->run($connection, ['diff', $arguments['resource'], '--include-remote'], $project);
                $result['workspace_refresh'] = ['verified' => ($check['data']['workingVsServer']['equal'] ?? false) === true];
            } else {
                $result['workspace_refresh'] = ['verified' => false, 'message' => 'As edições locais foram preservadas. Confira diff e refresh_resource antes de continuar a autoria.'];
            }
        } catch (\Throwable) {
            $result['workspace_refresh'] = ['verified' => false, 'message' => 'A execução terminou, mas a atualização do documento local precisa ser conferida com refresh_resource. Não repita a execução para corrigir a sincronização.'];
        }

        if (($result['workspace_refresh']['verified'] ?? false) === true) {
            $current = $this->catalog->workingMetadata($project)[$arguments['resource']] ?? null;
            if (is_array($current)) {
                $this->recordFinishedReceipt($approval, [...$receipt, 'resulting_sha256' => $this->stateFingerprint($current)]);
            }
        }

        return $result;
    }

    public function finishedReceipt(Approval $approval, string $resource, string $node): ?array
    {
        $stored = $approval->payload['etl_attempt']['native_finished'] ?? null;
        if (! is_array($stored) || ! is_array($stored['receipt'] ?? null) || ! is_string($stored['signature'] ?? null)) {
            return null;
        }
        $receipt = $stored['receipt'];
        if (($receipt['state'] ?? null) !== 'finished' || ($receipt['resource'] ?? null) !== $resource || ($receipt['node'] ?? null) !== $node || ! hash_equals($this->receiptSignature($approval, $receipt), $stored['signature'])) {
            return null;
        }

        return $receipt;
    }

    public function wasNotDispatched(Approval $approval, string $resource, string $node): bool
    {
        $stored = $approval->payload['etl_attempt']['native_finished'] ?? null;
        $receipt = is_array($stored) ? ($stored['receipt'] ?? null) : null;

        return is_array($receipt) && ($receipt['state'] ?? null) === 'not_dispatched' && ($receipt['resource'] ?? null) === $resource && ($receipt['node'] ?? null) === $node && is_string($stored['signature'] ?? null) && hash_equals($this->receiptSignature($approval, $receipt), $stored['signature']);
    }

    public function stateFingerprint(array $body): string
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

        return hash_hmac('sha256', json_encode($sort($body), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function recordFinishedReceipt(Approval $approval, array $receipt): void
    {
        $approval->update(['payload' => [...$approval->payload, 'etl_attempt' => [...($approval->payload['etl_attempt'] ?? []), 'native_finished' => ['receipt' => $receipt, 'signature' => $this->receiptSignature($approval, $receipt)]]]]);
    }

    private function receiptSignature(Approval $approval, array $receipt): string
    {
        return hash_hmac('sha256', 'etl-finished-v1|'.$approval->id.'|'.$approval->user_id.'|'.$approval->project_id.'|'.$approval->fingerprint.'|'.json_encode($receipt, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function snapshot(Connection $connection, Project $project, array $arguments): array
    {
        $ref = (string) ($arguments['resource'] ?? '');
        $nodeId = (string) ($arguments['node'] ?? '');
        if (! preg_match('/^flow:([1-9][0-9]*)$/D', $ref, $match) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $nodeId)) {
            throw new RuntimeException('Informe um fluxo válido e o identificador de sua etapa.');
        }
        $user = User::findOrFail($project->user_id);
        [$currentConnection, $tenant] = $this->catalog->access($user, (string) $project->tenant_id);
        if ($currentConnection->id !== $connection->id || ($currentConnection->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A sessão BI mudou. Prepare uma nova revisão.');
        }
        $detail = $this->catalog->detail($user, (string) $project->tenant_id, 'flow', $match[1], false);
        $body = $this->catalog->workingMetadata($this->catalog->context($connection, $tenant))[$ref] ?? null;
        if (($detail['metadata_complete'] ?? false) !== true || ! is_array($body) || ! is_array($body['nodes']['Processors'] ?? null) || (string) ($detail['resource']['id'] ?? '') !== $match[1] || (string) ($detail['tenant']['id'] ?? '') !== (string) $project->tenant_id) {
            throw new RuntimeException('O BI não retornou a configuração completa do fluxo.');
        }
        $nodes = collect($body['nodes']['Processors'])->filter(fn (mixed $node): bool => is_array($node) && ($node['internalId'] ?? '') === $nodeId)->values();
        if ($nodes->count() !== 1) {
            throw new RuntimeException('A etapa não existe ou está duplicada neste fluxo. Consulte info antes de preparar o teste.');
        }

        return [$body, $nodes->first()];
    }

    private function chain(array $body, array $selected): array
    {
        $nodes = $body['nodes']['Processors'];
        $steps = [];
        $visiting = [];
        $visited = [];
        $visit = function (array $node) use (&$visit, &$steps, &$visiting, &$visited, $nodes): void {
            $id = $node['internalId'] ?? '';
            if ($id === '' || isset($visiting[$id])) {
                throw new RuntimeException('O fluxo contém uma dependência circular ou sem identificação. Valide o YAML antes de executar.');
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($node['inputs'] ?? [] as $input) {
                $matches = array_values(array_filter($nodes, fn (array $candidate): bool => ($candidate['internalId'] ?? '') === $input || ($candidate['complete_id'] ?? '') === $input));
                if (count($matches) !== 1) {
                    throw new RuntimeException('Uma dependência da etapa não foi identificada. Valide o fluxo antes de executar.');
                }
                $visit($matches[0]);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $options = is_array($node['options'] ?? null) ? $node['options'] : [];
            $steps[] = ['id' => $id, 'kind' => $node['kind'] ?? '', 'description' => $node['description'] ?? '', 'table_id' => is_numeric($options['TableID'] ?? null) ? (int) $options['TableID'] : null, 'sql' => is_string($options['SQL'] ?? null) ? mb_strcut($options['SQL'], 0, 20000, 'UTF-8') : null, 'has_custom_code' => isset($options['Script']) || isset($options['Code'])];
        };
        $visit($selected);

        return $steps;
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
