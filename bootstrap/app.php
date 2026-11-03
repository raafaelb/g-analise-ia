<?php

use App\Http\Middleware\CanonicalLocalOrigin;
use App\Http\Middleware\EnsureUserAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustDeploymentProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', channels: __DIR__.'/../routes/channels.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustDeploymentProxies::class);
        $middleware->web(append: [CanonicalLocalOrigin::class, HandleInertiaRequests::class, SecurityHeaders::class, EnsureUserAccess::class]);
        $middleware->redirectGuestsTo(fn () => url('/login'));
        $mcpQuery = fn (Request $request): bool => $request->is('api/catalog/*/apps/*/mcp');
        $variableWrite = fn (Request $request): bool => $request->is('api/catalog/*/variables/actions');
        $middleware->convertEmptyStringsToNull(except: [$mcpQuery, $variableWrite]);
        $middleware->trimStrings(except: ['values.password', 'values.JsonKey', 'values.odbcconnection', 'columns.*.key', $mcpQuery, $variableWrite]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $status = $response->getStatusCode();
            if ($request->is('api/*') || $request->expectsJson() || ! in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }
            if (config('app.debug') && $status >= 500) {
                return $response;
            }

            Inertia::resolveUrlUsing(app(HandleInertiaRequests::class)->urlResolver());
            $page = Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
            $page->headers->set('Cache-Control', 'no-store, private');
            if ($response->headers->has('Retry-After')) {
                $page->headers->set('Retry-After', $response->headers->get('Retry-After'));
            }

            return $page;
        });
    })->create();
