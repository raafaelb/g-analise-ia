<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\BiLoadExecution;
use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\ArtifactImages;
use App\Services\AttachmentContext;
use App\Services\Audit;
use App\Services\BiCatalog;
use App\Services\BiCloneNames;
use App\Services\BiCredentials;
use App\Services\BiDesks;
use App\Services\BiDraftDeletions;
use App\Services\BiFlowClones;
use App\Services\BiFlowExecution;
use App\Services\BiFlowWizard;
use App\Services\BiMcpClient;
use App\Services\BiMutationGuard;
use App\Services\BiPublications;
use App\Services\BiResourceValidation;
use App\Services\BiScheduleRuns;
use App\Services\BiSchedules;
use App\Services\BiTablePresentation;
use App\Services\BiValidationFailure;
use App\Services\BiViewer;
use App\Services\BiWorkspaceSync;
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

class ActionBroker
{
    public function __construct(private LumoClient $lumo, private SafeWorkspace $files) {}

    public function manualRevision(User $user, Project $project, array $arguments): array
    {
        if ($project->user_id !== $user->id || $user->role === 'viewer' || (in_array($arguments['action'] ?? '', ['clone_app', 'clone_flow', 'publish_app', 'publish_flow'], true) && $project->status === 'archived')) {
            throw new RuntimeException('Este projeto não está disponível para alterações.');
        }
        if (! in_array($arguments['action'] ?? '', ['clone_app', 'clone_flow', 'delete_draft', 'publish_app', 'publish_flow'], true)) {
            throw new RuntimeException('Ação manual não permitida.');
        }
        $connection = app(BiCatalog::class)->connection($user);
        app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $this->command($arguments, $project), $connection);
        $context = $this->manualScope($user, $project, $arguments, $connection);
        if ($arguments['action'] === 'delete_draft') {
            $this->lumo->initialize($connection, $project, true);
            $this->managed($project, $arguments['resource']);
        }
        if (in_array($arguments['action'], ['publish_app', 'publish_flow'], true)) {
            $this->publicationDraft($project, $arguments['resource']);
            if ($arguments['action'] === 'publish_app' && $project->source_ref !== $arguments['resource']) {
                throw new RuntimeException('O recurso do projeto mudou. Confira a aplicação antes de publicar.');
            }
            $this->lumo->initialize($connection, $project);
        }
        $revision = $this->revision($connection, in_array($arguments['action'], ['publish_app', 'publish_flow', 'delete_draft'], true) ? $project : $context, $arguments);
        $latest = $connection->fresh();
        if ($latest->status !== 'connected' || ($latest->metadata['session_generation'] ?? null) !== ($connection->metadata['session_generation'] ?? null)) {
            throw new RuntimeException('A conexão mudou durante a preparação. Atualize seus acessos e abra uma nova revisão.');
        }
        $revision['connection_generation'] = $latest->metadata['session_generation'] ?? null;

