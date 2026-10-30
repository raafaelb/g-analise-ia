<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class BiResourceValidation
{
    public function __construct(private LumoClient $lumo, private WorkspaceFiles $files) {}

    public function inspect(Connection $connection, Project $project, string $resource): array
    {
        $path = $this->path($project, $resource);
        $result = $this->lumo->run($connection, ['lint', $path], $project, 90, true);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $issues = [];
        foreach ($data['results'] ?? [] as $file) {
            foreach ($file['issues'] ?? [] as $issue) {
                if (! is_array($issue) || ! is_string($issue['message'] ?? null)) {
                    continue;
                }
                $issues[] = ['path' => mb_strcut((string) ($issue['path'] ?? '/'), 0, 500, 'UTF-8'), 'message' => mb_strcut(str_replace($this->files->path($project).'/', '', $issue['message']), 0, 2000, 'UTF-8'), 'severity' => ($issue['severity'] ?? '') === 'warning' ? 'warning' : 'error'];
                if (count($issues) >= 50) {
                    break 2;
                }
            }
        }
        $passed = ($result['exit_code'] ?? 0) === 0 && ($data['errorCount'] ?? 0) === 0 && ($data['valid'] ?? true) !== false;

        return ['passed' => $passed, 'resource' => $resource, 'file' => substr($path, strlen($this->files->path($project)) + 1), 'issues' => $issues, 'error_count' => (int) ($data['errorCount'] ?? ($passed ? 0 : 1)), 'warning_count' => (int) ($data['warningCount'] ?? 0), 'no_remote_write' => true, 'message' => $passed ? 'O recurso selecionado passou na validação local.' : 'Corrija os problemas indicados, leia novamente o arquivo para obter seu hash e valide o mesmo recurso antes de preparar outra revisão.'];
    }

    private function path(Project $project, string $resource): string
    {
        if (! preg_match('/^(app|flow|table|schedule):([1-9][0-9]*)$/D', $resource, $match)) {
            throw new RuntimeException('Informe resource para validar apenas a aplicação, fluxo, tabela ou agenda em edição.');
        }
        $folder = ['app' => 'apps', 'flow' => 'flows', 'table' => 'tables', 'schedule' => 'schedules'][$match[1]];
        $root = $this->files->path($project);
        $matches = [];
        if (is_dir($root.'/'.$folder) && ! is_link($root.'/'.$folder)) {
            foreach (File::files($root.'/'.$folder) as $file) {
                if ($file->isLink() || $file->getSize() > 2_000_000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                    continue;
                }
                try {
                    $header = Yaml::parse(preg_split('/^---\s*$/m', File::get($file->getPathname()), 2)[0]);
                } catch (\Throwable) {
                    continue;
                }
                if (is_array($header) && ($header['kind'] ?? null) === $match[1] && (string) ($header['id'] ?? '') === $match[2] && (string) ($header['tenantId'] ?? '') === (string) $project->tenant_id) {
                    $matches[] = $file->getPathname();
                }
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Não foi encontrado um único YAML local deste recurso e tenant. Confira os arquivos do projeto antes de validar.');
        }

        return $matches[0];
    }
}
