<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentInferenceGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('horus.codex.gateway_enabled', false) === true, 404);
        $secret = config('horus.codex.gateway_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 256, 503, 'O acesso interno de inferência precisa ser configurado.');
        $presented = $request->header('X-G-Analise-Inference');
        abort_unless(is_string($presented) && hash_equals($secret, $presented), 404);

        return $next($request);
    }
}
