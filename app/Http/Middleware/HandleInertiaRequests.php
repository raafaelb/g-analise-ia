<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function urlResolver(): \Closure
    {
        return fn (Request $request) => rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?: '', '/').$request->getRequestUri();
    }

    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role')], 'flash' => ['success' => fn () => $request->session()->get('success'), 'error' => fn () => $request->session()->get('error')]];
    }
}
