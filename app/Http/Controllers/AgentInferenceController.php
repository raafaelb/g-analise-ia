<?php

namespace App\Http\Controllers;

use App\Services\Agent\AgentInferenceGateway;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class AgentInferenceController extends Controller
{
    public function __invoke(Request $request, AgentInferenceGateway $gateway): Response
    {
        abort_unless($request->isJson(), 415, 'Envie a solicitação de inferência em JSON.');
        $grant = $request->bearerToken();
        abort_unless(is_string($grant) && $grant !== '', 401, 'Autorização temporária obrigatória.');
        $maximum = (int) config('horus.codex.gateway_max_bytes', 50331648);
        abort_unless($maximum >= 1024 && $maximum <= 50331648, 503, 'O limite interno de inferência precisa ser configurado.');
        abort_if((int) $request->header('Content-Length', '0') > $maximum, 413, 'O contexto da tarefa excede o limite de envio.');
        $body = $request->getContent();
        abort_if(strlen($body) > $maximum, 413, 'O contexto da tarefa excede o limite de envio.');
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'A solicitação contém JSON inválido.');
        }
        abort_unless(is_array($payload), 400, 'A solicitação deve ser um objeto JSON.');

        return $gateway->respond($grant, $payload);
    }
}
