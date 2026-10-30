<?php

namespace App\Services;

use App\Models\Connection;
use RuntimeException;

class TenantPolicy
{
    public function isReadOnly(string $tenantId, ?Connection $connection = null): bool
    {
        if (in_array($tenantId, array_map('strval', config('horus.read_only_tenants', [])), true)) {
            return true;
        }

        return false;
    }

    public function assertCommandAllowed(string $tenantId, array $arguments, ?Connection $connection = null): void
    {
        if (! $this->isReadOnly($tenantId, $connection)) {
            return;
        }
        $command = $arguments[0] ?? '';
        $subcommand = $arguments[1] ?? '';
        $readOnly = in_array($command, ['init', 'list', 'info', 'columns', 'lint', 'diff', 'status', 'scaffold', 'fetch', 'pull', 'log'], true)
            || ($command === 'app' && in_array($subcommand, ['dashboard-preview', 'export'], true))
            || ($command === 'table' && $subcommand === 'preview')
            || ($command === 'flow' && in_array($subcommand, ['status', 'logs'], true))
            || ($command === 'schedule' && $subcommand === 'logs')
            || ($command === 'agent' && in_array($subcommand, ['health', 'events'], true));
        if (! $readOnly) {
            throw new RuntimeException('Este tenant está protegido para somente leitura. Alterações, publicações e execuções estão bloqueadas.');
        }
    }
}
