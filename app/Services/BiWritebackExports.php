<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class BiWritebackExports
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    /** @return array{stream: resource, size: int, filename: string} */
    public function prepare(User $user, string $tenant, string $id): array
    {
        $this->catalog->detail($user, $tenant, 'writeback', $id, includeRelations: false);
        [$connection, $item] = $this->catalog->access($user, $tenant);
        $project = $this->catalog->context($connection, $item);
        $accessVersion = $user->access_version;
        $generation = $connection->metadata['session_generation'] ?? null;
        $root = $this->root();
        File::ensureDirectoryExists($root, 0700, true);
        chmod($root, 0700);
        $path = $root.'/writeback-'.Str::uuid().'.xlsx';
        $stream = null;
        try {
            $this->lumo->run($connection, ['writeback', 'export', 'writeback:'.$id, '--output', $path], $project, 120);
            if (is_link($path) || ! is_file($path) || realpath($path) !== realpath($root).'/'.basename($path)) {
                throw new RuntimeException('O BI não retornou um arquivo de exportação válido.');
            }
            $size = filesize($path);
            if ($size < 1 || $size > max(1, (int) config('horus.downloads.max_bytes'))) {
                throw new RuntimeException('A planilha excede o limite de exportação desta instalação ou foi retornada vazia.');
            }
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('O BI não retornou uma planilha XLSX válida.');
            }
            try {
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw new RuntimeException('O BI não retornou uma planilha XLSX válida.');
                }
            } finally {
                $zip->close();
            }
            $current = $connection->fresh();
            $actor = $user->fresh();
            if (! $actor?->canStartWork() || $actor->access_version !== $accessVersion || ! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation) {
                throw new RuntimeException('Seu acesso ou a conexão BI mudou durante a exportação. Abra o cadastro novamente.');
            }
            chmod($path, 0600);
            $stream = fopen($path, 'rb');
            if ($stream === false || ! unlink($path)) {
                throw new RuntimeException('Não foi possível preparar o download protegido da planilha.');
            }
            Audit::record($user->id, 'bi.writeback.exported', 'Planilha de cadastro preparada para download individual.', null, ['tenant_id' => $tenant, 'resource' => 'writeback:'.$id, 'bytes' => $size]);

            return ['stream' => $stream, 'size' => $size, 'filename' => 'cadastro-'.$id.'.xlsx'];
        } catch (\Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $error;
        } finally {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
    }

    public function purgeStale(): int
    {
        $root = $this->root();
        $removed = 0;
        foreach (is_dir($root) ? File::files($root) : [] as $file) {
            if ($file->isLink() || ! preg_match('/^writeback-([0-9a-f-]{36})\.xlsx$/D', $file->getFilename(), $match) || ! Str::isUuid($match[1]) || $file->getMTime() >= now()->subHour()->getTimestamp()) {
                continue;
            }
            if (unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    private function root(): string
    {
        $root = (string) config('horus.downloads.root');
        if ($root === '' || is_link($root)) {
            throw new RuntimeException('A pasta protegida de downloads precisa ser conferida pelo administrador.');
        }

        return $root;
    }
}
