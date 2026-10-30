<?php

namespace App\Console\Commands;

use App\Models\Artifact;
use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneLocalData extends Command
{
    protected $signature = 'ganalise:retention {--apply : Aplica a política; sem esta opção, apenas simula}';

    protected $description = 'Retém conversas e anexos; expira somente exportações geradas e auditoria conforme configuração.';

    public function handle(): int
    {
        $exportDays = config('operations.export_retention_days');
        $auditDays = config('operations.audit_retention_days');
        $exports = Artifact::where('kind', 'dashboard_export')->where('created_at', '<', now()->subDays(max(1, $exportDays)));
        $audits = AuditEvent::where('created_at', '<', now()->subDays(max(1, $auditDays)));
        $counts = ['exports' => $exportDays > 0 ? (clone $exports)->count() : 0, 'audit_events' => $auditDays > 0 ? (clone $audits)->count() : 0];
        $removed = 0;
        $errors = 0;
        if ($this->option('apply')) {
            if ($exportDays > 0) {
                $exports->each(function ($artifact) use (&$removed, &$errors) {
                    $prefix = 'exports/'.$artifact->user_id.'/'.$artifact->project_id.'/';
                    $legacy = 'workspaces/'.$artifact->user_id.'/'.$artifact->project_id.'/exports/';
                    if ((! str_starts_with((string) $artifact->path, $prefix) && ! str_starts_with((string) $artifact->path, $legacy)) || str_contains($artifact->path, '..') || is_link(Storage::disk('local')->path($artifact->path))) {
                        $errors++;

                        return;
                    }
                    if (Storage::disk('local')->exists($artifact->path) && ! Storage::disk('local')->delete($artifact->path)) {
                        $errors++;

                        return;
                    }
                    $artifact->delete();
                    $removed++;
                });
            }
            if ($auditDays > 0) {
                $audits->delete();
            }
        }
        $this->line(json_encode(['dry_run' => ! $this->option('apply'), 'eligible' => $counts, 'exports_removed' => $removed, 'errors' => $errors, 'conversations_and_attachments' => 'preserved'], JSON_THROW_ON_ERROR));

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
