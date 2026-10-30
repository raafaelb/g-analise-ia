<?php

return [
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),
    'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 30),
    'minimum_free_bytes' => (int) env('MINIMUM_FREE_BYTES', 104857600),
];
