<?php

namespace App\Http\Controllers;

use App\Jobs\AnswerHelpQuestion;
use App\Models\Connection;
use App\Models\HelpQuestion;
use App\Models\User;
use App\Services\OpenAiIdentity;
use App\Services\ProductGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HelpQuestionController extends Controller
{
    public function index(Request $request, OpenAiIdentity $identity): JsonResponse
    {
        $user = $request->user();
        HelpQuestion::where('user_id', $user->id)->interrupted()->update(['status' => 'failed', 'error_message' => 'O atendimento foi interrompido. Envie uma nova pergunta para continuar.', 'finished_at' => now()]);
        $questions = HelpQuestion::where('user_id', $user->id)->latest()->orderByDesc('id')->limit(30)->get()->reverse()->values()->map($this->question(...));
        $connection = Connection::where('user_id', $user->id)->where('provider', 'openai')->where('status', 'connected')->first();

        return $this->respond(['questions' => $questions, 'connected' => $identity->configured() && $connection && ($connection->metadata['plan_usage'] ?? false)]);
    }

    public function store(Request $request, ProductGuide $guide, OpenAiIdentity $identity): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['question', 'request_id', 'page']) !== []) {
            throw ValidationException::withMessages(['question' => 'Envie somente sua pergunta e o contexto da página.']);
        }
        $data = $request->validate(['question' => ['required', 'string', 'max:4000'], 'request_id' => ['required', 'uuid'], 'page' => ['required', 'string', 'max:40']], ['question.required' => 'Escreva sua dúvida sobre o G-Analise IA.', 'question.max' => 'Use até 4.000 caracteres na pergunta.']);
        $user = $request->user();
        $page = $guide->page($user, $data['page']);
        $hash = hash('sha256', json_encode([$data['page'], $data['question']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($user, $data, $hash, $page, $identity): array {
            $this->lockedUser($user);
            $existing = HelpQuestion::where('user_id', $user->id)->where('request_id', strtolower($data['request_id']))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Esta solicitação já foi usada com outra pergunta. Envie uma nova solicitação.');

                return ['question' => $this->question($existing), 'reused' => true];
            }
            if (! $identity->configured()) {
                throw ValidationException::withMessages(['question' => 'O ChatGPT ainda não está habilitado nesta instalação. Os guias e tours continuam disponíveis.']);
            }
            $connection = Connection::where('user_id', $user->id)->where('provider', 'openai')->where('status', 'connected')->lockForUpdate()->first();
            if (! $connection || ! ($connection->metadata['plan_usage'] ?? false)) {
                throw ValidationException::withMessages(['question' => 'Conecte e autorize sua conta ChatGPT em Conexões. Os guias continuam disponíveis sem IA.']);
            }
            abort_if(HelpQuestion::where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'Aguarde a resposta atual ou interrompa o atendimento antes de enviar outra pergunta.');
            $generation = $connection->metadata['session_generation'] ?? null;
            if (! is_string($generation) || ! Str::isUuid($generation)) {
                $generation = (string) Str::uuid();
                $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => $generation]]);
            }
            $question = HelpQuestion::create(['user_id' => $user->id, 'connection_id' => $connection->id, 'request_id' => strtolower($data['request_id']), 'request_hash' => $hash, 'access_version' => $user->access_version, 'connection_generation' => $generation, 'page_key' => $data['page'], 'guide_version' => $page['version'], 'question' => $data['question']]);
            AnswerHelpQuestion::dispatch($question->id)->onQueue('help')->afterCommit();

            return ['question' => $this->question($question), 'reused' => false];
        });

        return $this->respond($result, $result['reused'] ? 200 : 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id): array {
            $this->lockedUser($request->user());
            $question = HelpQuestion::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            if (in_array($question->status, ['queued', 'running'], true)) {
                $question->update(['status' => 'cancelled', 'finished_at' => now()]);
            }

            return $this->question($question);
        });

        return $this->respond(['question' => $result]);
    }

    public function clear(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $this->lockedUser($request->user());
            HelpQuestion::where('user_id', $request->user()->id)->delete();
        });

        return $this->respond(['cleared' => true]);
    }

    private function lockedUser(User $user): void
    {
        $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->canStartWork() && $current->access_version === $user->access_version, 403, 'Seu acesso mudou. Entre novamente para continuar.');
    }

    private function question(HelpQuestion $question): array
    {
        return $question->only(['id', 'page_key', 'guide_version', 'question', 'answer', 'status', 'model', 'error_message', 'created_at', 'started_at', 'finished_at']);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
