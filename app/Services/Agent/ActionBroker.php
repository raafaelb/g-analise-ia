<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\RefreshSchedule;
use App\Models\User;
use App\Services\ArtifactImages;
use App\Services\Audit;
use App\Services\BiCatalog;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use App\Services\ScheduleChanges;
use App\Services\TenantPolicy;
use App\Services\WorkspaceFiles;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ActionBroker
{
    public function __construct(private LumoClient $lumo, private SafeWorkspace $files) {}

    public function tools(): array
    {
        return [
            ['name' => 'tenant_variables', 'description' => 'Lista nomes e referências das variáveis do tenant do projeto. Os valores são protegidos: nunca devolve senhas, chaves ou outros valores. Use a referência no recurso BI.', 'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
            ['name' => 'refresh_coverage', 'description' => 'Confere todas as fontes de uma aplicação no tenant do projeto, agendas diárias ou mais frequentes, resultados reais e a execução de teste correlacionada. Somente leitura remota; não cria agendas ou executa cargas.', 'inputSchema' => ['type' => 'object', 'properties' => ['resource' => ['type' => 'string', 'pattern' => '^app:[1-9][0-9]*$']], 'required' => ['resource'], 'additionalProperties' => false]],
            ['name' => 'project_image', 'description' => 'Devolve a imagem PNG de um dashboard exportado para análise visual somente após autorização explícita do usuário no portal para esse arquivo e esta conversa. Não gera nova imagem. Use o artifact_id retornado pelo export.', 'inputSchema' => ['type' => 'object', 'properties' => ['artifact_id' => ['type' => 'string']], 'required' => ['artifact_id'], 'additionalProperties' => false]],
            ['name' => 'horus_action', 'description' => 'Opera somente o tenant do projeto pela sessão G-Analise do usuário. Use para listar, inspecionar, criar rascunhos, clonar, validar, sincronizar, gerar prévia e publicar após revisão humana. delete_draft exclui somente rascunhos criados por este projeto e registrados pelo sistema; recursos preexistentes são recusados. Nunca execute lumo pelo shell.', 'inputSchema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'info', 'columns', 'table_preview', 'lint', 'diff', 'scaffold', 'new', 'clone_app', 'push', 'preview', 'export', 'publish_app', 'publish_flow', 'flow_status', 'flow_logs', 'schedule_info', 'schedule_logs', 'run_schedule', 'resume_schedule', 'delete_draft']], 'kind' => ['type' => 'string', 'enum' => ['app', 'table', 'flow', 'schedule', 'bi-desk', 'dw-desk', 'agent', 'widget', 'expression', 'node', 'fact', 'report', 'calendar']], 'resource' => ['type' => 'string'], 'name' => ['type' => 'string'], 'template' => ['type' => 'string', 'description' => 'Variante do scaffold, por exemplo Kpi, Barline, contexts ou postgresql.'], 'flow' => ['type' => 'string'], 'node' => ['type' => 'string'], 'agent' => ['type' => 'integer'], 'desk' => ['type' => 'integer'], 'dashboard' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => false]],
            ['name' => 'workspace_file', 'description' => 'Lista, lê e escreve arquivos YAML e notas do workspace. Não permite caminhos absolutos, arquivos internos, credenciais ou exclusões. Leia o arquivo antes de editar e envie expected_sha256 para conferir conflitos.', 'inputSchema' => ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'enum' => ['list', 'read', 'write']], 'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'expected_sha256' => ['type' => 'string']], 'required' => ['operation'], 'additionalProperties' => false]],
        ];
    }

    public function execute(AgentRun $run, string $tool, array $arguments): array
    {
        $run->refresh();
        if (in_array($run->status, ['cancel_requested', 'cancelled', 'failed'], true)) {
            throw new RuntimeException('Tarefa interrompida pelo usuário.');
        }
        if ($run->status === 'awaiting_approval') {
            return ['status' => 'awaiting_approval', 'message' => 'Há uma revisão pendente. Encerre o turno; a tarefa será retomada pelo portal após a decisão.'];
        }
        $user = User::findOrFail($run->user_id);
        if ($user->role === 'viewer') {
            throw new RuntimeException('O perfil atual permite somente leitura.');
        }
        $project = $run->conversation->project;
        if ($project->user_id !== $user->id) {
            throw new RuntimeException('Projeto fora do contexto autorizado.');
        }
        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus')->where('status', 'connected')->first();
        if (! $connection) {
            throw new RuntimeException('A conexão G-Analise foi removida ou expirou.');
        }
        if ($tool === 'tenant_variables') {
            $rows = app(BiCatalog::class)->rows($connection, $project, 'variables');
            Audit::record($user->id, 'horus.variables.read', 'Referências de variáveis consultadas; valores protegidos.', $project->id);

            return ['count' => count($rows), 'variables' => $rows, 'values_protected' => true];
        }
        if ($tool === 'refresh_coverage') {
            if (! preg_match('/^app:([1-9][0-9]*)$/D', (string) ($arguments['resource'] ?? ''), $match)) {
                throw new RuntimeException('Informe uma aplicação válida.');
            }

            return app(RefreshCoverage::class)->inspect($user, (string) $project->tenant_id, $match[1], $project);
        }
        if ($tool === 'project_image') {
            $artifact = app(ArtifactImages::class)->authorized($run, (string) ($arguments['artifact_id'] ?? ''));

            return ['image_artifact_id' => $artifact->id, 'name' => $artifact->name, 'resource' => $artifact->metadata['resource'] ?? null];
        }
        if ($tool === 'workspace_file') {
            return $this->files->file($project, $arguments);
        }
        if ($tool !== 'horus_action') {
            throw new RuntimeException('Ferramenta não autorizada.');
        }
        $command = $this->command($arguments, $project);
        app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $command, $connection);
        $action = $arguments['action'];
        if ($run->status === 'awaiting_approval') {
            return ['status' => 'awaiting_approval', 'message' => 'Há uma revisão pendente nesta tarefa. Informe isso ao usuário e encerre o turno sem repetir ações.'];
        }
        if ($action === 'delete_draft') {
            $this->assertDeletable($connection, $project, $arguments['resource']);
        }
        if (in_array($action, ['push', 'publish_app', 'publish_flow', 'run_schedule', 'resume_schedule', 'delete_draft'], true)) {
            $revision = $this->revision($connection, $project, $arguments);
            $fingerprint = hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR));
            $approval = Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run->id, 'type' => str_starts_with($action, 'publish') ? 'publish' : 'execution', 'title' => match ($action) {
                'push' => 'Sincronizar alterações com o G-Analise BI','publish_app' => 'Publicar aplicação no G-Analise BI','publish_flow' => 'Publicar fonte de dados no G-Analise BI','run_schedule' => 'Executar carga de teste','delete_draft' => 'Excluir rascunho criado por este projeto',default => 'Ativar agenda no G-Analise BI'
            }, 'description' => 'Confira tenant, recurso, destino e alterações. Após a autorização, o portal executará esta revisão e retomará a IA com o resultado.', 'payload' => ['action' => $arguments, 'revision' => $revision], 'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(12)]);
            $run->update(['status' => 'awaiting_approval', 'heartbeat_at' => now()]);

            return ['status' => 'awaiting_approval', 'approval_id' => $approval->id, 'resource' => $arguments['resource'], 'message' => 'A alteração foi preparada para revisão. Encerre este turno e informe que a IA será retomada automaticamente após a decisão. Nenhuma ação desta solicitação foi executada ainda.'];
        }

        return $this->perform($user, $project, $connection, $arguments);
    }

    public function performApproved(Approval $approval): array
    {
        if ($approval->status !== 'executing' || $approval->expires_at->isPast()) {
            throw new RuntimeException('A autorização não está válida para execução.');
        }
        $user = User::findOrFail($approval->user_id);
        $project = Project::where('user_id', $user->id)->findOrFail($approval->project_id);
        if ($user->role === 'viewer') {
            throw new RuntimeException('O perfil atual permite somente leitura.');
        }
        $arguments = $approval->payload['action'] ?? [];
        if (! in_array($arguments['action'] ?? '', ['push', 'publish_app', 'publish_flow', 'run_schedule', 'resume_schedule', 'delete_draft'], true)) {
            throw new RuntimeException('Ação de revisão inválida.');
        }
        $revision = $approval->payload['revision'] ?? [];
        $fingerprint = hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR));
        if (! hash_equals($approval->fingerprint, $fingerprint)) {
            throw new RuntimeException('A revisão foi alterada. Prepare uma nova autorização.');
        }
        $connection = Connection::where('user_id', $user->id)->where('provider', 'horus')->where('status', 'connected')->first();
        if (! $connection) {
            throw new RuntimeException('Reconecte sua conta G-Analise BI.');
        }
        $this->lumo->initialize($connection, $project);
        if (! hash_equals(hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($this->revision($connection, $project, $arguments), JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('O recurso mudou após a revisão. Uma nova autorização é necessária.');
        }
        AgentRun::whereKey($approval->agent_run_id)->update(['heartbeat_at' => now()]);

        return $this->perform($user, $project, $connection, $arguments);
    }

    private function perform(User $user, Project $project, Connection $connection, array $arguments): array
    {
        if (in_array($arguments['action'] ?? '', ['push', 'resume_schedule'], true) && str_starts_with($arguments['resource'] ?? '', 'schedule:')) {
            return Cache::lock('schedule-write:'.$project->tenant_id, 630)->block(5, function () use ($user, $project, $connection, $arguments) {
                app(ScheduleChanges::class)->inspect($user, $project, $arguments['resource'], $arguments['action'] === 'resume_schedule');

                return $this->performUnlocked($user, $project, $connection, $arguments);
            });
        }

        return $this->performUnlocked($user, $project, $connection, $arguments);
    }

    private function performUnlocked(User $user, Project $project, Connection $connection, array $arguments): array
    {
        $command = $this->command($arguments, $project);
        app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $command, $connection);
        $action = $arguments['action'];
        $this->assertRefreshBeforePublish($user, $project, $arguments);
        if ($action === 'delete_draft') {
            $this->assertDeletable($connection, $project, $arguments['resource']);
            $this->checkpointDraft($project, $arguments['resource']);
        }
        if (in_array($action, ['push', 'publish_app', 'publish_flow'], true)) {
            $this->lumo->run($connection, ['lint'], $project, 90);
        }
        if ($action === 'export') {
            File::delete(app(WorkspaceFiles::class)->path($project).'/exports/dashboard.png');
        }
        $requestedAt = now()->toIso8601String();
        $result = $this->lumo->run($connection, $command, $project, in_array($action, ['export', 'run_schedule'], true) ? 180 : 90);
        if (array_key_exists('data', $result)) {
            $result['data'] = app(BiCatalog::class)->redact($result['data']);
        }
        if ($action === 'run_schedule') {
            $documents = app(BiCatalog::class)->workingMetadata($project);
            $schedule = $documents[$arguments['resource']] ?? [];
            $ids = array_values(array_unique(array_map(fn ($binding) => (int) $binding['flow_etl_id'], $schedule['flows'] ?? [])));
            $data = $result['data'] ?? [];
            $runId = is_array($data) ? ($data['run_id'] ?? $data['runId'] ?? $data['execution']['run_id'] ?? null) : null;
            $request = ['schedule_id' => (int) substr($arguments['resource'], 9), 'requested_at' => $requestedAt, 'expected_flow_ids' => $ids, 'run_id' => is_string($runId) ? $runId : null, 'verified' => false, 'configuration_hash' => app(RefreshCoverage::class)->signature($schedule, $documents)];
            $result['execution_request'] = $request;
            foreach ($ids as $flowId) {
                $record = RefreshSchedule::firstOrNew(['user_id' => $user->id, 'tenant_id' => $project->tenant_id, 'source_id' => 'flow:'.$flowId]);
                if (! $record->exists) {
                    $record->project_id = $project->id;
                    $record->name = 'Fonte #'.$flowId;
                }
                $record->remote_id = (string) $request['schedule_id'];
                $record->status = 'pending_test';
                $record->metadata = [...($record->metadata ?? []), 'test_request' => $request, 'test_verified' => false];
                $record->save();
            }
        }
        if (in_array($action, ['new', 'clone_app'], true)) {
            $result = $this->recordCreated($project, $arguments, $result);
        }
        if ($action === 'delete_draft') {
            ManagedBiResource::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->update(['status' => 'deleted']);
            if ($project->source_ref === $arguments['resource']) {
                $project->update(['source_ref' => null]);
            }
        }
        if ($action === 'publish_app') {
            ManagedBiResource::where('user_id', $user->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->update(['status' => 'published']);
        }
        if ($action === 'info') {
            $managed = ManagedBiResource::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->first();
            if ($managed) {
                $result['project_provenance'] = ['resource' => $managed->resource, 'origin' => $managed->origin, 'source_ref' => $managed->source_ref, 'status' => $managed->status, 'created_at' => $managed->receipt['created_at'] ?? null];
            }
        }
        Audit::record($user->id, 'horus.'.$action, 'Operação G-Analise: '.$action.'.', $project->id, ['resource' => $arguments['resource'] ?? null]);
        if ($action === 'export') {
            $path = app(WorkspaceFiles::class)->path($project).'/exports/dashboard.png';
            $size = is_file($path) ? @getimagesize($path) : false;
            if (! $size || $size['mime'] !== 'image/png' || filesize($path) > 20000000) {
                throw new RuntimeException('A exportação não retornou uma imagem PNG válida de até 20 MB.');
            }
            if (is_file($path)) {
                $stored = 'exports/'.$user->id.'/'.$project->id.'/'.Str::uuid().'.png';
                Storage::disk('local')->put($stored, File::get($path));
                $artifact = Artifact::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => $project->name.' - '.str_replace(':', '-', $arguments['resource']).' - '.now()->format('Y-m-d_H-i-s').'.png', 'kind' => 'dashboard_export', 'mime' => 'image/png', 'path' => $stored, 'metadata' => ['resource' => $arguments['resource'], 'dashboard_id' => $arguments['dashboard'] ?? null, 'width' => $size[0], 'height' => $size[1], 'generated_at' => now()->toIso8601String()]]);
                $result['artifact_id'] = $artifact->id;
                $result['download_path'] = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/').'/api/artifacts/'.$artifact->id.'/download';
                $result['visual_review'] = 'Abra a prévia no portal para autorizar o envio deste arquivo à OpenAI; depois use project_image com artifact_id.';
            }
        }

        return $result;
    }

    public function command(array $arguments, Project $project): array
    {
        $action = $arguments['action'] ?? '';
        $ref = (string) ($arguments['resource'] ?? '');
        if ($ref !== '' && ! preg_match('/^(app|flow|table|schedule|agent|bi-desk|dw-desk):[1-9][0-9]*$/D', $ref)) {
            throw new RuntimeException('Referência G-Analise inválida.');
        }
        $required = ['info', 'columns', 'table_preview', 'diff', 'clone_app', 'push', 'preview', 'export', 'publish_app', 'publish_flow', 'flow_status', 'flow_logs', 'schedule_info', 'schedule_logs', 'run_schedule', 'resume_schedule', 'delete_draft'];
        if (in_array($action, $required, true) && $ref === '') {
            throw new RuntimeException('Informe o recurso que será operado.');
        }
        $kind = $arguments['kind'] ?? 'app';
        if ($action === 'scaffold') {
            if (! in_array($kind, ['app', 'flow', 'table', 'schedule', 'bi-desk', 'dw-desk', 'agent', 'widget', 'expression', 'node', 'fact', 'report', 'calendar'], true)) {
                throw new RuntimeException('Tipo de receita não permitido.');
            }
            $template = (string) ($arguments['template'] ?? '');
            if ($template !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $template)) {
                throw new RuntimeException('Variante de receita inválida.');
            }

            return ['scaffold', $kind, ...($template !== '' ? [$template] : [])];
        }

        if (! in_array($kind, ['app', 'table', 'flow', 'schedule', 'bi-desk', 'dw-desk', 'agent'], true)) {
            throw new RuntimeException('Tipo de recurso inválido.');
        }
        $appActions = ['clone_app', 'preview', 'export', 'publish_app', 'delete_draft'];
        $flowActions = ['publish_flow', 'flow_status', 'flow_logs'];
        $scheduleActions = ['schedule_info', 'schedule_logs', 'run_schedule', 'resume_schedule'];
        if ((in_array($action, $appActions, true) && ! str_starts_with($ref, 'app:')) || (in_array($action, $flowActions, true) && ! str_starts_with($ref, 'flow:')) || (in_array($action, $scheduleActions, true) && ! str_starts_with($ref, 'schedule:')) || (in_array($action, ['columns', 'table_preview'], true) && ! str_starts_with($ref, 'table:'))) {
            throw new RuntimeException('Tipo de referência incompatível com a operação.');
        }
        if (in_array($action, ['publish_app', 'publish_flow'], true) && (! isset($arguments['desk']) || ! is_int($arguments['desk']) || $arguments['desk'] < 1)) {
            throw new RuntimeException('Escolha uma mesa válida para publicar.');
        }
        if ($action === 'delete_draft') {
            $this->managed($project, $ref);
        }
        $cloneName = trim((string) ($arguments['name'] ?? ''));
        if (strlen($cloneName) > 150) {
            throw new RuntimeException('O nome da cópia deve ter até 150 caracteres.');
        }
        $dashboard = isset($arguments['dashboard']) ? ['--dashboard', (string) (int) $arguments['dashboard']] : [];

        return match ($action) {
            'list' => ['list', $kind, '--remote', '--limit', '500'], 'info' => ['info', $ref, ...(str_starts_with($ref, 'app:') ? ['--full'] : [])], 'columns' => ['columns', $ref], 'table_preview' => ['table', 'preview', $ref, '--limit', '20'], 'lint' => ['lint'], 'diff' => ['diff', $ref, '--include-remote'],
            'scaffold' => ['scaffold', $kind],
            'new' => $this->newCommand($kind, $arguments),
            'clone_app' => ['app', 'clone', $ref, ...($cloneName !== '' ? ['--name', $cloneName] : [])], 'push' => ['push', $ref],
            'preview' => ['app', 'dashboard-preview', $ref, '--limit', '20', ...$dashboard],
            'export' => ['app', 'export', $ref, '--image-format', 'png', '--output', app(WorkspaceFiles::class)->path($project).'/exports/dashboard.png', ...$dashboard],
            'publish_app' => ['app', 'publish', $ref, '--desk', (string) $arguments['desk']], 'publish_flow' => ['flow', 'publish', $ref, '--desk', (string) $arguments['desk']],
            'delete_draft' => ['rm', $ref, '--force'],
            'flow_status' => ['flow', 'status', $ref], 'flow_logs' => ['flow', 'logs', $ref],
            'schedule_info' => ['info', $ref], 'schedule_logs' => ['schedule', 'logs', $ref, '--tail', '50'], 'run_schedule' => ['schedule', 'run', $ref], 'resume_schedule' => ['schedule', 'resume', $ref],
            default => throw new RuntimeException('Ação G-Analise não permitida.'),
        };
    }

    private function managed(Project $project, string $ref): ManagedBiResource
    {
        $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('status', 'draft')->first();
        if (! $managed || ! str_starts_with($ref, 'app:')) {
            throw new RuntimeException('A exclusão permite somente aplicações em rascunho criadas e registradas por este projeto. Recursos preexistentes não podem ser excluídos.');
        }

        return $managed;
    }

    private function assertDeletable(Connection $connection, Project $project, string $ref): void
    {
        $this->managed($project, $ref);
        $result = $this->lumo->run($connection, ['info', $ref], $project);
        $payload = $result['data']['payload'] ?? $result['data'];
        $body = $payload['body'] ?? $payload;
        if (isset($body['deskId']) || isset($body['_state']['deskId']) || isset($body['desk_id'])) {
            throw new RuntimeException('Esta aplicação já foi publicada. A exclusão de rascunhos não pode removê-la.');
        }
        if (! array_key_exists('deskId', $body) && ! array_key_exists('deskId', $body['_state'] ?? []) && ! array_key_exists('desk_id', $body)) {
            throw new RuntimeException('Não foi possível confirmar que o recurso continua em rascunho.');
        }
    }

    private function checkpointDraft(Project $project, string $ref): void
    {
        $root = app(WorkspaceFiles::class)->path($project);
        $id = substr($ref, 4);
        $relative = null;
        foreach (File::files($root.'/apps') as $file) {
            if ($file->isLink() || $file->getSize() > 2000000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                continue;
            }
            $header = preg_split('/^---\\s*$/m', File::get($file->getPathname()), 2)[0];
            if (preg_match('/^id:\\s*'.preg_quote($id, '/').'\\s*$/m', $header) && preg_match('/^kind:\\s*app\\s*$/m', $header)) {
                $relative = 'apps/'.$file->getFilename();
                break;
            }
        }
        if (! $relative) {
            throw new RuntimeException('A exclusão foi interrompida porque o YAML do rascunho não pôde ser preservado.');
        }
        $environment = array_merge(array_fill_keys(array_keys(getenv()), false), ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => '/tmp', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']);
        $prefix = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', '-c', 'user.name=G-Analise IA', '-c', 'user.email=local@g-analise.invalid'];
        foreach ([['init', '--quiet'], ['add', '--force', '--', $relative], ['commit', '--quiet', '--allow-empty', '-m', 'Antes de excluir rascunho '.$ref, '--only', '--', $relative]] as $command) {
            $process = new Process([...$prefix, ...$command], $root, $environment);
            $process->setTimeout(15);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('A exclusão foi interrompida porque não foi possível salvar a revisão local do rascunho.');
            }
        }
    }

    private function assertRefreshBeforePublish(User $user, Project $project, array $arguments): void
    {
        if (($arguments['action'] ?? '') !== 'publish_app' || ! ManagedBiResource::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->exists()) {
            return;
        }
        $report = app(RefreshCoverage::class)->inspect($user, (string) $project->tenant_id, substr($arguments['resource'], 4), $project);
        if (! ($report['verified'] ?? false)) {
            throw new RuntimeException('Atualização pendente: confira todas as fontes, agendas ativas ao menos diárias e uma carga de teste concluída antes de publicar. Se uma fonte estiver privada, solicite autorização para publicá-la.');
        }
    }

    private function recordCreated(Project $project, array $arguments, array $result): array
    {
        $kind = $arguments['action'] === 'clone_app' ? 'app' : ($arguments['kind'] ?? 'app');
        $data = $result['data'] ?? [];
        $candidates = is_array($data) ? [$data, $data['payload'] ?? [], $data['resource'] ?? [], $data['created'] ?? []] : [];
        $ref = null;
        foreach ($candidates as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['ref', 'resource_ref', 'resource', 'app_ref'] as $key) {
                if (is_string($item[$key] ?? null) && preg_match('/^'.preg_quote($kind, '/').':[1-9][0-9]*$/D', $item[$key])) {
                    $ref = $item[$key];
                    break 2;
                }
            }
            foreach (['cloned_id', 'new_id', $kind.'_id', 'id'] as $key) {
                if (isset($item[$key]) && preg_match('/^[1-9][0-9]*$/D', (string) $item[$key])) {
                    $ref = $kind.':'.$item[$key];
                    break 2;
                }
            }
        }
        if (! $ref || $ref === ($arguments['resource'] ?? null)) {
            $result['management_warning'] = 'O recurso foi criado, mas a resposta não identificou com segurança seu novo ID. A exclusão automática não foi habilitada; não repita a criação.';

            return $result;
        }
        ManagedBiResource::firstOrCreate(['user_id' => $project->user_id, 'tenant_id' => $project->tenant_id, 'resource' => $ref], ['project_id' => $project->id, 'origin' => $arguments['action'], 'source_ref' => $arguments['resource'] ?? null, 'receipt' => ['created_at' => now()->toIso8601String(), 'cli_exit_code' => $result['exit_code'] ?? 0]]);
        if ($kind === 'app' && ! $project->source_ref) {
            $project->update(['source_ref' => $ref]);
        }
        $result['managed_resource'] = $ref;

        return $result;
    }

    private function newCommand(string $kind, array $arguments): array
    {
        if (! in_array($kind, ['app', 'flow', 'table', 'schedule'], true)) {
            throw new RuntimeException('Este tipo não pode ser criado por esta ferramenta.');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '' || strlen($name) > 150) {
            throw new RuntimeException('Informe um nome de até 150 caracteres.');
        }
        $command = ['new', $kind, '--name', $name];
        if ($kind === 'table') {
            if (! preg_match('/^flow:[1-9][0-9]*$/D', $arguments['flow'] ?? '') || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $arguments['node'] ?? '')) {
                throw new RuntimeException('Criar tabela requer flow válido e identificador do nó DW.');
            }$command = [...$command, '--from-flow', $arguments['flow'], '--node', $arguments['node']];
        }if ($kind === 'schedule') {
            if (! is_int($arguments['agent'] ?? null) || $arguments['agent'] < 1) {
                throw new RuntimeException('Escolha um agente válido para a agenda.');
            }$command = [...$command, '--agent', (string) $arguments['agent']];
        }

        return $command;
    }

    private function revision(Connection $connection, Project $project, array $arguments): array
    {
        $ref = $arguments['resource'];
        $info = $this->lumo->run($connection, ['info', $ref], $project);
        $needsDiff = str_starts_with($arguments['action'], 'publish') || in_array($arguments['action'], ['push', 'run_schedule', 'resume_schedule'], true);
        $diff = $needsDiff ? $this->lumo->run($connection, ['diff', $ref, '--include-remote'], $project) : [];
        $revision = ['tenant_id' => $project->tenant_id, 'resource' => $ref, 'info' => $info, 'changes' => $diff];
        if (in_array($arguments['action'], ['run_schedule', 'resume_schedule'], true)) {
            $schedule = app(BiCatalog::class)->workingMetadata($project)[$ref] ?? null;
            if (! is_array($schedule) || ($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
                throw new RuntimeException('A configuração da agenda está indisponível, alterada ou não sincronizada. Revise a agenda antes de executá-la.');
            }
            $revision['schedule'] = Arr::only($schedule, ['nome', 'ativo', 'tokenId', 'timezone', 'triggers', 'flows']);
            foreach (['last_log', 'last_log_date'] as $key) {
                unset($revision['info']['data']['payload'][$key],$revision['info']['data'][$key]);
            }
        }

        return $revision;
    }
}
