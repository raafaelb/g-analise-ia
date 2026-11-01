<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\Project;
use App\Services\Agent\SafeWorkspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class PdfAttachmentPages
{
    public function render(Project $project, Artifact $artifact, string $source): array
    {
        $this->assertOwnership($project, $artifact);
        if (! is_executable('/usr/bin/pdftoppm')) {
            throw new RuntimeException('A leitura visual de PDF não está disponível nesta instalação. Envie imagens das páginas ou um PDF com texto selecionável.');
        }
        $temporary = sys_get_temp_dir().'/g-analise-pdf-'.Str::uuid();
        File::ensureDirectoryExists($temporary, 0700, true);
        try {
            $process = new Process(['/usr/bin/pdftoppm', '-f', '1', '-l', '3', '-forcenum', '-scale-to', '1600', '-png', $source, $temporary.'/page'], null, [...ProcessEnvironment::isolated(), 'PATH' => '/usr/bin:/bin']);
            $process->setTimeout(30);
            $process->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Não foi possível preparar as páginas deste PDF para leitura visual. Confira se o arquivo está íntegro e sem senha.');
            }
            $files = [];
            foreach (File::files($temporary) as $file) {
                if (preg_match('/^page-([0-9]+)\.png$/D', $file->getFilename(), $match)) {
                    $files[(int) $match[1]] = $file->getPathname();
                }
            }
            ksort($files, SORT_NUMERIC);
            if ($files === [] || array_keys($files) !== range(1, count($files)) || count($files) > 3) {
                throw new RuntimeException('O PDF não retornou páginas visuais válidas. Envie imagens das páginas ou um PDF com texto selecionável.');
            }
            $pages = [];
            foreach ($files as $number => $path) {
                $size = $this->pngSize($path);
                $relative = $this->pagePath($artifact, $number);
                $target = app(SafeWorkspace::class)->resolve($project, $relative);
                File::ensureDirectoryExists(dirname($target), 0700, true);
                File::replace($target, File::get($path), 0600);
                $pages[] = ['page' => $number, 'path' => $relative, 'sha256' => hash_file('sha256', $target), 'width' => $size[0], 'height' => $size[1]];
            }

            return $pages;
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    public function verified(Project $project, Artifact $artifact, array $pages): array
    {
        $this->assertOwnership($project, $artifact);
        if ($pages === [] || ! array_is_list($pages) || count($pages) > 3) {
            throw new RuntimeException('As páginas de um anexo PDF não estão disponíveis. Envie novamente o arquivo.');
        }
        $verified = [];
        foreach ($pages as $index => $page) {
            $number = $index + 1;
            if (! is_array($page) || ($page['page'] ?? null) !== $number || ($page['path'] ?? null) !== $this->pagePath($artifact, $number) || ! is_string($page['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $page['sha256'])) {
                throw new RuntimeException('As páginas de um anexo PDF foram alteradas. Envie novamente o arquivo.');
            }
            $path = app(SafeWorkspace::class)->resolve($project, $page['path']);
            $this->pngSize($path);
            if (! hash_equals($page['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('As páginas de um anexo PDF foram alteradas. Envie novamente o arquivo.');
            }
            $verified[] = ['page' => $number, 'path' => $path];
        }

        return $verified;
    }

    private function pagePath(Artifact $artifact, int $number): string
    {
        return 'attachments/'.$artifact->id.'/page-'.$number.'.png';
    }

    private function assertOwnership(Project $project, Artifact $artifact): void
    {
        if ($artifact->kind !== 'attachment' || (string) $artifact->project_id !== (string) $project->id || (string) $artifact->user_id !== (string) $project->user_id) {
            throw new RuntimeException('Este anexo não pertence ao projeto atual.');
        }
    }

    private function pngSize(string $path): array
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > 8000000) {
            throw new RuntimeException('Uma página visual do PDF está indisponível ou excede os limites. Envie novamente o arquivo.');
        }
        $size = @getimagesize($path);
        if (! $size || $size['mime'] !== 'image/png' || min($size[0], $size[1]) < 1 || max($size[0], $size[1]) > 1600) {
            throw new RuntimeException('Uma página visual do PDF não é uma imagem válida. Envie novamente o arquivo.');
        }

        return $size;
    }
}
