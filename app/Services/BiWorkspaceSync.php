<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BiWorkspaceSync
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function refreshProject(User $user, string $id): array
    {
        $project = Project::where('user_id', $user->id)->findOrFail($id);
        try {
            return Cache::lock('project:'.$project->id, 630)->block(3, function () use ($user, $project): array {
                $project->refresh();
                abort_if(AgentRun::whereHas('conversation', fn ($query) => $query->where('project_id', $project->id))->whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested'])->exists(), 409, 'Aguarde a tarefa de IA terminar antes de atualizar a versão do BI.');

                return $this->refresh($user, $project, (string) $project->source_ref);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['sync' => 'Outra tarefa deste projeto está em andamento. Aguarde para atualizar a versão do BI.']);
        }
    }

    /** Called with the project lock held by the HTTP entry point or the agent worker.
     * @return array<string, mixed>
     */
    public function refresh(User $user, Project $project, string $resource): array
    {
        abort_unless($project->user_id === $user->id && $project->fresh()?->user_id === $user->id, 404);
        abort_if(User::find($user->id)?->role === 'viewer', 403, 'Seu perfil permite somente leitura.');
        abort_if($project->status === 'archived', 409, 'Este projeto está arquivado.');
        if (! preg_match('/^(app|flow|table|schedule|agent|bi-desk|dw-desk):([1-9][0-9]*)$/D', $resource, $match)) {
            throw new RuntimeException('Vincule um recurso do BI ao projeto antes de atualizar sua versão.');
        }
        abort_if(Approval::where('project_id', $project->id)->where(function ($query): void {
            $query->whereIn('status', ['approved', 'executing'])->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '>', now()));
        })->exists(), 409, 'Conclua ou recuse a revisão pendente antes de atualizar a versão do BI.');
        [$connection] = $this->catalog->access($user, (string) $project->tenant_id);
        $generation = $connection->metadata['session_generation'] ?? null;
        $this->lumo->initialize($connection, $project, true);
        $this->assertSession($connection, $generation);
        abort_unless(collect($this->catalog->rows($connection, $project, $match[1]))->contains(fn (array $row): bool => (string) $row['id'] === $match[2]), 404, 'Este recurso não está disponível no tenant para sua sessão atual.');
        $this->assertSession($connection, $generation);
        $before = $this->difference($connection, $project, $resource);
        $this->assertSession($connection, $generation);
        if ($before['workingMissing'] === false && $before['workingVsState']['equal'] !== true) {
            return ['status' => 'local_changes', 'updated' => false, 'verified' => false, 'resource' => $resource, 'message' => 'Há edições locais ainda não sincronizadas. Elas foram preservadas. Revise as diferenças com a IA antes de atualizar a versão do BI.'];
        }
        if ($before['workingMissing'] === false && $before['workingVsServer']['equal'] === true) {
            return ['status' => 'current', 'updated' => false, 'verified' => true, 'resource' => $resource, 'message' => 'Este projeto já está com a versão atual do BI.'];
        }
        $this->lumo->run($connection, ['pull', $resource], $project, 90);
        $after = $this->difference($connection, $project, $resource);
        $this->assertSession($connection, $generation);
        $verified = $after['workingMissing'] === false && $after['workingVsState']['equal'] === true && $after['workingVsServer']['equal'] === true;
        Audit::record($user->id, 'bi.workspace.refreshed', $verified ? 'Versão do BI atualizada no projeto, preservando edições locais.' : 'Versão recebida do BI; uma nova diferença ainda precisa ser conferida.', $project->id, ['resource' => $resource, 'tenant_id' => $project->tenant_id, 'verified' => $verified, 'remote_read_only' => true]);

        return ['status' => $verified ? 'updated' : 'changed_again', 'updated' => true, 'verified' => $verified, 'resource' => $resource, 'message' => $verified ? 'Projeto atualizado com a versão atual do BI. Nenhuma alteração foi enviada ao BI.' : 'A versão foi recebida, mas o BI ou os arquivos mudaram novamente. Confira as diferenças antes de editar ou publicar.'];
    }

    /** @return array<string, mixed> */
    private function difference(Connection $connection, Project $project, string $resource): array
    {
        $data = $this->lumo->run($connection, ['diff', $resource, '--include-remote'], $project, 60)['data'] ?? null;
        if (! is_array($data) || ! is_bool($data['workingMissing'] ?? null) || ($data['workingMissing'] ? ! is_bool($data['stateVsServer']['equal'] ?? null) : (! is_bool($data['workingVsState']['equal'] ?? null) || ! is_bool($data['workingVsServer']['equal'] ?? null)))) {
            throw new RuntimeException('Não foi possível confirmar as diferenças locais. Os arquivos não serão substituídos.');
        }

        return $data;
    }

    private function assertSession(Connection $connection, ?string $generation): void
    {
        $current = $connection->fresh();
        if (! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A sessão mudou durante a atualização. Confira seus acessos e as diferenças antes de continuar.');
        }
    }
}
