<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\ManagedBiResource;
use App\Models\Project;
use RuntimeException;

class BiMutationGuard
{
    private const PENDING = 'Este recurso tem uma exclusão em conferência. Use Conferir exclusão antes de editar, publicar, duplicar ou executar outra carga.';

    public function assertAvailable(int $userId, string $tenant, string $ref): void
    {
        $reason = $this->reason($userId, $tenant, $ref);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    public function reason(int $userId, string $tenant, string $ref): ?string
    {
        $managed = ManagedBiResource::where('user_id', $userId)->where('tenant_id', $tenant)->where('resource', $ref)->whereIn('status', ['deleted', 'deletion_pending'])->first();
        if ($managed !== null) {
            return $managed->status === 'deleted'
                ? 'Este recurso foi excluído. Não recrie um recurso a partir de arquivos antigos; crie um novo rascunho quando necessário.'
                : self::PENDING;
        }
        if (! str_starts_with($ref, 'table:')) {
            return null;
        }
        $parents = ManagedBiResource::where('user_id', $userId)->where('tenant_id', $tenant)->where('status', 'deletion_pending')->where('resource', 'like', 'flow:%')->get();
        foreach ($parents as $parent) {
            $attempt = $parent->receipt['deletion_attempt'] ?? [];
            $approval = isset($attempt['approval_id']) && is_string($attempt['approval_id']) ? Approval::where('user_id', $userId)->where('project_id', $parent->project_id)->find($attempt['approval_id']) : null;
            $plan = $approval?->payload['revision']['flow_deletion'] ?? null;
            if (! is_array($plan) || ($plan['resource'] ?? null) !== $parent->resource || ! is_array($plan['tables'] ?? null) || ! array_is_list($plan['tables']) || ! hash_equals((string) ($attempt['plan_sha256'] ?? ''), hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)))) {
                return 'Há uma exclusão pendente cuja lista de tabelas não pôde ser conferida. Confira essa operação antes de alterar tabelas neste ambiente.';
            }
            foreach ($plan['tables'] as $table) {
                if (! is_array($table) || ! is_string($table['resource'] ?? null) || ! preg_match('/^table:[1-9][0-9]*$/D', $table['resource'])) {
                    return 'Uma exclusão pendente contém um destino não identificado. Confira essa operação antes de alterar tabelas neste ambiente.';
                }
                if ($table['resource'] === $ref) {
                    return self::PENDING;
                }
            }
        }

        return null;
    }

    /** Guards writes; the deletion service separately authorizes its own rm attempt. */
    public function command(Project $project, array $arguments): void
    {
        $command = $arguments[0] ?? '';
        $operation = $arguments[1] ?? '';
        $refs = [];
        if ($command === 'push') {
            $variables = in_array($arguments, [['push', 'variables'], ['push', 'variables', '--dry-run']], true);
            if (in_array('variables', array_slice($arguments, 1), true) && ! $variables) {
                throw new RuntimeException('Envie somente o destino explícito de variáveis, sem outros recursos ou sobrescrita forçada.');
            }
            $refs = $variables ? ['variables'] : array_values(array_filter(array_slice($arguments, 1), fn ($argument): bool => is_string($argument) && preg_match('/^[a-z-]+:[1-9][0-9]*$/D', $argument)));
            if ($refs === []) {
                throw new RuntimeException('O envio pelo portal exige identificadores explícitos dos recursos revisados.');
            }
        } elseif (($command === 'flow' && in_array($operation, ['clone', 'publish', 'run', 'run-node', 'sync-table'], true)) || ($command === 'app' && in_array($operation, ['clone', 'publish', 'change-table'], true)) || ($command === 'table' && $operation === 'truncate') || ($command === 'schedule' && in_array($operation, ['run', 'pause', 'resume'], true))) {
            $refs[] = $arguments[2] ?? '';
            if ($command === 'app' && $operation === 'change-table') {
                foreach (['--from-table', '--to-table'] as $flag) {
                    $index = array_search($flag, $arguments, true);
                    if ($index !== false && preg_match('/^[1-9][0-9]*$/D', (string) ($arguments[$index + 1] ?? ''))) {
                        $refs[] = 'table:'.$arguments[$index + 1];
                    }
                }
            }
        } elseif ($command === 'new' && $operation === 'table') {
            $index = array_search('--from-flow', $arguments, true);
            if ($index !== false) {
                $refs[] = $arguments[$index + 1] ?? '';
            }
        }
        foreach (array_unique($refs) as $ref) {
            if (is_string($ref) && ($ref === 'variables' || preg_match('/^[a-z-]+:[1-9][0-9]*$/D', $ref))) {
                if (! is_int($project->user_id) || $project->user_id < 1 || ! preg_match('/^[1-9][0-9]*$/D', (string) $project->tenant_id)) {
                    throw new RuntimeException('Esta operação exige um projeto vinculado a um usuário e tenant válidos.');
                }
                $this->assertAvailable($project->user_id, (string) $project->tenant_id, $ref);
            }
        }
    }
}
