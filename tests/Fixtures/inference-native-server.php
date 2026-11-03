<?php

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentInferenceGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$root = getenv('GANALISE_NATIVE_TEST_ROOT');
$externalProxy = getenv('GANALISE_NATIVE_TEST_PROXY') === 'external';
$actorCount = getenv('GANALISE_NATIVE_TEST_ACTORS') === '2' ? 2 : 1;
$endpoint = $externalProxy ? '/internal/inference/v1/responses' : '/v1/responses';
if (getenv('APP_ENV') !== 'testing' || ! is_string($root) || ! preg_match('#^/tmp/ganalise-inference-native-[a-f0-9]{16}$#D', $root) || ! is_dir($root)) {
    http_response_code(403);
    exit(1);
}
if (PHP_SAPI !== 'cli' && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['REQUEST_URI'] ?? '') !== $endpoint)) {
    http_response_code(404);
    exit;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($root.'/storage');
foreach (['framework/cache/data', 'framework/cache/locks', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    $path = $root.'/storage/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true)) {
        throw new RuntimeException('The synthetic storage could not be initialized.');
    }
}
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/database.sqlite',
    'cache.default' => 'file',
    'horus.codex.gateway_enabled' => true, 'horus.codex.gateway_secret' => 'SYNTHETIC-INTERNAL-GATEWAY-SECRET-DO-NOT-USE-OUTSIDE-TESTS',
]);
if (PHP_SAPI === 'cli') {
    if (is_file($root.'/database.sqlite')) {
        throw new RuntimeException('A synthetic context already exists.');
    }
    File::put($root.'/database.sqlite', '');
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $actors = [];
    for ($index = 0; $index < $actorCount; $index++) {
        $label = $index === 0 ? 'A' : 'B';
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Native synthetic transport '.$label, 'tenant_id' => (string) (100 + $index)]);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'model' => 'gpt-synthetic']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running', 'started_at' => now()]);
        $oauth = 'SYNTHETIC-BACKEND-OAUTH-NEVER-PASSED-TO-CODEX'.($actorCount === 2 ? '-'.$label : '');
        Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'subject' => (string) Str::uuid(), 'status' => 'connected', 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => $oauth], 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid(), 'auth_mode' => 'hosted']]);
        $grant = app(AgentInferenceGrants::class)->issue($run, 'gpt-synthetic');
        $actors[] = ['label' => $label, 'authorization' => $grant->authorization()];
    }
    echo json_encode($actorCount === 1 ? ['authorization' => $actors[0]['authorization']] : ['actors' => $actors], JSON_THROW_ON_ERROR);
    exit;
}
$proxyHeaderAbsent = ! isset($_SERVER['HTTP_X_G_ANALISE_INFERENCE']);
$proxyHeaderValid = ($_SERVER['HTTP_X_G_ANALISE_INFERENCE'] ?? '') === config('horus.codex.gateway_secret');
$temporaryBearer = str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer gaigr_');
if (! $externalProxy) {
    $_SERVER['HTTP_X_G_ANALISE_INFERENCE'] = config('horus.codex.gateway_secret');
}
$_SERVER['REQUEST_URI'] = '/internal/inference/v1/responses';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = base_path('public/index.php');
$_SERVER['PHP_SELF'] = '/index.php';
Http::preventStrayRequests();
Http::fake(['https://api.openai.com/v1/responses' => function (ClientRequest $request) use ($root, $proxyHeaderAbsent, $proxyHeaderValid, $temporaryBearer, $externalProxy, $actorCount) {
    $payload = $request->data();
    $label = '';
    if ($actorCount === 2) {
        foreach (['A', 'B'] as $candidate) {
            if ($request->hasHeader('Authorization', 'Bearer SYNTHETIC-BACKEND-OAUTH-NEVER-PASSED-TO-CODEX-'.$candidate)) {
                $label = $candidate;
            }
        }
    }
    $oauth = 'SYNTHETIC-BACKEND-OAUTH-NEVER-PASSED-TO-CODEX'.($label !== '' ? '-'.$label : '');
    $path = $root.'/receipt'.($label !== '' ? '-'.$label : '').'.json';
    $previous = is_file($path) ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) : [];
    $receipt = [
        'requests' => ($previous['requests'] ?? 0) + 1,
        'temporary_bearer_at_gateway' => $temporaryBearer,
        'proxy_secret_absent_from_native_request' => $proxyHeaderAbsent,
        'backend_oauth_at_provider' => $request->hasHeader('Authorization', 'Bearer '.$oauth),
        'proxy_secret_absent_from_provider_request' => ! $request->hasHeader('X-G-Analise-Inference'),
        'prompt_received' => str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'SYNTHETIC_NATIVE_GATEWAY_QUESTION'.($label !== '' ? '_'.$label : '')),
        'stream' => $payload['stream'] ?? null, 'store' => $payload['store'] ?? null,
    ];
    if ($externalProxy) {
        $receipt['proxy_added_valid_secret'] = $proxyHeaderValid;
        $receipt['actor'] = $label;
    }
    File::put($path, json_encode($receipt, JSON_THROW_ON_ERROR));
    $text = 'SYNTHETIC_NATIVE_GATEWAY_COMPLETED'.($label !== '' ? '_'.$label : '');
    $part = ['type' => 'output_text', 'text' => $text, 'annotations' => []];
    $item = ['id' => 'msg_synthetic_gateway', 'type' => 'message', 'role' => 'assistant', 'content' => [$part], 'status' => 'completed'];
    $events = [
        ['type' => 'response.created', 'response' => ['id' => 'resp_synthetic_gateway', 'status' => 'in_progress']],
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => [...$item, 'content' => [], 'status' => 'in_progress']],
        ['type' => 'response.content_part.added', 'item_id' => $item['id'], 'output_index' => 0, 'content_index' => 0, 'part' => [...$part, 'text' => '']],
        ['type' => 'response.output_text.delta', 'item_id' => $item['id'], 'output_index' => 0, 'content_index' => 0, 'delta' => $text],
        ['type' => 'response.output_text.done', 'item_id' => $item['id'], 'output_index' => 0, 'content_index' => 0, 'text' => $text],
        ['type' => 'response.content_part.done', 'item_id' => $item['id'], 'output_index' => 0, 'content_index' => 0, 'part' => $part],
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $item],
        ['type' => 'response.completed', 'response' => ['id' => 'resp_synthetic_gateway', 'status' => 'completed', 'output' => [$item], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]],
    ];
    $body = '';
    foreach ($events as $event) {
        $body .= 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
    }

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}]);
$app->handleRequest(Request::capture());
