<?php

namespace App\Services\Agent;

use App\Models\Project;
use App\Services\WorkspaceFiles;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SafeWorkspace
{
    public function resolve(Project $project, string $relative): string
    {
        if (strlen($relative) > 250 || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Caminho de arquivo inválido.');
        }
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
                throw new RuntimeException('O acesso a arquivos internos não é permitido.');
            }
        }
        if (! in_array($relative, ['AGENTS.md', 'PROJECT.md', 'BI_GUIDE.md'], true) && ! in_array($parts[0], ['apps', 'flows', 'tables', 'schedules', 'exports', 'notes', 'attachments'], true)) {
            throw new RuntimeException('Use apenas as pastas de recursos do projeto.');
        }
        $root = app(WorkspaceFiles::class)->path($project);
        $path = $root;
        foreach ($parts as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Links simbólicos não são permitidos.');
            }
        }

        return $path;
    }

    public function file(Project $project, array $arguments): array
    {
        $operation = $arguments['operation'] ?? 'list';
        if ($operation === 'list') {
            $root = app(WorkspaceFiles::class)->path($project);
            $paths = [];
            foreach (['apps', 'flows', 'tables', 'schedules', 'exports', 'notes', 'attachments'] as $folder) {
                if (is_dir($root.'/'.$folder)) {
                    foreach (File::allFiles($root.'/'.$folder) as $file) {
                        $relative = substr($file->getPathname(), strlen($root) + 1);
                        if (! is_link($file->getPathname())) {
                            $paths[] = ['path' => $relative, 'size' => $file->getSize()];
                        }if (count($paths) >= 500) {
                            break 2;
                        }
                    }
                }
            }

            return ['files' => $paths];
        }
        $relative = (string) ($arguments['path'] ?? '');
        $path = $this->resolve($project, $relative);
        if ($operation === 'read') {
            if (! is_file($path) || filesize($path) > 256000) {
                throw new RuntimeException('Arquivo inexistente ou maior que o limite de leitura.');
            }
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['yaml', 'yml', 'json', 'csv', 'md', 'txt', 'svg'], true)) {
                throw new RuntimeException('Este formato não pode ser lido como texto.');
            }

            return ['path' => $relative, 'content' => File::get($path), 'sha256' => hash_file('sha256', $path)];
        }
        if ($operation !== 'write') {
            throw new RuntimeException('Operação de arquivo não permitida.');
        }
        if (in_array($relative, ['AGENTS.md', 'PROJECT.md', 'BI_GUIDE.md'], true)) {
            throw new RuntimeException('As regras e o contexto do projeto são somente leitura.');
        }
        if (str_starts_with($relative, 'attachments/')) {
            throw new RuntimeException('Anexos do usuário são somente leitura.');
        }
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['yaml', 'yml', 'json', 'md', 'txt'], true)) {
            throw new RuntimeException('Extensão de escrita não permitida.');
        }
        $content = (string) ($arguments['content'] ?? '');
        if (strlen($content) > 256000) {
            throw new RuntimeException('O arquivo excede o limite de 256 KB.');
        }
        if (is_file($path) && isset($arguments['expected_sha256']) && ! hash_equals(hash_file('sha256', $path), (string) $arguments['expected_sha256'])) {
            throw new RuntimeException('O arquivo foi alterado. Leia novamente antes de salvar.');
        }
        File::ensureDirectoryExists(dirname($path), 0700, true);
        File::put($path, $content);
        chmod($path, 0600);

        return ['path' => $relative, 'sha256' => hash_file('sha256', $path), 'saved' => true];
    }
}
