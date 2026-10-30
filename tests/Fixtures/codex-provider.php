<?php

/** A loopback-only synthetic provider. It never forwards a request or uses real credentials. */
function boundaryToolNames(array $tools): array
{
    $names = [];
    foreach ($tools as $tool) {
        if (is_string($tool['name'] ?? null)) {
            $names[] = $tool['name'];
        }
        preg_match_all('/declare const tools: \{\s*([A-Za-z0-9_]+)/', $tool['description'] ?? '', $matches);
        $names = [...$names, ...$matches[1], ...boundaryToolNames($tool['tools'] ?? [])];
    }

    return array_values(array_unique($names));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['REQUEST_URI'] ?? '') !== '/v1/responses') {
    http_response_code(404);

    return;
}
$body = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$output = getenv('BOUNDARY_OUTPUT');
if (is_file($output.'.catalog')) {
    $rejected = false;
    foreach ($body['input'] ?? [] as $item) {
        if (in_array($item['type'] ?? '', ['custom_tool_call_output', 'function_call_output'], true)) {
            $rejected = $rejected || str_contains(json_encode($item['output'] ?? ''), 'patch rejected by user');
        }
    }
    file_put_contents($output, json_encode(['native_patch_rejected' => $rejected]));
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":{"message":"Synthetic provider finished; no inference performed."}}';

    return;
}
$names = boundaryToolNames($body['tools'] ?? []);
foreach ($body['input'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'additional_tools') {
        $names = [...$names, ...boundaryToolNames($item['tools'] ?? [])];
    }
}
file_put_contents($output.'.catalog', json_encode(array_values(array_unique($names))));
$serialized = json_encode($body, JSON_THROW_ON_ERROR);
$guidance = [];
foreach (json_decode(getenv('BOUNDARY_MARKERS') ?: '[]', true, flags: JSON_THROW_ON_ERROR) as $marker) {
    $guidance[$marker] = str_contains($serialized, $marker);
}
file_put_contents($output.'.guidance', json_encode($guidance));
$patch = "*** Begin Patch\n*** Add File: ".getenv('BOUNDARY_CANARY')."\n+synthetic-canary-only\n*** End Patch";
$call = ['type' => 'custom_tool_call', 'id' => 'ctc_boundary', 'call_id' => 'call_boundary', 'name' => 'exec', 'namespace' => 'functions', 'input' => 'text(await tools.apply_patch('.json_encode($patch).'));'];
header('Content-Type: text/event-stream');
foreach ([
    ['type' => 'response.created', 'response' => ['id' => 'resp_boundary', 'status' => 'in_progress']],
    ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => [...$call, 'input' => '']],
    ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $call],
    ['type' => 'response.completed', 'response' => ['id' => 'resp_boundary', 'status' => 'completed', 'output' => [$call], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]],
] as $event) {
    echo 'event: '.$event['type']."\n", 'data: '.json_encode($event)."\n\n";
}
