<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Approval;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BiManualActions
{
    public function __construct(private BiCatalog $catalog, private TenantPolicy $policy, private ActionBroker $broker) {}

    /** @return array{project: Project, approval: Approval, reused: bool} */
    public function prepare(User $user, string $tenant, string $resourceId, array $data, string $kind = 'app'): array
    {
        abort_if($user->role === 'viewer', 403, 'Seu perfil permite somente leitura.');
        abort_unless(in_array($kind, ['app', 'flow'], true) && ctype_digit($resourceId) && (int) $resourceId > 0, 422, 'Recurso inválido.');
        [$connection, $tenantItem] = $this->catalog->access($user, $tenant);
        $action = $data['action'];
        abort_unless(in_array($action, $kind === 'flow' ? ['clone_flow'] : ['clone_app', 'delete_draft', 'publish_app'], true), 422, 'Ação manual não permitida.');
        $this->policy->assertCommandAllowed($tenant, [$action === 'delete_draft' ? 'rm' : $kind, in_array($action, ['clone_app', 'clone_flow'], true) ? 'clone' : ($action === 'publish_app' ? 'publish' : 'app:'.$resourceId)], $connection);
        $arguments = ['action' => $action, 'resource' => $kind.':'.$resourceId];
        if (in_array($action, ['clone_app', 'clone_flow'], true)) {
            $arguments['name'] = trim($data['name']);
        }
        if ($action === 'publish_app') {
            $arguments['desk'] = (int) $data['desk'];
            $arguments['publish_mode'] = $data['publish_mode'];
        }
        $requestId = strtolower($data['request_id']);
        $requestHash = hash('sha256', json_encode([$tenant, $arguments], JSON_THROW_ON_ERROR));
        try {
            return Cache::lock('bi-review:'.$user->id.':'.$requestId, 660)->block(3, function () use ($user, $tenant, $tenantItem, $arguments, $requestId, $requestHash): array {
                $existing = Approval::where('user_id', $user->id)->where('payload->request_id', $requestId)->first();
                if ($existing) {
                    abort_unless(hash_equals((string) ($existing->payload['request_hash'] ?? ''), $requestHash), 409, 'Esta solicitação já foi usada para outra operação. Abra uma nova revisão.');

                    return ['project' => Project::where('user_id', $user->id)->findOrFail($existing->project_id), 'approval' => $existing, 'reused' => true];
                }

                if (in_array($arguments['action'], ['clone_app', 'clone_flow'], true)) {
                    $project = new Project(['user_id' => $user->id, 'tenant_id' => $tenant, 'tenant_name' => $tenantItem['name'], 'name' => $arguments['name'], 'description' => 'Cópia de '.$arguments['resource'].' preparada pelo catálogo.', 'instructions' => 'A duplicação foi preparada pela interface. Confira o histórico de revisões e o recurso já criado antes de iniciar outra criação.']);
                    $project->id = (string) Str::uuid();
                } else {
                    $managed = ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $tenant)->where('resource', $arguments['resource'])->where('status', 'draft')->first();
                    abort_unless($managed, 422, 'Esta ação exige um rascunho criado e registrado pelo portal.');
                    $project = Project::where('user_id', $user->id)->where('tenant_id', $tenant)->findOrFail($managed->project_id);
                }
                $revision = $this->broker->manualRevision($user, $project, $arguments);
                if (! $project->exists) {
                    $project->description = 'Cópia de “'.mb_substr((string) ($revision['info']['data']['nome'] ?? 'recurso'), 0, 150).'” no G-Analise BI.';
                }

                $result = DB::transaction(function () use ($user, $project, $revision, $tenant, $arguments, $requestId, $requestHash): array {
                    if (! $project->exists) {
                        $project->save();
                    } else {
                        $current = Project::where('user_id', $user->id)->lockForUpdate()->findOrFail($project->id);
                        abort_unless($current->tenant_id === $project->tenant_id && $current->source_ref === $project->source_ref && $current->status === $project->status, 409, 'O contexto do projeto mudou. Prepare uma nova revisão.');
                    }
                    $approval = $this->broker->createReview($user, $project, $arguments, $revision);
                    $approval->update(['payload' => [...$approval->payload, 'request_id' => $requestId, 'request_hash' => $requestHash]]);
                    Audit::record($user->id, 'bi.manual_review.created', 'Operação de BI preparada para revisão pela interface.', $project->id, ['approval_id' => $approval->id, 'resource' => $arguments['resource'], 'action' => $arguments['action'], 'tenant_id' => $tenant]);

                    return ['project' => $project->refresh(), 'approval' => $approval->refresh(), 'reused' => false];
                });
                try {
                    event(new WorkspaceChanged($user->id));
                } catch (Throwable) {
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['operation' => 'Esta revisão ainda está sendo preparada. Aguarde e tente novamente sem abrir outra solicitação.']);
        }
    }

    /** @return array{project: ?Project, approval: Approval} */
    public function status(User $user, string $id): array
    {
        $approval = Approval::where('user_id', $user->id)->where('type', 'manual')->findOrFail($id);

        return ['approval' => $approval, 'project' => $approval->project_id === null ? null : Project::where('user_id', $user->id)->findOrFail($approval->project_id)];
    }
}