        return $revision;
    }

    private function assertPublicationRecovery(AgentRun $run, Project $project, string $tool, array $arguments): void
    {
        $message = $run->input_message_id ? Message::find($run->input_message_id) : null;
        $id = $message?->role === 'system' ? ($message->metadata['publication_recovery_approval_id'] ?? null) : null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            return;
        }
        $approval = Approval::where('user_id', $run->user_id)->where('project_id', $project->id)->findOrFail($id);
        if ($approval->status === 'completed' && ($approval->payload['execution']['result']['publication']['verified'] ?? false) === true) {
            return;
        }
        if ($tool !== 'horus_action' || ($arguments['action'] ?? '') !== 'publication_status' || ($arguments['approval_id'] ?? null) !== $id) {
            throw new RuntimeException('Esta retomada permite somente conferir a publicação autorizada com publication_status e approval_id. Não envie outra publicação nem altere recursos enquanto o resultado estiver pendente.');
        }
    }

    private function manualScope(User $user, Project $project, array $arguments, Connection $connection): Project
    {
        $catalog = app(BiCatalog::class);
        [, $tenant] = $catalog->access($user, (string) $project->tenant_id);
        $context = $catalog->context($connection, $tenant);
        $this->lumo->initialize($connection, $context, true);
        $kind = explode(':', $arguments['resource'], 2)[0];
        $id = explode(':', $arguments['resource'], 2)[1];
        abort_unless(collect($catalog->rows($connection, $context, $kind))->contains(fn (array $row): bool => (string) $row['id'] === $id), 404, 'Recurso não disponível neste tenant para sua sessão.');

        return $context;
    }

    public function createReview(User $user, Project $project, array $arguments, array $revision, ?AgentRun $run = null): Approval
    {
        if ($project->user_id !== $user->id || $user->role === 'viewer' || (string) ($revision['tenant_id'] ?? '') !== (string) $project->tenant_id || ($revision['resource'] ?? '') !== ($arguments['resource'] ?? '')) {
            throw new RuntimeException('Revisão fora do contexto autorizado.');
        }
        $action = $arguments['action'];
        if ($run === null && ! in_array($action, ['clone_app', 'clone_flow', 'delete_draft', 'publish_app', 'publish_flow'], true)) {
            throw new RuntimeException('Ação manual não permitida.');
        }
        $payload = ['action' => $arguments, 'revision' => $revision];
        if ($run === null) {
            $payload['origin'] = 'manual';
        }

        return Approval::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_run_id' => $run?->id, 'type' => $run === null ? 'manual' : (str_starts_with($action, 'publish') ? 'publish' : 'execution'), 'title' => match ($action) {
            'push' => 'Sincronizar alterações com o G-Analise BI', 'publish_app' => 'Publicar aplicação no G-Analise BI', 'publish_flow' => 'Publicar fonte de dados no G-Analise BI', 'run_schedule' => 'Executar carga de teste', 'run_flow_node' => 'Executar etapa do ETL', 'clone_app' => 'Duplicar aplicação no G-Analise BI', 'clone_flow' => 'Duplicar ETL no G-Analise BI', 'delete_draft' => 'Excluir rascunho criado por este projeto', default => 'Ativar agenda no G-Analise BI'
        }, 'description' => $run === null ? 'Confira o tenant, o recurso e o resultado esperado. Esta operação usa sua conexão BI e não inicia uma tarefa de IA.' : 'Confira tenant, recurso, destino e alterações. Após a autorização, o portal executará esta revisão e retomará a IA com o resultado.', 'payload' => $payload, 'fingerprint' => hash('sha256', json_encode([$user->id, $project->id, $project->tenant_id, $arguments, $revision], JSON_THROW_ON_ERROR)), 'expires_at' => now()->addMinutes(12)]);
    }

    public function tools(): array
    {
        return [
            ['name' => 'bi_data', 'description' => 'Consulta dados reais, indicadores, estrutura e valores da aplicação vinculada ao projeto pelo MCP individual do usuário. Somente leitura, sem SQL e sem criar/alterar recursos. Antes de query, use get_app/search_catalog para obter refs e indicadores; use search_values para localizar valores sem inventá-los. O MCP exige autorização própria do ambiente na página Conexões. As permissões são as da conta autorizada no MCP. Nunca peça tokens ou senhas no chat. Para descobrir bancos de origem ou executar ETL use horus_action.', 'inputSchema' => ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'enum' => BiMcpClient::TOOLS], 'arguments' => ['type' => 'object', 'description' => 'get_app: {}. search_catalog: {terms:[palavras]}. search_values: {ref:coluna,contains:trecho,mode:distinct ou sample}; ref ou contains é necessário. query: {fields:[{ref:coluna ou expressão,agg:sum/avg/min/max/count/count_distinct,bucket:day/month/year}],filters:[{ref,op,value}],orderBy:[{ref,dir:asc ou desc}],limit:1 a 500}. agg e bucket são opcionais, use no máximo um por campo. Filtros aceitam =, !=, >, <, >=, <=, between, not between, in, not in, like, not like, is null e is not null. Intervalos usam dois valores; in usa lista. Nunca inclua appId, tenant, resource ou SQL.']], 'required' => ['operation', 'arguments'], 'additionalProperties' => false]],
            ['name' => 'tenant_variables', 'description' => 'Lista nomes e referências das variáveis do tenant do projeto. Os valores são protegidos: nunca devolve senhas, chaves ou outros valores. Use a referência no recurso BI.', 'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
            ['name' => 'refresh_coverage', 'description' => 'Confere todas as fontes de uma aplicação no tenant do projeto, agendas diárias ou mais frequentes, resultados reais e a execução de teste correlacionada. Somente leitura remota; não cria agendas ou executa cargas.', 'inputSchema' => ['type' => 'object', 'properties' => ['resource' => ['type' => 'string', 'pattern' => '^app:[1-9][0-9]*$']], 'required' => ['resource'], 'additionalProperties' => false]],
            ['name' => 'project_image', 'description' => 'Devolve a imagem PNG de um dashboard exportado para análise visual somente após autorização explícita do usuário no portal para esse arquivo e esta conversa. Não gera nova imagem. Use o artifact_id retornado pelo export.', 'inputSchema' => ['type' => 'object', 'properties' => ['artifact_id' => ['type' => 'string']], 'required' => ['artifact_id'], 'additionalProperties' => false]],
            ['name' => 'horus_action', 'description' => 'Opera somente o tenant do projeto pela sessão G-Analise do usuário. Use para listar, inspecionar, criar rascunhos, clonar, validar, sincronizar, gerar prévia e publicar após revisão humana. delete_draft exclui somente aplicações ou ETLs em rascunho criados por este projeto e registrados pelo sistema; ETLs incluem suas tabelas privadas e dados de teste na revisão. Recursos preexistentes são recusados. deletion_status confere uma tentativa já autorizada de exclusão de aplicação ou ETL com approval_id, sem reenviar a remoção. refresh_resource recebe a versão atual do BI somente se não houver edições locais pendentes; nunca descarta arquivos ou força conflitos. Para investigar o banco use list com kind credential, credential_schema e credential_relationships para conferir vínculos declarados; não peça ao usuário nomes técnicos de tabelas. clone_flow cria uma cópia privada do ETL após revisão, com novos IDs de etapas/tabelas; dados exigem teste de carga separado. run_flow_node executa uma etapa ETL em rascunho após revisão; flow_preview lê seu resultado já executado. app_link retorna a URL real da aplicação sem credenciais. publication_status confere uma publicação já autorizada neste projeto com approval_id, sem publicar novamente. preview é a conferência de dados de um app, nunca uma prévia de ETL. lint exige resource e devolve os erros do YAML para corrigir sem pedir intervenção técnica do usuário. Nunca execute lumo pelo shell.', 'inputSchema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'info', 'columns', 'table_preview', 'lint', 'diff', 'refresh_resource', 'scaffold', 'new', 'clone_app', 'clone_flow', 'push', 'preview', 'export', 'app_link', 'flow_preview', 'run_flow_node', 'credential_schema', 'credential_relationships', 'publish_app', 'publish_flow', 'flow_status', 'flow_logs', 'schedule_info', 'schedule_logs', 'load_status', 'publication_status', 'deletion_status', 'run_schedule', 'resume_schedule', 'delete_draft']], 'execution_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'ID do acompanhamento retornado por run_schedule; load_status apenas confere o resultado, sem reenviar a carga.'], 'approval_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Revisão já autorizada neste projeto. publication_status confere uma publicação e deletion_status confere a exclusão de uma aplicação ou ETL; ambas apenas consultam o resultado existente, sem reenviar a operação.'], 'kind' => ['type' => 'string', 'enum' => ['app', 'table', 'flow', 'schedule', 'bi-desk', 'dw-desk', 'agent', 'widget', 'expression', 'node', 'fact', 'report', 'calendar', 'credential']], 'resource' => ['type' => 'string'], 'name' => ['type' => 'string'], 'template' => ['type' => 'string', 'description' => 'Variante do scaffold, por exemplo Kpi, Barline, contexts ou postgresql.'], 'flow' => ['type' => 'string'], 'node' => ['type' => 'string', 'description' => 'internalId da etapa. Para new/table, prefira a origem com esquema capturado diretamente ligada à única inserção sem tabela existente.'], 'agent' => ['type' => 'integer'], 'desk' => ['type' => 'integer'], 'publish_mode' => ['type' => 'string', 'enum' => ['new', 'replace'], 'description' => 'Publicar como novo recurso (padrão) ou substituir o original na mesma mesa. ETLs usam mesas de dados. Substituição exige revisão explícita do alvo.'], 'copy_data' => ['type' => 'boolean', 'description' => 'Somente publish_flow em modo replace: solicita copiar também os dados da tabela da cópia para a original; padrão false. Exige revisão específica do efeito nos dados.'], 'dashboard' => ['type' => 'integer'], 'schema' => ['type' => 'string', 'maxLength' => 128], 'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Busca na estrutura; em credential_relationships filtra nomes das tabelas, schemas ou do vínculo, preservando todos os seus campos.'], 'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'description' => 'Use next_offset da resposta para continuar o catálogo.']], 'required' => ['action'], 'additionalProperties' => false]],
            ['name' => 'workspace_file', 'description' => 'Lista, lê e escreve arquivos YAML e notas do workspace. Anexos extraídos são somente leitura e estão limitados à seleção desta tarefa; arquivos já enviados podem permanecer no histórico. Não permite caminhos absolutos, arquivos internos, credenciais ou exclusões. Leia o arquivo antes de editar. Para substituir um arquivo existente, expected_sha256 é obrigatório e deve corresponder ao conteúdo lido; omita-o somente ao criar um arquivo novo.', 'inputSchema' => ['type' => 'object', 'properties' => ['operation' => ['type' => 'string', 'enum' => ['list', 'read', 'write']], 'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'expected_sha256' => ['type' => 'string']], 'required' => ['operation'], 'additionalProperties' => false]],
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
        if (! $user->canStartWork() || $user->role === 'viewer') {
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
        $this->assertPublicationRecovery($run, $project, $tool, $arguments);
        if ($tool === 'bi_data') {
            if (array_diff(array_keys($arguments), ['operation', 'arguments']) !== [] || ! is_string($arguments['operation'] ?? null) || ! is_array($arguments['arguments'] ?? null) || ! preg_match('/^app:([1-9][0-9]*)$/D', (string) $project->source_ref, $match)) {
                throw new RuntimeException('Vincule uma aplicação ao projeto e informe uma consulta MCP válida.');
            }

            return app(BiMcpClient::class)->invoke($user, (string) $project->tenant_id, (int) $match[1], $arguments['operation'], $arguments['arguments'], $project->id);
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
            return $this->files->file($project, $arguments, app(AttachmentContext::class)->idsForRun($run));
        }
        if ($tool !== 'horus_action') {
            throw new RuntimeException('Ferramenta não autorizada.');
        }
        if (($arguments['action'] ?? '') === 'publication_status') {
            $id = $arguments['approval_id'] ?? '';
            abort_unless(is_string($id) && Str::isUuid($id), 422, 'Informe a revisão de publicação deste projeto.');
            $result = app(BiPublications::class)->reconcileForAgent($run, $id);

            return ['status' => 'confirmed', 'approval_id' => $id, 'publication' => $result['approval']->payload['execution']['result']['publication'], 'remote_read_only' => true, 'message' => 'Resultado da publicação conferido sem novo envio. A confirmação de configuração não comprova carga ou atualização dos dados.'];
        }
        if (($arguments['action'] ?? '') === 'deletion_status') {
            $id = $arguments['approval_id'] ?? '';
            abort_unless(is_string($id) && Str::isUuid($id), 422, 'Informe a revisão de exclusão deste projeto.');
            $result = app(BiDraftDeletions::class)->reconcile($user, $id, $run);

            return ['status' => 'confirmed', 'approval_id' => $id, 'deletion' => $result['approval']->payload['execution']['result']['deletion'], 'remote_read_only' => true];
        }
        if (($arguments['action'] ?? '') === 'load_status') {
            $id = $arguments['execution_id'] ?? '';
            abort_unless(is_string($id) && Str::isUuid($id), 422, 'Informe o acompanhamento desta carga.');
            $execution = BiLoadExecution::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->findOrFail($id);

            return app(BiScheduleRuns::class)->observe($user, $execution->id);
        }
        $command = $this->command($arguments, $project);
        app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $command, $connection);
        $action = $arguments['action'];
        if ($run->status === 'awaiting_approval') {
            return ['status' => 'awaiting_approval', 'message' => 'Há uma revisão pendente nesta tarefa. Informe isso ao usuário e encerre o turno sem repetir ações.'];
        }
        if ($action === 'refresh_resource') {
            return app(BiWorkspaceSync::class)->refresh($user, $project, $arguments['resource']);
        }
        if ($action === 'delete_draft') {
            $this->managed($project, $arguments['resource']);
        }
        if ($action === 'push') {
            $validation = app(BiResourceValidation::class)->inspect($connection, $project, $arguments['resource']);
            if (! $validation['passed']) {
                return ['status' => 'validation_failed', 'validation' => $validation, 'message' => 'Corrija o arquivo local com os detalhes da validação e solicite uma nova revisão somente depois de validar. Nenhuma alteração foi enviada ao BI.'];
            }
        }
        if (in_array($action, ['clone_flow', 'push', 'publish_app', 'publish_flow', 'run_schedule', 'run_flow_node', 'resume_schedule', 'delete_draft'], true)) {
            $revision = $this->revision($connection, $project, $arguments);
            $approval = $this->createReview($user, $project, $arguments, $revision, $run);
            $run->update(['status' => 'awaiting_approval', 'heartbeat_at' => now()]);

            return ['status' => 'awaiting_approval', 'approval_id' => $approval->id, 'resource' => $arguments['resource'], 'message' => 'A alteração foi preparada para revisão. Encerre este turno e informe que a IA será retomada automaticamente após a decisão. Nenhuma ação desta solicitação foi executada ainda.'];
        }

        return $this->perform($user, $project, $connection, $arguments);
    }

    public function performApproved(Approval $approval): array
    {
        $actor = User::find($approval->user_id);
        if (! $actor?->canStartWork()) {
            throw new RuntimeException('O acesso do usuário está inativo ou requer atualização da senha.');
        }
        if (! $approval->permitsUserAccess($actor)) {
            throw new RuntimeException('A autorização pertence a uma versão anterior do seu acesso. Prepare uma nova revisão.');
        }
        if ($approval->status !== 'executing' || $approval->expires_at->isPast()) {
            throw new RuntimeException('A autorização não está válida para execução.');
        }
        if (($approval->payload['action']['action'] ?? '') === 'run_schedule_now') {
            return app(BiScheduleRuns::class)->executeApproved($approval);
        }
        if (in_array($approval->payload['action']['action'] ?? '', BiSchedules::ACTIONS, true)) {
            return app(BiSchedules::class)->executeApproved($approval);
        }
        if (in_array($approval->payload['action']['action'] ?? '', BiTablePresentation::ACTIONS, true)) {
            return app(BiTablePresentation::class)->executeApproved($approval);
        }
        if (in_array($approval->payload['action']['action'] ?? '', BiDesks::ACTIONS, true)) {
            return app(BiDesks::class)->executeApproved($approval);
        }
        if (in_array($approval->payload['action']['action'] ?? '', BiFlowWizard::ACTIONS, true)) {
            return app(BiFlowWizard::class)->executeApproved($approval);
        }
        if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
            return app(BiCredentials::class)->executeApproved($approval);
        }
        $user = User::findOrFail($approval->user_id);
        $project = Project::where('user_id', $user->id)->findOrFail($approval->project_id);
        if (! $user->canStartWork() || $user->role === 'viewer') {
            throw new RuntimeException('O perfil atual permite somente leitura.');
        }
        $arguments = $approval->payload['action'] ?? [];
        $manual = $approval->type === 'manual';
        if ($manual && ($approval->agent_run_id !== null || ($approval->payload['origin'] ?? '') !== 'manual')) {
            throw new RuntimeException('A origem da revisão manual é inválida.');
        }
        if ($manual && in_array($arguments['action'] ?? '', ['clone_app', 'clone_flow'], true) && ($project->status === 'archived' || $project->source_ref !== null)) {
            throw new RuntimeException('O projeto mudou após a revisão. Prepare uma nova operação.');
        }
        $allowed = $manual ? ['clone_app', 'clone_flow', 'delete_draft', 'publish_app', 'publish_flow'] : ['clone_flow', 'push', 'publish_app', 'publish_flow', 'run_schedule', 'run_flow_node', 'resume_schedule', 'delete_draft'];
        if (! in_array($arguments['action'] ?? '', $allowed, true)) {
            throw new RuntimeException('Ação de revisão inválida.');
        }
        if ($manual && in_array($arguments['action'] ?? '', ['publish_app', 'publish_flow'], true)) {
            if ($project->status === 'archived' || ($arguments['action'] === 'publish_app' && $project->source_ref !== ($arguments['resource'] ?? null))) {
                throw new RuntimeException('O projeto mudou após a revisão. Prepare uma nova publicação.');
            }
            $this->publicationDraft($project, $arguments['resource']);
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
        if ($manual) {
            $this->manualScope($user, $project, $arguments, $connection);
        }
        $execute = function () use ($approval, $user, $project, $connection, $arguments, $manual, $revision): array {
            $currentRevision = $this->revision($connection, $project, $arguments);
            if ($manual) {
                $currentRevision['connection_generation'] = $connection->metadata['session_generation'] ?? null;
            }
            if (! hash_equals(hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($currentRevision, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('O recurso mudou após a revisão. Uma nova autorização é necessária.');
            }
            if ($approval->agent_run_id !== null) {
                AgentRun::whereKey($approval->agent_run_id)->update(['heartbeat_at' => now()]);
            }

            return $this->perform($user, $project, $connection, $arguments, $manual, $currentRevision['publication'] ?? null, $approval);
        };
        if (in_array($arguments['action'] ?? '', ['publish_app', 'publish_flow'], true) && ($revision['publication']['mode'] ?? '') === 'replace') {
            $target = $revision['publication']['target']['resource'];

            return Cache::lock('bi-publish:'.$project->tenant_id.':'.$target, 630)->block(5, $execute);
        }

        return $execute();
    }

    private function perform(User $user, Project $project, Connection $connection, array $arguments, bool $approvedManual = false, ?array $publication = null, ?Approval $approval = null): array
    {
        if (in_array($arguments['action'] ?? '', ['push', 'resume_schedule'], true) && str_starts_with($arguments['resource'] ?? '', 'schedule:')) {
            return Cache::lock('schedule-write:'.$project->tenant_id, 630)->block(5, function () use ($user, $project, $connection, $arguments, $approvedManual, $publication, $approval) {
                app(ScheduleChanges::class)->inspect($user, $project, $arguments['resource'], $arguments['action'] === 'resume_schedule');

                return $this->performUnlocked($user, $project, $connection, $arguments, $approvedManual, $publication, $approval);
            });
        }

        return $this->performUnlocked($user, $project, $connection, $arguments, $approvedManual, $publication, $approval);
    }

    private function performUnlocked(User $user, Project $project, Connection $connection, array $arguments, bool $approvedManual = false, ?array $publication = null, ?Approval $approval = null): array
    {
        $command = $this->command($arguments, $project);
        app(TenantPolicy::class)->assertCommandAllowed((string) $project->tenant_id, $command, $connection);
        $action = $arguments['action'];
        if ($action === 'new' && ($arguments['kind'] ?? '') === 'table') {
            app(BiFlowExecution::class)->assertTableCreation($connection, $project, $arguments);
        }
        if ($action === 'lint') {
            return app(BiResourceValidation::class)->inspect($connection, $project, $arguments['resource']);
        }
        if ($action === 'run_flow_node') {
            if (! $approval) {
                throw new RuntimeException('A execução de ETL exige a revisão autorizada desta etapa.');
            }
            $result = app(BiFlowExecution::class)->run($connection, $project, $arguments, $approval);
            Audit::record($user->id, 'horus.run_flow_node', 'Etapa ETL executada após revisão.', $project->id, ['resource' => $arguments['resource'], 'node' => $arguments['node']]);
            Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$project->tenant_id);

            return $result;
        }
        if ($action === 'flow_preview') {
            return app(BiFlowExecution::class)->preview($connection, $project, $arguments);
        }
        if (in_array($action, ['credential_schema', 'credential_relationships'], true)) {
            $report = app(BiCredentials::class)->inspect($user, (string) $project->tenant_id, substr($arguments['resource'], 11), $action === 'credential_relationships' ? 'relationships' : 'schema', $arguments['schema'] ?? null, $arguments['search'] ?? null, (int) ($arguments['offset'] ?? 0));
            Audit::record($user->id, 'horus.'.$action, 'Metadados da fonte consultados pela tarefa do projeto.', $project->id, ['resource' => $arguments['resource'], 'verified' => $report['verified'], ($action === 'credential_relationships' ? 'relationship_count' : 'table_count') => count($report[$action === 'credential_relationships' ? 'relationships' : 'tables'] ?? []), 'truncated' => $report['truncated'] ?? null]);
            if ($action === 'credential_schema' && ! isset($arguments['search']) && strlen(json_encode($report, JSON_THROW_ON_ERROR)) > 60000) {
                $report['tables'] = array_map(fn (array $table): array => ['schema' => $table['schema'] ?? null, 'name' => $table['name'], 'column_count' => count($table['columns'] ?? []), 'visible_column_count' => $table['visible_column_count'] ?? null, 'columns_complete' => $table['columns_complete'] ?? null, 'columns_truncated' => $table['columns_truncated'] ?? null], $report['tables'] ?? []);
                $report['columns_omitted'] = true;
                $report['message'] = 'Catálogo resumido para a IA. Use search para buscar o assunto solicitado e receber as colunas. Se houver next_offset, a lista ainda tem outras páginas.';
            }

            return $report;
        }
        if ($action === 'app_link') {
            $detail = app(BiCatalog::class)->detail($user, (string) $project->tenant_id, 'app', substr($arguments['resource'], 4), false);
            if (isset($arguments['dashboard']) && ! collect($detail['resource']['dashboards'] ?? [])->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $arguments['dashboard'])) {
                throw new RuntimeException('O dashboard não pertence à aplicação selecionada.');
            }
            $result = $this->lumo->run($connection, $command, $project, 60);
            $url = app(BiViewer::class)->plainUrl($result['data'], substr($arguments['resource'], 4));

            Audit::record($user->id, 'horus.app_link', 'Link comum da aplicação consultado.', $project->id, ['resource' => $arguments['resource']]);

            return ['resource' => $arguments['resource'], 'url' => $url, 'desk_id' => $detail['resource']['desk_id'] ?? null, 'state' => $detail['resource']['state'] ?? null, 'preview_path' => $project->source_ref === $arguments['resource'] ? route('workspace', ['section' => 'project/'.$project->id], absolute: false).'?preview=interactive'.(isset($arguments['dashboard']) ? '&dashboard='.$arguments['dashboard'] : '') : null, 'message' => 'URL oficial sem credenciais. Quem abrir usará o próprio login BI. Para a prévia dentro da conversa, use preview_path.'];
        }
        if ($action === 'delete_draft') {
            if (! $approval) {
                throw new RuntimeException('A exclusão do rascunho exige revisão autorizada.');
            }

            return app(BiDraftDeletions::class)->execute($approval, $connection, $project, app(BiDraftDeletions::class)->plan($approval->payload['revision'] ?? []) ?? []);
        }
        $this->assertRefreshBeforePublish($user, $project, $arguments);
        if (in_array($action, ['publish_app', 'publish_flow'], true) && (! $approval || ! is_array($publication) || ($publication['source']['resource'] ?? null) !== $arguments['resource'] || ($publication['destination']['id'] ?? null) !== $arguments['desk'] || ($publication['mode'] ?? null) !== ($arguments['publish_mode'] ?? 'new'))) {
            throw new RuntimeException('A publicação exige uma revisão atual do destino e do efeito esperado.');
        }
        if (in_array($action, ['push', 'publish_app', 'publish_flow'], true)) {
            $validation = app(BiResourceValidation::class)->inspect($connection, $project, $arguments['resource']);
            if (! $validation['passed']) {
                throw new BiValidationFailure($validation);
            }
        }
        if ($action === 'export') {
            File::delete(app(WorkspaceFiles::class)->path($project).'/exports/dashboard.png');
        }
        if (in_array($action, ['publish_app', 'publish_flow'], true)) {
            if (isset($approval->payload['publication_attempt'])) {
                throw new RuntimeException('Uma tentativa desta publicação já foi iniciada. Confira o resultado sem reenviar a operação.');
            }
            $approval->update(['payload' => [...$approval->payload, 'publication_attempt' => ['plan_sha256' => hash('sha256', json_encode($publication, JSON_THROW_ON_ERROR)), 'started_at' => now()->toIso8601String()]]]);
        }
        if ($action === 'run_schedule') {
            if (! $approval) {
                throw new RuntimeException('A carga exige uma autorização atual.');
            }

            return app(BiScheduleRuns::class)->send($user, $connection, (string) $project->tenant_id, substr($arguments['resource'], 9), $approval, $approval->payload['revision']['load_plan'] ?? [], $project);
        }
        if ($action === 'clone_flow') {
            if (! $approval || isset($approval->payload['flow_clone_attempt'])) {
                throw new RuntimeException('A duplicação do ETL exige uma revisão ainda não enviada. Confira o catálogo antes de repetir uma tentativa.');
            }
            $approval->update(['payload' => [...$approval->payload, 'flow_clone_attempt' => ['started_at' => now()->toIso8601String(), 'resource' => $arguments['resource']]]]);
        }
        $result = $this->lumo->run($connection, $command, $project, $action === 'export' ? 180 : 90);
        if (array_key_exists('data', $result)) {
            $result['data'] = app(BiCatalog::class)->redact($result['data']);
        }
        if (in_array($action, ['new', 'clone_app', 'clone_flow'], true)) {
            $result = $this->recordCreated($project, $arguments, $result);
        }
        if (in_array($action, ['clone_app', 'clone_flow'], true) && trim((string) ($arguments['name'] ?? '')) !== '') {
            $result['name_verification'] = ['verified' => false, 'requested_name' => $arguments['name']];
            if (($approvedManual || $action === 'clone_flow') && isset($result['managed_resource'])) {
                try {
                    $result['name_verification'] = app(BiCloneNames::class)->ensure($connection, $project, $result['managed_resource'], $arguments['name']);
                    $result['confirmed_name'] = $result['name_verification']['name'];
                    Audit::record($user->id, 'horus.clone_name.confirmed', 'Nome da cópia confirmado no BI.', $project->id, ['resource' => $result['managed_resource']]);
                } catch (\Throwable $e) {
                    $result['management_warning'] = 'A cópia foi criada, mas o nome escolhido ainda não pôde ser confirmado. Abra o projeto para conferir o recurso antes de solicitar outra duplicação.';
                    Audit::record($user->id, 'horus.clone_name.pending', 'A cópia foi criada; a confirmação do nome requer atenção.', $project->id, ['resource' => $result['managed_resource'], 'exception_type' => get_class($e), 'reason' => get_class($e) === RuntimeException::class ? $e->getMessage() : 'Não foi possível validar o nome.']);
                }
            } elseif (! $approvedManual) {
                $result['name_verification']['message'] = 'Confira o nome real com info antes de afirmar que o nome foi aplicado. Se houver divergência, prepare uma alteração de nome no YAML e uma revisão de push, sem duplicar novamente.';
            }
        }
        if ($action === 'clone_flow') {
            $result['flow_clone'] = ['verified' => false, 'requires_load_test' => true];
            if (isset($result['managed_resource'])) {
                try {
                    $result['flow_clone'] = app(BiFlowClones::class)->confirm($connection, $project, $result['managed_resource'], $approval->payload['revision']);
                } catch (\Throwable $e) {
                    $result['management_warning'] = 'A cópia do ETL foi criada, mas as etapas e tabelas precisam de conferência. Abra o projeto e consulte os recursos antes de executar cargas ou solicitar outra duplicação.';
                    Audit::record($user->id, 'horus.flow_clone.unverified', 'Cópia de ETL criada; conferência final pendente.', $project->id, ['resource' => $result['managed_resource'], 'exception_type' => get_class($e)]);
                }
            }
        }
        if ($action === 'clone_flow') {
            $result['data'] = ['cloned_resource' => $result['managed_resource'] ?? null];
        }
        if (in_array($action, ['publish_app', 'publish_flow'], true)) {
            $approval->update(['payload' => [...$approval->payload, 'publication_attempt' => [...$approval->payload['publication_attempt'], 'native_accepted_at' => now()->toIso8601String()]]]);
            Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$project->tenant_id);
            $managed = ManagedBiResource::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->first();
            if ($managed) {
                $managed->update(['status' => 'publication_pending', 'receipt' => [...($managed->receipt ?? []), 'publication' => ['expected_resource' => $publication['target']['resource'], 'mode' => $publication['mode'], 'destination' => $publication['destination'], 'native_accepted_at' => now()->toIso8601String()]]]);
            }
            Audit::record($user->id, 'horus.publish.accepted', 'Publicação aceita pelo BI; conferência do resultado em andamento.', $project->id, ['resource' => $arguments['resource'], 'expected_resource' => $publication['target']['resource'], 'mode' => $publication['mode']]);
            try {
                $confirmation = app(BiPublications::class)->verify($connection, $project, $publication);
            } catch (\Throwable $e) {
                Audit::record($user->id, 'horus.publish.unverified', 'O resultado da publicação ainda precisa ser conferido no catálogo.', $project->id, ['resource' => $arguments['resource'], 'expected_resource' => $publication['target']['resource'], 'exception_type' => get_class($e)]);
                throw new RuntimeException('O BI aceitou a publicação, mas a confirmação final ficou pendente. Confira '.$publication['target']['resource'].' na mesa “'.$publication['destination']['name'].'” antes de solicitar outra publicação.');
            }
            app(BiPublications::class)->recordVerified($project, $arguments['resource'], $confirmation);
            $result['publication'] = $confirmation;
            $result['published_resource'] = $confirmation['resource'];
            $result['confirmed_name'] = $confirmation['name'];
            if ($action === 'publish_flow') {
                $result['data'] = ['published_resource' => $confirmation['resource']];
            }
        }
        if ($action === 'info') {
            $managed = ManagedBiResource::where('user_id', $user->id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $arguments['resource'])->first();
            if ($managed) {
                $result['project_provenance'] = ['resource' => $managed->resource, 'origin' => $managed->origin, 'source_ref' => $managed->source_ref, 'status' => $managed->status, 'created_at' => $managed->receipt['created_at'] ?? null];
            }
        }
        if (in_array($action, ['new', 'clone_app', 'clone_flow', 'push', 'publish_app', 'publish_flow', 'delete_draft', 'resume_schedule', 'run_schedule', 'run_flow_node'], true)) {
            Cache::forget('bi-catalog:v3:'.$connection->id.':'.($connection->metadata['session_generation'] ?? 'initial').':'.$project->tenant_id);
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
                $result['preview_path'] = $project->source_ref === $arguments['resource'] ? route('workspace', ['section' => 'project/'.$project->id], absolute: false).'?preview=interactive' : null;
                $result['visual_review'] = 'Abra a prévia no portal para autorizar o envio deste arquivo à OpenAI; depois use project_image com artifact_id.';
            }
        }

        return $result;
    }

    public function command(array $arguments, Project $project): array
    {
        $action = $arguments['action'] ?? '';
        $ref = (string) ($arguments['resource'] ?? '');
        if ($ref !== '' && ! preg_match('/^(app|flow|table|schedule|agent|bi-desk|dw-desk|credential):[1-9][0-9]*$/D', $ref)) {
            throw new RuntimeException('Referência G-Analise inválida.');
        }
        $required = ['lint', 'info', 'columns', 'table_preview', 'diff', 'refresh_resource', 'clone_app', 'clone_flow', 'push', 'preview', 'export', 'app_link', 'flow_preview', 'run_flow_node', 'credential_schema', 'credential_relationships', 'publish_app', 'publish_flow', 'flow_status', 'flow_logs', 'schedule_info', 'schedule_logs', 'run_schedule', 'resume_schedule', 'delete_draft'];
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

        if (! in_array($kind, ['app', 'table', 'flow', 'schedule', 'bi-desk', 'dw-desk', 'agent', 'credential'], true)) {
            throw new RuntimeException('Tipo de recurso inválido.');
        }
        $appActions = ['clone_app', 'preview', 'export', 'app_link', 'publish_app'];
        if ($action === 'delete_draft' && ! preg_match('/^(app|flow):[1-9][0-9]*$/D', $ref)) {
            throw new RuntimeException('A exclusão de rascunhos aceita somente aplicações e ETLs criados pelo projeto.');
        }
        $flowActions = ['clone_flow', 'publish_flow', 'flow_status', 'flow_logs', 'flow_preview', 'run_flow_node'];
        $scheduleActions = ['schedule_info', 'schedule_logs', 'run_schedule', 'resume_schedule'];
        if ((in_array($action, $appActions, true) && ! str_starts_with($ref, 'app:')) || (in_array($action, $flowActions, true) && ! str_starts_with($ref, 'flow:')) || (in_array($action, $scheduleActions, true) && ! str_starts_with($ref, 'schedule:')) || (in_array($action, ['columns', 'table_preview'], true) && ! str_starts_with($ref, 'table:'))) {
            throw new RuntimeException('Tipo de referência incompatível com a operação.');
        }
        if (in_array($action, ['publish_app', 'publish_flow'], true) && (! isset($arguments['desk']) || ! is_int($arguments['desk']) || $arguments['desk'] < 1)) {
            throw new RuntimeException('Escolha uma mesa válida para publicar.');
        }
        if (str_starts_with($ref, 'credential:') && ! in_array($action, ['credential_schema', 'credential_relationships'], true)) {
            throw new RuntimeException('Use credential_schema para consultar a estrutura sem expor a configuração da conexão.');
        }
        if (in_array($action, ['credential_schema', 'credential_relationships'], true) && ! str_starts_with($ref, 'credential:')) {
            throw new RuntimeException('Escolha uma conexão válida para consultar a estrutura.');
        }
        if (in_array($action, ['flow_preview', 'run_flow_node'], true) && ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string) ($arguments['node'] ?? ''))) {
            throw new RuntimeException('Informe o identificador da etapa ETL.');
        }
        if (isset($arguments['schema']) && (! is_string($arguments['schema']) || ! preg_match('/^[\pL\pN_][\pL\pN_. -]{0,127}$/uD', $arguments['schema']))) {
            throw new RuntimeException('O schema informado é inválido.');
        }
        if ((isset($arguments['search']) && (! is_string($arguments['search']) || ! preg_match('/^[\pL\pN_ .-]{1,100}$/uD', $arguments['search']))) || (isset($arguments['offset']) && (! is_int($arguments['offset']) || $arguments['offset'] < 0 || $arguments['offset'] > 1000000))) {
            throw new RuntimeException('Use um termo e uma posição de consulta de estrutura válidos.');
        }
        if ($action === 'delete_draft') {
            $this->managed($project, $ref);
        }
        $publicationFlags = [];
        if (in_array($action, ['publish_app', 'publish_flow'], true)) {
            $mode = $arguments['publish_mode'] ?? 'new';
            if (! in_array($mode, ['new', 'replace'], true)) {
                throw new RuntimeException('Modo de publicação inválido.');
            }
            if ($action === 'publish_flow') {
                if (isset($arguments['copy_data']) && ! is_bool($arguments['copy_data'])) {
                    throw new RuntimeException('A escolha de copiar dados deve ser verdadeira ou falsa.');
                }
                if (($arguments['copy_data'] ?? false) && $mode !== 'replace') {
                    throw new RuntimeException('Copiar dados só é permitido ao substituir a versão original.');
                }
                $publicationFlags = [...($mode === 'new' ? ['--new'] : []), ...(($arguments['copy_data'] ?? false) ? ['--copy-data'] : [])];
            } else {
                $publicationFlags = $mode === 'new' ? ['--new'] : ['--force'];
            }
        }
        $cloneName = trim((string) ($arguments['name'] ?? ''));
        if (mb_strlen($cloneName) > 150) {
            throw new RuntimeException('O nome da cópia deve ter até 150 caracteres.');
        }
        if ($action === 'clone_flow' && ($cloneName === '' || preg_match('/[\x00-\x1F\x7F]/', $cloneName))) {
            throw new RuntimeException('Informe um nome válido para a cópia do ETL.');
        }
        $dashboard = isset($arguments['dashboard']) ? ['--dashboard', (string) (int) $arguments['dashboard']] : [];

        $command = match ($action) {
            'list' => ['list', $kind, '--remote', '--limit', '500'], 'info' => ['info', $ref, ...(str_starts_with($ref, 'app:') ? ['--full'] : [])], 'columns' => ['columns', $ref], 'table_preview' => ['table', 'preview', $ref, '--limit', '20'], 'lint' => ['lint'], 'diff' => ['diff', $ref, '--include-remote'], 'refresh_resource' => ['pull', $ref],
            'scaffold' => ['scaffold', $kind],
            'new' => $this->newCommand($kind, $arguments),
            'clone_flow' => ['flow', 'clone', $ref, '--name', $cloneName],
            'clone_app' => ['app', 'clone', $ref, ...($cloneName !== '' ? ['--name', $cloneName] : [])], 'push' => ['push', $ref],
            'flow_preview' => ['flow', 'preview-node', $ref, '--node', $arguments['node']],
            'run_flow_node' => ['flow', 'run-node', $ref, '--node', $arguments['node']],
            'credential_schema', 'credential_relationships' => ['credential', 'schema', $ref],
            'app_link' => ['app', 'open', $ref, '--no-browser', ...$dashboard],
            'preview' => ['app', 'dashboard-preview', $ref, '--limit', '20', ...$dashboard],
            'export' => ['app', 'export', $ref, '--image-format', 'png', '--output', app(WorkspaceFiles::class)->path($project).'/exports/dashboard.png', ...$dashboard],
            'publish_app' => ['app', 'publish', $ref, '--desk', (string) $arguments['desk'], ...$publicationFlags], 'publish_flow' => ['flow', 'publish', $ref, '--desk', (string) $arguments['desk'], ...$publicationFlags],
            'delete_draft' => ['rm', $ref, '--force'],
            'flow_status' => ['flow', 'status', $ref], 'flow_logs' => ['flow', 'logs', $ref],
            'schedule_info' => ['info', $ref], 'schedule_logs' => ['schedule', 'logs', $ref, '--tail', '50'], 'run_schedule' => ['schedule', 'run', $ref], 'resume_schedule' => ['schedule', 'resume', $ref],
            default => throw new RuntimeException('Ação G-Analise não permitida.'),
        };
        if ($project->user_id !== null && $project->tenant_id !== null) {
            app(BiMutationGuard::class)->command($project, $command);
        }

        return $command;
    }

    private function publicationDraft(Project $project, string $ref): void
    {
        if (str_starts_with($ref, 'app:')) {
            $this->managed($project, $ref);

            return;
        }
        if (! preg_match('/^flow:[1-9][0-9]*$/D', $ref) || ($project->source_ref !== $ref && ! ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('status', 'draft')->exists())) {
            throw new RuntimeException('Abra o projeto deste ETL antes de preparar a publicação.');
        }
    }

    private function managed(Project $project, string $ref): ManagedBiResource
    {
        $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('status', 'draft')->first();
        if (! $managed || ! preg_match('/^(app|flow):[1-9][0-9]*$/D', $ref)) {
            throw new RuntimeException('A exclusão permite somente aplicações ou ETLs em rascunho criados e registrados por este projeto. Recursos preexistentes não podem ser excluídos.');
        }

        return $managed;
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
        $kind = match ($arguments['action']) {
            'clone_app' => 'app', 'clone_flow' => 'flow', default => $arguments['kind'] ?? 'app'
        };
        $data = $result['data'] ?? [];
        $candidates = is_array($data) ? [$data, $data['payload'] ?? [], $data['resource'] ?? [], $data['created'] ?? [], $data[$kind] ?? []] : [];
        $ref = null;
        foreach ($candidates as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['ref', 'resource_ref', 'resource', $kind.'_ref'] as $key) {
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
        if ($kind === 'app' && (! $project->source_ref || ($arguments['action'] === 'clone_app' && $project->source_ref === ($arguments['resource'] ?? null)))) {
            $project->update(['source_ref' => $ref]);
            $result['project_resource'] = $ref;
        }
        if ($arguments['action'] === 'clone_flow' && (! $project->source_ref || $project->source_ref === ($arguments['resource'] ?? null))) {
            $project->update(['source_ref' => $ref]);
            $result['project_resource'] = $ref;
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
                throw new RuntimeException('Criar tabela requer flow válido e identificador da etapa com esquema ou de inserção.');
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
        if ($arguments['action'] === 'delete_draft') {
            return app(BiDraftDeletions::class)->revision($connection, $project, $ref);
        }
        if ($arguments['action'] === 'clone_flow') {
            return app(BiFlowClones::class)->revision($connection, $project, $ref);
        }
        if ($arguments['action'] === 'run_flow_node') {
            return app(BiFlowExecution::class)->revision($connection, $project, $arguments);
        }
        if ($arguments['action'] === 'clone_app') {
            $data = $this->lumo->run($connection, ['info', $ref, '--full'], $project)['data'];
            $payload = is_array($data) ? ($data['payload'] ?? $data) : [];
            $body = $payload['body'] ?? null;
            if (! is_array($body) || ! is_array($body['dashboards'] ?? null) || (isset($payload['header']['tenantId']) && (string) $payload['header']['tenantId'] !== (string) $project->tenant_id)) {
                throw new RuntimeException('Não foi possível conferir a configuração completa da aplicação para duplicar.');
            }

            return ['tenant_id' => $project->tenant_id, 'resource' => $ref, 'info' => ['data' => ['id' => substr($ref, 4), 'nome' => $body['nome'] ?? $body['name'] ?? $ref, 'dashboard_count' => count($body['dashboards'])]], 'changes' => [], 'source_sha256' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR))];
        }
        if ($arguments['action'] === 'publish_flow') {
            $publication = app(BiPublications::class)->plan($connection, $project, $arguments);
            $diff = $this->lumo->run($connection, ['diff', $ref, '--include-remote'], $project);
            if (($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
                throw new RuntimeException('Sincronize e confira as alterações do rascunho do ETL antes de preparar a publicação.');
            }

            return ['tenant_id' => $project->tenant_id, 'resource' => $ref, 'info' => ['data' => ['id' => substr($ref, 5), 'nome' => $publication['source']['name']]], 'changes' => [], 'publication' => $publication];
        }
        $info = $this->lumo->run($connection, ['info', $ref, ...($arguments['action'] === 'publish_app' ? ['--full'] : [])], $project);
        $needsDiff = str_starts_with($arguments['action'], 'publish') || in_array($arguments['action'], ['push', 'run_schedule', 'resume_schedule'], true);
        $diff = $needsDiff ? $this->lumo->run($connection, ['diff', $ref, '--include-remote'], $project) : [];
        $revision = ['tenant_id' => $project->tenant_id, 'resource' => $ref, 'info' => $info, 'changes' => $diff];
        if (in_array($arguments['action'], ['run_schedule', 'resume_schedule'], true)) {
            $schedule = app(BiCatalog::class)->workingMetadata($project)[$ref] ?? null;
            if (! is_array($schedule) || ($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
                throw new RuntimeException('A configuração da agenda está indisponível, alterada ou não sincronizada. Revise a agenda antes de executá-la.');
            }
            $revision['schedule'] = Arr::only($schedule, ['nome', 'ativo', 'tokenId', 'timezone', 'triggers', 'flows']);
            if ($arguments['action'] === 'run_schedule') {
                $revision['load_plan'] = app(BiScheduleRuns::class)->plan($connection, (string) $project->tenant_id, substr($ref, 9));
                $revision['schedule'] = $revision['load_plan']['schedule'];
            }
            foreach (['last_log', 'last_log_date'] as $key) {
                unset($revision['info']['data']['payload'][$key],$revision['info']['data'][$key]);
            }
        }

        if ($arguments['action'] === 'publish_app') {
            if (($diff['data']['workingVsServer']['equal'] ?? false) !== true) {
                throw new RuntimeException('Sincronize e confira as alterações do rascunho antes de preparar a publicação.');
            }
            $this->assertRefreshBeforePublish(User::findOrFail($project->user_id), $project, $arguments);
            $revision['publication'] = app(BiPublications::class)->plan($connection, $project, $arguments);
        }

        return $revision;
    }
}
