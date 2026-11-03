<?php

return [
    'preview' => (bool) env('LOCAL_PREVIEW', false),
    'read_only_tenants' => array_values(array_filter(array_map('trim', explode(',', (string) env('BI_READ_ONLY_TENANT_IDS', ''))))),
    'registration' => (bool) env('ALLOW_REGISTRATION', false),
    'downloads' => ['root' => storage_path('app/private/bi-downloads'), 'max_bytes' => (int) env('BI_EXPORT_MAX_BYTES', 50_000_000)],
    'workspace_root' => storage_path('app/private/workspaces'),
    'openai' => [
        'enabled' => (bool) env('OPENAI_SIGN_IN_ENABLED', false),
        'mode' => env('OPENAI_AUTH_MODE', 'hosted'),
        'local_redirect_uri' => env('OPENAI_LOCAL_REDIRECT_URI', 'http://127.0.0.1:8088/auth/openai/callback'),
        'client_id' => env('OPENAI_CLIENT_ID'), 'client_secret' => env('OPENAI_CLIENT_SECRET'),
        'client_auth' => env('OPENAI_CLIENT_AUTH', 'none'),
        'issuer' => 'https://auth.openai.com',
        'redirect_uri' => env('OPENAI_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8088'), '/').'/auth/openai/callback'),
        'scopes' => env('OPENAI_SCOPES', 'openid profile email'),
        'inference_scope' => env('OPENAI_INFERENCE_SCOPE'),
        'plan_enabled' => (bool) env('OPENAI_PLAN_USAGE_ENABLED', false),
    ],
    'lumo' => ['binary' => env('LUMO_BINARY', '/usr/local/bin/lumo'), 'server' => env('LUMO_API_URL', 'https://api.horusbi.com.br/cli/v2'), 'web_url' => env('LUMO_WEB_URL', 'https://dataviz.horusbi.com.br'), 'login_enabled' => (bool) env('LUMO_LOGIN_ENABLED', false)],
    'mcp' => ['enabled' => (bool) env('BI_MCP_ENABLED', false), 'resource' => env('BI_MCP_RESOURCE', rtrim(env('LUMO_WEB_URL', 'https://dataviz.horusbi.com.br'), '/').'/mcp'), 'redirect_uri' => env('BI_MCP_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8088'), '/').'/auth/bi-mcp/callback'), 'client_id' => env('BI_MCP_CLIENT_ID')],
    'viewer' => ['enabled' => (bool) env('BI_VIEWER_ENABLED', false), 'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('BI_VIEWER_ALLOWED_ORIGINS', env('LUMO_WEB_URL', 'https://dataviz.horusbi.com.br'))))))],
    'codex' => [
        'binary' => env('CODEX_BINARY', '/usr/local/bin/codex'),
        'model' => env('CODEX_DEFAULT_MODEL'),
        'timeout' => (int) env('CODEX_TURN_TIMEOUT', 900),
        'gateway_enabled' => (bool) env('AGENT_INFERENCE_ENABLED', false),
        'gateway_secret' => env('AGENT_INFERENCE_SECRET'),
        'gateway_max_requests' => (int) env('CODEX_GATEWAY_MAX_REQUESTS', 64),
        'gateway_max_bytes' => (int) env('CODEX_GATEWAY_MAX_BYTES', 50331648),
    ],
];
