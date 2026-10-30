<?php

namespace App\Services;

use App\Models\AuditEvent;

class Audit
{
    public static function record(int $userId, string $action, string $summary, ?string $resource = null, array $metadata = []): void
    {
        AuditEvent::create(['user_id' => $userId, 'action' => $action, 'summary' => $summary, 'resource_id' => $resource, 'metadata' => $metadata]);
    }
}
