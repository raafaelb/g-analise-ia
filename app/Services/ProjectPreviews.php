<?php

namespace App\Services;

use App\Events\WorkspaceChanged;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProjectPreviews
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function context(User $user, Project $project): array
    {
        abort_unless($project->user_id === $user->id, 404);
        abort_unless(preg_match('/^app:([1-9][0-9]*)$/D', (string) $project->source_ref, $match), 422, 'Vincule uma aplicação ao projeto para gerar sua prévia.');
        $detail = $this->catalog->detail($user, (string) $project->tenant_id, 'app', $match[1], includeRelations: false);

        return ['resource' => $project->source_ref, 'name' => $detail['resource']['name'], 'dashboards' => $detail['resource']['dashboards'] ?? [], 'dashboards_available' => is_array($detail['resource']['dashboards'] ?? null)];
    }

    public function render(User $user, Project $project, ?int $dashboard = null): Artifact
    {
        $preview = $this->context($user, $project);
        $tenant = (string) $project->tenant_id;
        $appId = substr($preview['resource'], 4);
        if ($dashboard !== null) {
            abort_unless(collect($preview['dashboards'])->contains(fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $dashboard), 422, 'O dashboard selecionado não pertence à aplicação do projeto.');
        }
        [$connection, $item] = $this->catalog->access($user, $tenant);
        $context = $this->catalog->context($connection, $item);
        $directory = app(WorkspaceFiles::class)->path($context).'/exports/project-previews';
        File::ensureDirectoryExists($directory, 0700, true);
        $temporary = $directory.'/'.Str::uuid().'.png';
        $stored = 'exports/'.$user->id.'/'.$project->id.'/'.Str::uuid().'.png';
        $artifact = null;
        try {
            $this->lumo->run($connection, ['app', 'export', 'app:'.$appId, '--image-format', 'png', '--output', $temporary, '--timeout', '90s', ...($dashboard !== null ? ['--dashboard', (string) $dashboard] : [])], $context, 100);
            $image = is_file($temporary) ? @getimagesize($temporary) : false;
            if (! $image || $image['mime'] !== 'image/png' || filesize($temporary) > 20000000) {
                throw new RuntimeException('O BI não retornou uma imagem PNG válida de até 20 MB.');
            }
            $contents = File::get($temporary);
            if (! Storage::disk('local')->put($stored, $contents)) {
                throw new RuntimeException('Não foi possível salvar a prévia do projeto.');
            }
            $artifact = DB::transaction(function () use ($user, $project, $dashboard, $appId, $stored, $image, $contents): Artifact {
                $artifact = Artifact::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => $project->name.' - prévia - '.now()->format('Y-m-d_H-i-s').'.png', 'kind' => 'dashboard_export', 'mime' => 'image/png', 'path' => $stored, 'metadata' => ['resource' => 'app:'.$appId, 'dashboard_id' => $dashboard, 'width' => $image[0], 'height' => $image[1], 'generated_at' => now()->toIso8601String(), 'sha256' => hash('sha256', $contents), 'origin' => 'project_preview']]);
                Audit::record($user->id, 'preview.generated', 'Prévia real do projeto gerada no BI.', $project->id, ['tenant_id' => $project->tenant_id, 'resource' => 'app:'.$appId, 'artifact_id' => $artifact->id, 'dashboard_id' => $dashboard]);

                return $artifact;
            });
        } catch (Throwable $e) {
            if ($artifact === null) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        } finally {
            File::delete($temporary);
        }
        try {
            event(new WorkspaceChanged($user->id));
        } catch (Throwable) {
        }

        return $artifact;
    }
}
