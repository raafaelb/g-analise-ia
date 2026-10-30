<?php

namespace App\Http\Middleware;

use App\Services\BiViewer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $frames = implode(' ', app(BiViewer::class)->allowedOrigins());
        $response->headers->set('Content-Security-Policy', "frame-src 'self' blob: ".$frames."; frame-ancestors 'self'");
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
