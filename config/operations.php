<?php

return [
    'public_host' => env('PUBLIC_HOST', ''),
    'public_path' => env('PUBLIC_PATH', ''),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),
    'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 30),
    'minimum_free_bytes' => (int) env('MINIMUM_FREE_BYTES', 104857600),
];
