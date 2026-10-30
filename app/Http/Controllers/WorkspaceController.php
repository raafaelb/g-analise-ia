<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\ExtractAttachment;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Services\ArtifactImages;
use App\Services\Audit;
use App\Services\BiCredentials;
use App\Services\BiMcpIdentity;
use App\Services\LocalPreview;
use App\Services\OpenAiIdentity;
use App\Services\ProjectPreviews;
use App\Services\RefreshCoverage;
use App\Services\SuiteIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkspaceController extends Controller
{
    public function page(Request $request): Response
    {
        return Inertia::render('Workspace', ['initial' => $this->data($request)]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        return response()->json($this->data($request));
    }

    private function data(Request $request): array
    {
        $uid = $request->user()->id;
        $projects = Project::where('user_id', $uid)->where('status', '!=', 'archived')->withCount('conversations')->latest('updated_at')->get();
        $connections = Connection::where('user_id', $uid)->get();
        $lumoGeneration = $connections->firstWhere('provider', 'horus')?->metadata['session_generation'] ?? null;
        foreach ($connections as $connection) {
            if (str_starts_with($connection->provider, BiMcpIdentity::PREFIX)) {
                $connection->setAttribute('requires_reauthorization', $connection->status !== 'connected' || $lumoGeneration === null || ($connection->metadata['lumo_generation'] ?? null) !== $lumoGeneration || ($connection->metadata['access_version'] ?? null) !== $request->user()->access_version);
            }
        }

        $data = ['user' => [...$request->user()->only('id', 'name', 'email', 'role', 'active', 'local_login_enabled', 'must_change_password'), 'auth_provider' => $request->user()->suite_subject !== null ? 'suite' : 'local', 'can_manage_users' => $request->user()->canAdministerUsers()], 'projects' => $projects, 'conversations' => Conversation::where('user_id', $uid)->latest('updated_at')->get(), 'connections' => $connections, 'approvals' => Approval::where('user_id', $uid)->latest()->limit(100)->get(), 'schedules' => RefreshSchedule::where('user_id', $uid)->latest()->get(), 'activity' => AuditEvent::where('user_id', $uid)->latest()->limit(100)->get(), 'artifacts' => Artifact::where('user_id', $uid)->latest()->orderByDesc('id')->get(), 'runs' => AgentRun::where('user_id', $uid)->latest()->orderByDesc('id')->limit(30)->get(), 'preview' => app(LocalPreview::class)->enabled(), 'integration' => ['mcp_configured' => app(BiMcpIdentity::class)->configured(), 'openai_configured' => app(OpenAiIdentity::class)->configured(), 'plan_usage_enabled' => config('horus.openai.plan_enabled'), 'auth_mode' => config('horus.openai.mode'), 'lumo_login_enabled' => config('horus.lumo.login_enabled'), 'timezone' => 'America/Sao_Paulo', 'suite_url' => app(SuiteIdentity::class)->configured() ? app(SuiteIdentity::class)->issuer() : null]];
        if ($request->user()->must_change_password) {
            foreach (['projects', 'conversations', 'connections', 'approvals', 'schedules', 'activity', 'artifacts', 'runs'] as $key) {
                $data[$key] = [];
            }
        }

        return $data;
    }

    private function author(Request $request): void
    {
        abort_if($request->user()->role === 'viewer', 403, 'Seu perfil permite apenas leitura.');
    }

    private function project(Request $request, string $id): Project
    {
        return Project::where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function createProject(Request $request): JsonResponse
    {
        $this->author($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'color' => ['nullable', Rule::in(['violet', 'mint', 'amber', 'blue', 'rose'])], 'tenant_id' => ['nullable', 'string', 'max:80'], 'instructions' => ['nullable', 'string', 'max:10000']]);
        if (! empty($data['tenant_id'])) {
            $this->verifyTenant($request, $data['tenant_id']);
            $data['tenant_name'] = $this->tenantName($request, $data['tenant_id']);
        }
        $project = Project::create([...$data, 'user_id' => $request->user()->id]);
        $conversation = Conversation::create(['project_id' => $project->id, 'user_id' => $request->user()->id]);
        Audit::record($request->user()->id, 'project.created', 'Projeto “'.$project->name.'” criado.', $project->id);

        return response()->json(['project' => $project, 'conversation' => $conversation], 201);
    }

    public function updateProject(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $project = $this->project($request, $id);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'color' => ['sometimes', Rule::in(['violet', 'mint', 'amber', 'blue', 'rose'])], 'instructions' => ['nullable', 'string', 'max:10000'], 'tenant_id' => ['sometimes', 'nullable', 'string', 'max:80'], 'status' => ['sometimes', Rule::in(['draft', 'archived'])]]);
        $lock = Cache::lock('project:'.$project->id, 30);
        abort_unless($lock->get(), 409, 'Há uma operação usando este projeto. Aguarde ou interrompa a tarefa antes de alterar seu contexto.');
        try {
            $project = DB::transaction(function () use ($request, $id, $data): Project {
                $project = Project::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
                $contextChange = (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== $project->tenant_id) || (isset($data['status']) && $data['status'] !== $project->status);
                if ($contextChange) {
                    $activeRun = $project->conversations()->whereHas('runs', fn ($query) => $query->whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested']))->exists();
                    $activeReview = Approval::where('project_id', $project->id)->whereIn('status', ['pending', 'approved', 'executing'])->exists();
                    abort_if($activeRun || $activeReview, 409, 'Conclua ou cancele as tarefas e revisões deste projeto antes de alterar seu contexto.');
                }
                if (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== $project->tenant_id) {
                    abort_if($project->source_ref || $project->conversations()->where(fn ($query) => $query->whereNotNull('thread_id')->orWhereHas('messages'))->exists(), 409, 'Crie outro projeto para trabalhar em outro tenant depois de vincular um recurso ou iniciar uma conversa.');
                    if ($data['tenant_id']) {
                        $this->verifyTenant($request, $data['tenant_id']);
                        $data['tenant_name'] = $this->tenantName($request, $data['tenant_id']);
                    } else {
                        $data['tenant_name'] = null;
                    }
                    $data['is_example'] = false;
                }
                $project->update($data);
                Audit::record($request->user()->id, 'project.updated', 'Projeto “'.$project->name.'” atualizado.', $project->id);

                return $project;
            });
        } finally {
            $lock->release();
        }

        return response()->json($project);
    }

    private function verifyTenant(Request $request, string $tenant): void
    {
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', 'horus')->where('status', 'connected')->first();
        abort_unless($connection && collect($connection->metadata['tenants'] ?? [])->contains(fn ($t) => (string) ($t['id'] ?? '') === $tenant), 422, 'Conecte o G-Analise e selecione um tenant autorizado.');
    }

    private function tenantName(Request $request, string $tenant): ?string
    {
        return collect(Connection::where('user_id', $request->user()->id)->where('provider', 'horus')->first()?->metadata['tenants'] ?? [])->first(fn ($t) => (string) $t['id'] === $tenant)['name'] ?? null;
    }

    public function createConversation(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $project = $this->project($request, $id);
        abort_if($project->status === 'archived', 409, 'Este projeto está arquivado. Reabra-o antes de iniciar outra conversa.');
        $conversation = Conversation::create(['user_id' => $request->user()->id, 'project_id' => $project->id]);

        return response()->json($conversation, 201);
    }

    public function conversation(Request $request, string $id): JsonResponse
    {
        $conversation = Conversation::where('user_id', $request->user()->id)->findOrFail($id);

        $inputs = $conversation->runs()->pluck('input_message_id', 'id');
        $approvals = Approval::where('user_id', $request->user()->id)->where('project_id', $conversation->project_id)->whereIn('agent_run_id', $inputs->keys())->oldest()->get()->map(fn (Approval $approval): array => [...$approval->toArray(), 'conversation_id' => $conversation->id, 'input_message_id' => $inputs[$approval->agent_run_id]]);

        return response()->json(['conversation' => $conversation, 'messages' => $conversation->messages()->oldest()->orderBy('id')->get(), 'runs' => $conversation->runs()->latest()->orderByDesc('id')->limit(10)->get(), 'approvals' => $approvals]);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $data = $request->validate(['content' => ['required', 'string', 'max:30000'], 'model' => ['nullable', 'string', 'max:100']]);
        $conversation = Conversation::where('user_id', $request->user()->id)->findOrFail($id);
        $result = DB::transaction(function () use ($request, $data, $conversation) {
            $project = Project::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($conversation->project_id);
            abort_if($project->status === 'archived', 409, 'Este projeto está arquivado. Reabra-o antes de enviar outra mensagem.');
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_if($conversation->runs()->whereIn('status', ['queued', 'running', 'awaiting_approval', 'cancel_requested'])->exists(), 409, 'Já existe uma tarefa em execução nesta conversa.');
            $inputMessage = Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $data['content']]);
            if ($conversation->title === 'Nova conversa') {
                $conversation->title = Str::limit($data['content'], 65);
            }
            if (! empty($data['model'])) {
                $conversation->model = $data['model'];
            }
            $conversation->touch();
            $conversation->save();
            $run = AgentRun::create(['conversation_id' => $conversation->id, 'user_id' => $request->user()->id, 'input_message_id' => $inputMessage->id]);
            Audit::record($request->user()->id, 'conversation.message', 'Mensagem enviada ao projeto.', $conversation->project_id);

            return $run;
        });
        RunAgentTurn::dispatch($result->id)->onQueue('agents');

        return response()->json(['run' => $result], 202);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $run = AgentRun::where('user_id', $request->user()->id)->findOrFail($id);
        if ($run->status === 'awaiting_approval') {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);
            Approval::where('agent_run_id', $run->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'cancelled']);

            return response()->json($run);
        }
        if (in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'cancel_requested']);
            Approval::where('agent_run_id', $run->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        }

        return response()->json($run);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'fingerprint' => ['required', 'string', 'size:64']]);
        $approval = DB::transaction(function () use ($request, $id, $data) {
            $approval = Approval::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($approval->status === 'pending', 409, 'Esta solicitação já foi respondida.');
            abort_if($approval->expires_at->isPast(), 409, 'Esta solicitação expirou.');
            abort_unless(hash_equals($approval->fingerprint, $data['fingerprint']), 409, 'A revisão foi alterada. Atualize a página.');
            $approval->update(['status' => $data['decision'], 'decided_at' => now()]);

            return $approval;
        });
        Audit::record($request->user()->id, 'approval.'.$data['decision'], $data['decision'] === 'approved' ? 'Execução revisada e autorizada.' : 'Execução recusada.', $approval->project_id, ['approval_id' => $approval->id]);

        if ($approval->status === 'rejected' && in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
            app(BiCredentials::class)->cleanup($approval);
        }
        if ($approval->status === 'approved') {
            ExecuteApprovedAction::dispatch($approval->id)->onQueue('actions');
        } elseif ($approval->agent_run_id) {
            AgentRun::whereKey($approval->agent_run_id)->where('status', 'awaiting_approval')->update(['status' => 'cancelled', 'finished_at' => now()]);
        }

        return response()->json($approval);
    }

    public function upload(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $project = $this->project($request, $id);
        abort_if($project->status === 'archived', 409, 'Este projeto está arquivado. Reabra-o antes de anexar arquivos.');
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,pdf,xlsx,json,yaml,yml,md,png,jpg,jpeg']]);
        abort_if(Artifact::where('project_id', $project->id)->where('user_id', $request->user()->id)->where('kind', 'attachment')->count() >= 20, 422, 'Cada projeto aceita até 20 anexos.');
        $file = $request->file('file');
        $path = $file->store('uploads/'.$request->user()->id.'/'.$project->id, 'local');
        $artifact = Artifact::create(['user_id' => $request->user()->id, 'project_id' => $project->id, 'name' => Str::limit(basename($file->getClientOriginalName()), 200, ''), 'kind' => 'attachment', 'mime' => $file->getMimeType(), 'path' => $path, 'metadata' => ['size' => $file->getSize(), 'extraction' => ['status' => 'queued']]]);
        ExtractAttachment::dispatch($artifact->id)->onQueue('documents');
        Audit::record($request->user()->id, 'artifact.uploaded', 'Arquivo anexado ao projeto.', $project->id);

        return response()->json($artifact, 201);
    }

    public function visualConsent(Request $request, string $id): JsonResponse
    {
        $this->author($request);
        $data = $request->validate(['conversation_id' => ['required', 'uuid'], 'recipient' => ['required', Rule::in(['openai'])]]);
        $artifact = Artifact::where('user_id', $request->user()->id)->findOrFail($id);
        $conversation = Conversation::where('user_id', $request->user()->id)->where('project_id', $artifact->project_id)->findOrFail($data['conversation_id']);
        app(ArtifactImages::class)->grant($request->user(), $artifact, $conversation);

        return response()->json(['artifact' => $artifact->refresh(), 'message' => 'Esta imagem foi autorizada para análise com a OpenAI nesta conversa.']);
    }

    public function download(Request $request, string $id): BinaryFileResponse|StreamedResponse
    {
        $artifact = Artifact::where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless($artifact->path && Storage::disk('local')->exists($artifact->path), 404);

        return Storage::disk('local')->download($artifact->path, $artifact->name, ['Content-Type' => $artifact->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function previewContext(Request $request, string $id, ProjectPreviews $previews): JsonResponse
    {
        $project = $this->project($request, $id);
        try {
            return response()->json($previews->context($request->user(), $project));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['preview' => $e->getMessage()]);
        }
    }

    public function renderPreview(Request $request, string $id, ProjectPreviews $previews): JsonResponse
    {
        $project = $this->project($request, $id);
        $data = $request->validate(['dashboard' => ['nullable', 'integer', 'min:1']]);
        try {
            $artifact = $previews->render($request->user(), $project, isset($data['dashboard']) ? (int) $data['dashboard'] : null);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['preview' => $e->getMessage()]);
        }

        return response()->json(['artifact' => $artifact], 201);
    }

    public function refreshCoverage(Request $request, string $id): JsonResponse
    {
        $project = $this->project($request, $id);
        abort_unless(preg_match('/^app:([1-9][0-9]*)$/D', (string) $project->source_ref, $match), 422, 'Vincule uma aplicação do catálogo ao projeto para verificar todas as fontes.');
        try {
            $report = app(RefreshCoverage::class)->inspect($request->user(), (string) $project->tenant_id, $match[1], $project);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['coverage' => $e->getMessage()]);
        }

        return response()->json($report);
    }

    public function schedule(Request $request): JsonResponse
    {
        $this->author($request);
        $data = $request->validate(['project_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100'], 'source_id' => ['required', 'regex:/^flow:[1-9][0-9]*$/'], 'time' => ['required', 'date_format:H:i']]);
        $project = $this->project($request, $data['project_id']);
        abort_unless($project->tenant_id, 422, 'Vincule o projeto a um tenant real para preparar a atualização.');
        $schedule = RefreshSchedule::firstOrCreate(['user_id' => $request->user()->id, 'tenant_id' => $project->tenant_id, 'source_id' => $data['source_id']], ['project_id' => $project->id, 'name' => $data['name'], 'time' => $data['time'], 'timezone' => 'America/Sao_Paulo', 'status' => 'pending_validation']);
        Audit::record($request->user()->id, 'schedule.prepared', 'Plano de atualização preparado; ativação e teste no G-Analise pendentes.', $project->id);

        return response()->json($schedule->refresh(), 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        abort_if($request->user()->suite_subject !== null, 422, 'Seu nome é gerenciado pela Suíte.');
        $request->user()->forceFill([...$data, 'access_revision' => $request->user()->access_revision + 1])->save();

        return response()->json(['message' => 'Nome atualizado.']);
    }
}
