<?php

namespace App\Http\Controllers;

use App\Services\BiMcpClient;
use App\Services\BiMcpIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BiMcpController extends Controller
{
    public function applications(Request $request, string $tenant, BiMcpClient $client, BiMcpIdentity $identity): JsonResponse
    {
        abort_unless($request->user()->canStartWork() && $request->user()->role !== 'viewer', 403);
        abort_unless($identity->configured(), 409, 'As consultas MCP ainda não estão habilitadas nesta instalação.');
        try {
            return response()->json(['applications' => $client->applications($request->user(), $tenant)])->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            throw ValidationException::withMessages(['mcp' => $e->getMessage()]);
        }
    }

    public function authorize(Request $request, string $tenant, BiMcpIdentity $identity): JsonResponse
    {
        abort_unless($request->user()->canStartWork() && $request->user()->role !== 'viewer', 403);
        $data = $request->validate(['app_id' => ['required', 'integer', 'min:1']]);
        try {
            $flow = $identity->begin($request->user(), $tenant, (int) $data['app_id']);
            $previous = $request->session()->get('bi_mcp_oauth_state');
            if (is_string($previous)) {
                $identity->consume($previous);
            }
            $request->session()->put('bi_mcp_oauth_state', $flow['state']);

            return response()->json(['url' => $flow['url']])->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            throw ValidationException::withMessages(['mcp' => $e->getMessage()]);
        }
    }

    public function callback(Request $request, BiMcpIdentity $identity): RedirectResponse
    {
        $expected = $request->session()->pull('bi_mcp_oauth_state');
        $returned = $request->query('state');
        if (! is_string($expected) || ! is_string($returned) || ! hash_equals($expected, $returned)) {
            if (is_string($expected)) {
                $identity->consume($expected);
            }

            return redirect('/app/connections')->with('error', 'A autorização de consultas expirou ou não pôde ser verificada. Tente novamente.');
        }
        $transaction = $identity->consume($returned);
        $code = $request->query('code');
        $issuer = $request->query('iss');
        if (! $transaction || $request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 8000 || ($issuer !== null && ! is_string($issuer))) {
            return redirect('/app/connections')->with('error', 'A autorização MCP não foi concluída. Você pode tentar novamente quando quiser.');
        }
        try {
            $identity->complete($request->user(), $transaction, $code, $issuer);

            return redirect('/app/connections')->with('success', 'Consultas de dados autorizadas e ambiente conferido.');
        } catch (RuntimeException $e) {
            return redirect('/app/connections')->with('error', $e->getMessage());
        } catch (Throwable) {
            return redirect('/app/connections')->with('error', 'Não foi possível concluir a conexão MCP. Confira sua conta e tente novamente.');
        }
    }

    public function verify(Request $request, string $tenant, BiMcpClient $client): JsonResponse
    {
        abort_unless($request->user()->canStartWork() && $request->user()->role !== 'viewer', 403);
        try {
            return response()->json($client->check($request->user(), $tenant))->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            throw ValidationException::withMessages(['mcp' => $e->getMessage()]);
        }
    }

    public function disconnect(Request $request, string $tenant, BiMcpIdentity $identity): JsonResponse
    {
        $confirmed = $identity->disconnect($request->user(), $tenant);

        $message = match ($confirmed) {
            true => 'Consultas desconectadas neste portal. O BI recebeu a solicitação de revogação.',
            false => 'Consultas desconectadas neste portal. O BI não confirmou o recebimento da revogação; confira suas autorizações na página MCP do BI.',
            null => 'Consultas já estão desconectadas neste portal.',
        };

        return response()->json(['disconnected' => true, 'remote_revocation_acknowledged' => $confirmed, 'message' => $message])->header('Cache-Control', 'no-store, private');
    }

    public function query(Request $request, string $tenant, string $id, BiMcpClient $client): JsonResponse
    {
        abort_unless($request->user()->canStartWork() && $request->user()->role !== 'viewer', 403);
        $data = $request->validate(['tool' => ['required', Rule::in(BiMcpClient::TOOLS)], 'arguments' => ['present', 'array']]);
        try {
            return response()->json($client->invoke($request->user(), $tenant, (int) $id, $data['tool'], $data['arguments']))->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            throw ValidationException::withMessages(['mcp' => $e->getMessage()]);
        }
    }
}
