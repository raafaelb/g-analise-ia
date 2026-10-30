<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalLocalOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') || config('horus.openai.mode') !== 'oss_local' || ! $request->isMethod('GET') || ! $request->routeIs('home', 'login', 'workspace', 'openai.redirect')) {
            return $next($request);
        }
        $loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
        $callback = parse_url((string) config('horus.openai.local_redirect_uri'));
        if (! is_array($callback) || ! in_array($callback['host'] ?? '', $loopback, true) || ! in_array($request->getHost(), $loopback, true) || ! in_array($callback['scheme'] ?? '', ['http', 'https'], true) || isset($callback['user']) || isset($callback['pass']) || isset($callback['query']) || isset($callback['fragment'])) {
            return $next($request);
        }
        $port = $callback['port'] ?? ($callback['scheme'] === 'https' ? 443 : 80);
        $host = $callback['host'];
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }
        $origin = $callback['scheme'].'://'.$host.(($callback['scheme'] === 'https' && $port === 443) || ($callback['scheme'] === 'http' && $port === 80) ? '' : ':'.$port);
        if ($request->getSchemeAndHttpHost() === $origin) {
            return $next($request);
        }
        $basePath = preg_replace('#/auth/openai/callback$#', '', $callback['path'] ?? '');
        $target = $request->routeIs('openai.redirect') ? $basePath.'/app/connections' : $request->getRequestUri();

        return redirect()->away($origin.$target, 302);
    }
}
