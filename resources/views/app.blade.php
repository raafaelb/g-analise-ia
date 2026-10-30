<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-path" content="{{ rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?: '', '/') }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0861ee">
<meta name="application-name" content="G-Analise IA">
<link rel="icon" type="image/png" href="{{ asset('brand/g-analise-icon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('brand/g-analise-icon.png') }}">
@vite('resources/js/app.ts')
@inertiaHead
</head><body>@inertia</body></html>
