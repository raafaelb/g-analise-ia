<?php

use App\Http\Middleware\CanonicalLocalOrigin;
use App\Http\Middleware\EnsureUserAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', channels: __DIR__.'/../routes/channels.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [CanonicalLocalOrigin::class, HandleInertiaRequests::class, SecurityHeaders::class, EnsureUserAccess::class]);
        $middleware->redirectGuestsTo(fn () => url('/login'));
        $middleware->trimStrings(except: ['values.password', 'values.JsonKey', 'values.odbcconnection']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
