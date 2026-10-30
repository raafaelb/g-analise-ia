<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (! $user->active || (int) $request->session()->get('user_access_version', 0) !== $user->access_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sua sessão foi encerrada. Entre novamente ou procure a Inovadora.'], 401);
            }

            return redirect()->route('login')->with('error', 'Sua sessão foi encerrada. Entre novamente ou procure a Inovadora.');
        }
        if ($user?->must_change_password && ! $request->routeIs('account.password', 'logout') && ! ($request->routeIs('workspace') && $request->route('section') === 'account')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Defina sua senha pessoal para continuar.', 'requires_password_change' => true], 423);
            }

            return redirect()->route('workspace', ['section' => 'account']);
        }

        return $next($request);
    }
}
