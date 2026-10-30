<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\User;
use App\Services\Audit;
use App\Services\BiCredentials;
use App\Services\ChatGptUsage;
use App\Services\ChatGptVerifier;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConnectionController extends Controller
{
    public function redirect(Request $request, OpenAiIdentity $identity): RedirectResponse
    {
        try {
            $flow = $identity->begin($request->user()?->id);
            $request->session()->put('openai_oauth_state', $flow['state']);

            return redirect()->away($flow['url']);
        } catch (Throwable) {
            return redirect($request->user() ? '/app/connections' : '/login')->with('error', 'A integração oficial ChatGPT ainda não está configurada ou está indisponível.');
        }
    }

    public function callback(Request $request, OpenAiIdentity $identity): RedirectResponse
    {
        $expected = $request->session()->pull('openai_oauth_state');
        $returned = $request->query('state');
        if (! is_string($expected) || ! is_string($returned) || ! hash_equals($expected, $returned)) {
            if (is_string($expected)) {
                $identity->consume($expected);
            }

            return redirect('/login')->with('error', 'A autorização expirou ou não pôde ser verificada. Tente conectar novamente.');
        }
        $transaction = $identity->consume($returned);
        if (! $transaction || $request->has('error') || ! $request->filled('code') || ($transaction['user_id'] && $request->user()?->id !== $transaction['user_id'])) {
            return redirect('/login')->with('error', 'Não foi possível concluir a autorização.');
        }
        try {
            $result = $identity->exchange($request->string('code')->toString(), $transaction, $request->query('client_id'));
            $claims = $result['identity'];
            $tokens = $result['tokens'];
            $subject = hash('sha256', $claims['iss'].'|'.$result['client_id'].'|'.$claims['sub']);
            $linked = Connection::where('provider', 'openai')->where('subject', $subject)->first();
            $user = $transaction['user_id'] ? User::findOrFail($transaction['user_id']) : ($linked ? User::find($linked->user_id) : null);
            if (! $user || ! $user->canStartWork()) {
                throw new \RuntimeException('Entre em uma conta ativa e finalize sua senha pessoal antes de vincular o ChatGPT.');
            }
            if (! $transaction['user_id'] && $user->suite_subject !== null) {
                return redirect()->route('login')->with('error', 'Entre com a Suíte para acessar sua conta de colaborador. Depois, conecte o ChatGPT dentro do seu workspace.');
            }
            if ($linked && $linked->user_id !== $user->id) {
                throw new \RuntimeException('Esta identidade já está vinculada a outra conta.');
            }
            if ($result['mode'] !== 'oss_local') {
                unset($tokens['id_token']);
            }
            Connection::updateOrCreate(['user_id' => $user->id, 'provider' => 'openai'], ['subject' => $subject, 'status' => 'connected', 'account_label' => $claims['email'] ?? $claims['name'] ?? 'Conta ChatGPT', 'credentials' => $tokens, 'scopes' => preg_split('/\s+/', trim($tokens['scope'] ?? '')), 'metadata' => ['issuer' => $claims['iss'], 'client_id' => $result['client_id'], 'auth_mode' => $result['mode'], 'plan_usage' => $identity->planAuthorized($tokens)], 'expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null]);
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('user_access_version', $user->access_version);
            Audit::record($user->id, 'connection.openai.connected', 'Conta ChatGPT conectada.');

            return redirect('/app/connections')->with('success', 'Sua conta ChatGPT foi conectada.');
        } catch (Throwable) {
            return redirect($request->user() ? '/app/connections' : '/login')->with('error', 'Não foi possível verificar ou vincular a conta ChatGPT. Entre na conta local e tente novamente.');
        }
    }

    public function models(Request $request, OpenAiIdentity $identity): JsonResponse
    {
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', 'openai')->first();
        abort_unless($connection, 422, 'Conecte sua conta ChatGPT.');
        try {
            $token = $identity->accessToken($connection);
            $response = Http::withoutRedirecting()->withToken($token)->timeout(20)->get('https://api.openai.com/v1/models');
            if (! $response->successful()) {
                throw new \RuntimeException('Não foi possível consultar os modelos autorizados na OpenAI.');
            }$models = $response->json('models') ?? $response->json('data') ?? [];

            return response()->json(['models' => collect($models)->filter(fn ($m) => ($m['visibility'] ?? 'list') === 'list')->map(fn ($m) => ['slug' => $m['slug'] ?? $m['id'], 'display_name' => $m['display_name'] ?? $m['id'] ?? $m['slug']])->values()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }
    }

    public function usage(Request $request, ChatGptUsage $usage): JsonResponse
    {
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', 'openai')->where('status', 'connected')->first();
        abort_unless($connection, 422, 'Conecte sua conta ChatGPT para consultar o plano.');
        try {
            return response()->json($usage->read($connection));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }
    }

    public function verifyOpenAi(Request $request, ChatGptVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['model' => ['nullable', 'string', 'max:100']]);
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', 'openai')->where('status', 'connected')->firstOrFail();
        try {
            $result = $verifier->verify($connection, $data['model'] ?? null);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['openai' => $e->getMessage()]);
        }
        Audit::record($request->user()->id, 'connection.openai.verified', 'Teste real de inferência ChatGPT concluído.', null, ['model' => $result['model']]);

        return response()->json($result);
    }

    public function horusLogin(Request $request, LumoClient $lumo): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:500'], 'otp' => ['nullable', 'string', 'max:20']]);
        try {
            $connection = $lumo->login($request->user()->id, $data['email'], $data['password'], $data['otp'] ?? null);
            Audit::record($request->user()->id, 'connection.horus.connected', 'Conta G-Analise conectada.');

            return response()->json($connection);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }
    }

    public function refreshHorus(Request $request, LumoClient $lumo): JsonResponse
    {
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', 'horus')->firstOrFail();
        try {
            $lumo->refreshTenants($connection);

            return response()->json($connection->refresh());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }
    }

    public function disconnect(Request $request, string $provider, OpenAiIdentity $identity): JsonResponse
    {
        $connection = Connection::where('user_id', $request->user()->id)->where('provider', $provider)->firstOrFail();
        $revoked = true;
        if ($provider === 'openai') {
            try {
                $revoked = $identity->revoke($connection);
            } catch (Throwable) {
                $revoked = false;
            }
        }
        DB::transaction(function () use ($request, $connection, $provider) {
            // A paused review has no live worker to acknowledge cancel_requested.
            // Cancel it immediately so reconnecting can start a fresh task.
            $reviews = Approval::where('user_id', $request->user()->id)->whereIn('status', ['pending', 'approved']);
            if ($provider === 'openai') {
                $reviews->whereNotNull('agent_run_id');
            }
            $reviews->update(['status' => 'cancelled', 'decided_at' => now()]);
            AgentRun::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'awaiting_approval'])->update(['status' => 'cancelled', 'finished_at' => now()]);
            AgentRun::where('user_id', $request->user()->id)->where('status', 'running')->update(['status' => 'cancel_requested']);
            $connection->update(['status' => 'disconnected', 'credentials' => null, 'scopes' => [], 'metadata' => $provider === 'openai' ? array_merge(array_intersect_key($connection->metadata ?? [], array_flip(['issuer', 'client_id', 'auth_mode'])), ['plan_usage' => false]) : [], 'expires_at' => null]);
        });
        if ($provider === 'horus') {
            foreach (Approval::where('user_id', $request->user()->id)->where('type', 'manual')->where('status', 'cancelled')->whereNull('project_id')->cursor() as $approval) {
                if (in_array($approval->payload['action']['action'] ?? '', BiCredentials::ACTIONS, true)) {
                    app(BiCredentials::class)->cleanup($approval);
                }
            }
        }
        Audit::record($request->user()->id, 'connection.'.$provider.'.disconnected', 'Conta desconectada.', null, ['remote_revocation_confirmed' => $revoked]);

        return response()->json(['message' => $revoked ? 'Conta desconectada.' : 'Conta desconectada localmente. Confirme a revogação nas configurações do ChatGPT.', 'remote_revocation_confirmed' => $revoked]);
    }
}
