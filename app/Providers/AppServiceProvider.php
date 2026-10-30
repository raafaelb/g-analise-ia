<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['workspace' => 600, 'login' => 10, 'registration' => 5, 'openai-login' => 10, 'bi-images' => 20, 'coverage' => 10, 'agent-messages' => 15, 'openai-verify' => 3, 'bi-login' => 5, 'attachments' => 10, 'catalog' => 20] as $name => $count) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($count)->by((string) ($request->user()?->id ?? $request->ip())));
        }
        if (trim((string) parse_url(config('app.url'), PHP_URL_PATH), '/') !== '') {
            URL::forceRootUrl(config('app.url'));
        }
        if (app()->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        //
    }
}
