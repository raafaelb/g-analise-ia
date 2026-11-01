<?php

namespace App\Services\Agent;

use App\Events\WorkspaceChanged;
use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Message;
use App\Models\User;
use App\Services\ArtifactImages;
use App\Services\AttachmentContext;
use App\Services\Audit;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use App\Services\ProcessEnvironment;
use App\Services\WorkspaceFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

class CodexRuntime
{
    public function __construct(private OpenAiIdentity $identity, private ActionBroker $broker, private LumoClient $lumo) {}

    public function run(AgentRun $run): void
    {
        if (! User::find($run->user_id)?->canStartWork()) {
            throw new RuntimeException('O acesso do usuário está inativo ou requer atualização da senha.');
        }
        $conversation = $run->conversation;
        $project = $conversation->project;
        $connection = Connection::where('user_id', $run->user_id)->where('provider', 'openai')->where('status', 'connected')->first();
        if (! $connection) {
            throw new RuntimeException('Conecte sua conta ChatGPT em Conexões para ativar a criação com IA. Sua mensagem foi salva.');
        }
        $token = $this->identity->accessToken($connection);
        $horus = Connection::where('user_id', $run->user_id)->where('provider', 'horus')->where('status', 'connected')->first();
        if (! $horus) {
            throw new RuntimeException('Conecte o G-Analise BI em Conexões para acessar seus dados. Sua mensagem foi salva.');
        }
        if (! $project->tenant_id) {
            throw new RuntimeException('Abra as configurações do projeto e escolha um tenant G-Analise antes de continuar.');
        }
        if (! is_executable(config('horus.codex.binary'))) {
            throw new RuntimeException('O cliente Codex ainda precisa ser instalado no worker.');
        }
        $this->lumo->initialize($horus, $project);
        $workspace = app(WorkspaceFiles::class)->prepare($project);
        File::ensureDirectoryExists($workspace.'/exports', 0700, true);
        $home = storage_path('app/private/codex/'.$run->user_id.'/'.$project->id);
        File::ensureDirectoryExists($home, 0700, true);
        File::replace($home.'/AGENTS.override.md', CodexProtocol::homeInstructions(), 0600);
        $execution = $home.'/execution';
        File::ensureDirectoryExists($execution, 0700, true);
        $model = $conversation->model ?? config('horus.codex.model');
        if (! $model) {
            $result = Http::withoutRedirecting()->withToken($token)->timeout(20)->get('https://api.openai.com/v1/models');
            if (! $result->successful()) {
                throw new RuntimeException('A OpenAI não retornou os modelos disponíveis. Reconecte sua conta.');
            }$models = $result->json('models') ?? $result->json('data') ?? [];
            $first = collect($models)->first(fn ($m) => ($m['visibility'] ?? 'list') === 'list');
            $model = $first['slug'] ?? $first['id'] ?? null;
        }
        if (! $model) {
            throw new RuntimeException('Não há modelo autorizado disponível para sua conta.');
        }
        $attachmentContext = app(AttachmentContext::class);
        $attachments = $attachmentContext->inputs($project, $attachmentContext->idsForRun($run));
        $tools = $this->broker->tools();
        $toolsHash = hash('sha256', json_encode([$tools, CodexProtocol::command(config('horus.codex.binary')), CodexProtocol::homeInstructions()], JSON_THROW_ON_ERROR));
        $toolsState = $home.'/tools-'.$conversation->id.'.sha256';
        $renewThread = $conversation->thread_id && (! is_file($toolsState) || ! hash_equals($toolsHash, trim(File::get($toolsState))));
        $threadId = $renewThread ? null : $conversation->thread_id;
        $command = CodexProtocol::command(config('horus.codex.binary'));
        $environment = ProcessEnvironment::isolated();
        $environment = array_merge($environment, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home, 'ACCESS_TOKEN' => $token, 'CODEX_HOME' => $home]);
        $input = new InputStream;
        $process = new Process($command, $execution, $environment);
        $process->setInput($input);
        $process->setTimeout(config('horus.codex.timeout') + 30);
        $send = fn (array $message) => $input->write(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        $assistant = Message::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => '', 'status' => 'streaming', 'metadata' => ['run_id' => $run->id]]);
        $buffer = new JsonRpcBuffer;
        $finished = false;
        $lastSave = 0;
        $lastHeartbeat = 0;
        $text = '';
        $lastAgentItem = null;
        try {
            $process->start();
            $send(['id' => 1, 'method' => 'initialize', 'params' => CodexProtocol::initialize()]);
            while ($process->isRunning()) {
                $process->checkTimeout();
                $now = microtime(true);
                if ($now - $lastHeartbeat > 2) {
                    $run->refresh();
                    if (in_array($run->status, ['cancel_requested', 'cancelled', 'failed'], true)) {
                        throw new RuntimeException('Tarefa interrompida pelo usuário.');
                    }$run->update(['heartbeat_at' => now()]);
                    $lastHeartbeat = $now;
                }
                foreach ($buffer->receive($process->getIncrementalOutput()) as $message) {
                    if (isset($message['error'])) {
                        throw new RuntimeException('O Codex não concluiu a solicitação: '.($message['error']['code'] ?? 'erro de protocolo').'.');
                    }
                    if (CodexProtocol::isResponse($message, 1)) {
                        $send(['method' => 'initialized', 'params' => (object) []]);
                        $params = CodexProtocol::thread($execution, $model, $tools, $threadId);
                        $send(['id' => 2, 'method' => $threadId ? 'thread/resume' : 'thread/start', 'params' => $params]);
                    }
                    if (CodexProtocol::isResponse($message, 2)) {
                        $thread = $message['result']['thread']['id'] ?? null;
                        if (! $thread) {
                            throw new RuntimeException('O Codex não retornou uma conversa válida.');
                        }$conversation->update(['thread_id' => $thread, 'model' => $model]);
                        File::put($toolsState, $toolsHash);
                        chmod($toolsState, 0600);
                        $messageInput = $run->input_message_id ? $conversation->messages()->findOrFail($run->input_message_id) : $conversation->messages()->where('role', 'user')->latest()->firstOrFail();
                        $prompt = $messageInput->content;
                        if ($messageInput->metadata['validation_repair'] ?? false) {
                            $prompt = "A validação offline falhou ANTES de enviar alterações ao BI. Nenhuma ação desta revisão foi aplicada. Leia os detalhes, corrija somente os arquivos deste recurso, valide com lint e prepare uma NOVA revisão; a aprovação anterior não autoriza o arquivo corrigido. Preserve o pedido de negócio do usuário e os recursos já criados. Não repita criações nem operações de resultado incerto.\n".$prompt."\n".json_encode($messageInput->metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        } elseif ($messageInput->metadata['continuation'] ?? false) {
                            $prompt = "Continue a tarefa anterior a partir do resultado confirmado da ação aprovada. Não repita essa ação e não recrie recursos já existentes. Confira o estado canônico, valide métricas e gere a prévia. Se precisar de outra alteração, prepare uma nova revisão.\n".$prompt."\n".json_encode($messageInput->metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }
                        if (! $threadId) {
                            $history = $conversation->messages()->where('id', '!=', $messageInput->id)->where('created_at', '<=', $messageInput->created_at)->where('status', 'completed')->whereIn('role', ['user', 'assistant', 'system'])->latest()->limit(20)->get(['role', 'content'])->reverse()->values()->toArray();
                            if ($history !== []) {
                                $prompt = CodexProtocol::history($history).$prompt;
                            }
                        }
                        $send(['id' => 3, 'method' => 'turn/start', 'params' => CodexProtocol::turn($thread, $prompt, $attachments)]);
                    }
                    $method = $message['method'] ?? '';
                    if ($method === 'item/agentMessage/delta') {
                        $itemId = $message['params']['itemId'] ?? null;
                        if ($itemId && $lastAgentItem && $itemId !== $lastAgentItem && $text !== '') {
                            $text .= "\n\n";
                        }
                        $lastAgentItem = $itemId ?? $lastAgentItem;
                        $text .= $message['params']['delta'] ?? '';
                        if (strlen($text) > 1_000_000) {
                            throw new RuntimeException('A resposta ultrapassou o limite de tamanho.');
                        }
                    }
                    if ($method === 'item/tool/call' && array_key_exists('id', $message)) {
                        $params = $message['params'];
                        $arguments = $params['arguments'] ?? [];
                        if (is_string($arguments)) {
                            $arguments = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
                        }
                        try {
                            $toolName = $params['tool'] ?? '';
                            $result = $this->broker->execute($run, $toolName, is_array($arguments) ? $arguments : []);
                            $reply = ['success' => true, 'contentItems' => [['type' => 'inputText', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]];
                            if ($toolName === 'project_image' && isset($result['image_artifact_id'])) {
                                $reply['contentItems'][] = app(ArtifactImages::class)->content($run, $result['image_artifact_id']);
                            }
                        } catch (ValidationException $e) {
                            $reply = ['success' => false, 'contentItems' => [['type' => 'inputText', 'text' => json_encode(['status' => 'invalid_arguments', 'message' => 'Corrija os argumentos da ferramenta conforme os campos abaixo e tente novamente.', 'errors' => $e->errors()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]];
                        } catch (RuntimeException $e) {
                            $reply = ['success' => false, 'contentItems' => [['type' => 'inputText', 'text' => $e->getMessage()]]];
                        }
                        $send(['id' => $message['id'], 'result' => $reply]);
                    } elseif (array_key_exists('id', $message) && $method !== '') {
                        if (in_array($method, ['item/commandExecution/requestApproval', 'item/fileChange/requestApproval'], true)) {
                            Audit::record($run->user_id, 'agent.native_operation_denied', 'Operação nativa recusada; use as ferramentas autorizadas do projeto.', $project->id, ['method' => $method]);
                        }
                        $send(CodexProtocol::restrictedResponse($message));
                    }
                    if ($method === 'turn/completed') {
                        $status = $message['params']['turn']['status'] ?? null;
                        if ($status !== 'completed') {
                            throw new RuntimeException($status === 'interrupted' ? 'Tarefa interrompida.' : 'A OpenAI não concluiu a tarefa. Verifique limites e autorização da conta.');
                        }$finished = true;
                        break 2;
                    }
                    if ($method === 'error') {
                        throw new RuntimeException('O Codex informou uma falha na execução. Verifique sua conexão e o limite do plano.');
                    }
                }
                if ($now - $lastSave > .4) {
                    $assistant->update(['content' => $text]);
                    $this->publish($run);
                    $lastSave = $now;
                }
                usleep(40000);
            }
            if (! $finished) {
                throw new RuntimeException('A conexão com o agente terminou antes de confirmar a conclusão.');
            }
            $assistant->update(['content' => $text ?: 'A tarefa foi concluída. Consulte os artefatos e o histórico do projeto.', 'status' => 'completed']);
        } catch (Throwable $e) {
            $assistant->update(['content' => $text, 'status' => 'failed']);
            throw $e;
        } finally {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(2);
            }unset($token);
        }
    }

    private function publish(AgentRun $run): void
    {
        try {
            event(new WorkspaceChanged($run->user_id, $run->id));
        } catch (Throwable) {
        }
    }
}
