<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiWorkspaceCheckpoint
{
    private const CREATED = 'bi.workspace.refresh_checkpoint';

    private const CLOSED = 'bi.workspace.refresh_checkpoint_closed';

    public function __construct(private WorkspaceFiles $workspaces) {}

    /** @return array{relative: string, sha256: string}|null */
    public function document(Project $project, string $resource): ?array
    {
        if (! preg_match('/^(app|flow|table|schedule|agent|bi-desk|dw-desk):([1-9][0-9]*)$/D', $resource, $match)) {
            throw new RuntimeException('Recurso inválido para preservar a versão do projeto.');
        }
        $folder = ['app' => 'apps', 'flow' => 'flows', 'table' => 'tables', 'schedule' => 'schedules', 'agent' => 'agents', 'bi-desk' => 'bi-desks', 'dw-desk' => 'dw-desks'][$match[1]];
        $root = $this->workspaces->path($project);
        if (is_link($root) || is_link($root.'/'.$folder) || ! is_dir($root.'/'.$folder)) {
            return null;
        }
        $matches = [];
        foreach (File::files($root.'/'.$folder) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Há um link simbólico na pasta deste recurso. Nenhuma versão será substituída.');
            }
            if ($file->getSize() > 2_000_000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                continue;
            }
            $text = File::get($file->getPathname());
            $parts = preg_split('/^---\s*$/m', $text, 2);
            if (count($parts) !== 2) {
                continue;
            }
            try {
                $header = Yaml::parse($parts[0]);
                $body = Yaml::parse($parts[1]);
            } catch (Throwable) {
                continue;
            }
            if (is_array($header) && ($header['kind'] ?? null) === $match[1] && is_int($header['id'] ?? null) && (string) $header['id'] === $match[2] && is_int($header['tenantId'] ?? null) && (string) $header['tenantId'] === (string) $project->tenant_id && is_array($body)) {
                $matches[] = ['relative' => $folder.'/'.$file->getFilename(), 'sha256' => hash('sha256', $text)];
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Há mais de um arquivo para este recurso. Nenhuma versão será substituída.');
        }

        return $matches[0] ?? null;
    }

    /**
     * A native pull may advance its comparison base before refusing old WORKING files.
     * Only the latest unused receipt for these exact bytes and access can recover that case.
     *
     * @param  array{relative: string, sha256: string}|null  $document
     */
    public function protect(Connection $connection, Project $project, string $resource, ?array $document, bool $nativeClean): ?AuditEvent
    {
        $scope = $this->scope($connection, $project, $resource);
        $this->assertUnchanged($project, $resource, $document);
        $existing = $this->latest($project, $resource);
        if ($existing && $this->pending($existing) && $this->valid($existing, $scope, $document)) {
            $this->verifyGit($project, $existing->metadata, $document);

            return $existing;
        }
        if (! $nativeClean) {
            return null;
        }
        $commit = $this->checkpoint($project, $document);
        $this->assertUnchanged($project, $resource, $document);
        if ($scope !== $this->scope($connection, $project, $resource)) {
            throw new RuntimeException('O acesso mudou durante a preservação dos arquivos. Confira a sessão antes de atualizar.');
        }
        $metadata = [...$scope, 'relative' => $document['relative'], 'sha256' => $document['sha256'], 'commit' => $commit, 'native_clean_verified' => true];
        $metadata['signature'] = $this->signature($metadata);

        return AuditEvent::create(['user_id' => $project->user_id, 'action' => self::CREATED, 'summary' => 'Versão local sem alterações de configuração preservada antes de receber a versão do BI.', 'resource_id' => $project->id, 'metadata' => $metadata]);
    }

    /** @param array{relative: string, sha256: string}|null $document */
    public function assertReady(Connection $connection, Project $project, string $resource, ?array $document, AuditEvent $checkpoint): void
    {
        $this->assertUnchanged($project, $resource, $document);
        if (! $this->pending($checkpoint) || ! $this->valid($checkpoint, $this->scope($connection, $project, $resource), $document)) {
            throw new RuntimeException('O comprovante desta atualização mudou. Os arquivos locais serão preservados.');
        }
        $this->verifyGit($project, $checkpoint->metadata, $document);
    }

    public function close(Project $project, string $resource): void
    {
        $checkpoint = $this->latest($project, $resource);
        if ($checkpoint && $this->pending($checkpoint)) {
            Audit::record($project->user_id, self::CLOSED, 'Comprovante de recebimento encerrado; não autoriza substituir edições futuras.', $project->id, ['resource' => $resource, 'checkpoint_id' => $checkpoint->id]);
        }
    }

    private function assertUnchanged(Project $project, string $resource, ?array $expected): void
    {
        if ($expected === null || $this->document($project, $resource) !== $expected) {
            throw new RuntimeException('Os arquivos locais mudaram durante a conferência ou não puderam ser identificados. Eles foram preservados. Confira a versão antes de atualizar.');
        }
    }

    private function latest(Project $project, string $resource): ?AuditEvent
    {
        return AuditEvent::where('user_id', $project->user_id)->where('resource_id', $project->id)->where('action', self::CREATED)->where('metadata->resource', $resource)->latest('created_at')->latest('id')->first();
    }

    private function pending(AuditEvent $checkpoint): bool
    {
        return ! AuditEvent::where('user_id', $checkpoint->user_id)->where('resource_id', $checkpoint->resource_id)->where('action', self::CLOSED)->where('metadata->checkpoint_id', $checkpoint->id)->exists();
    }

    private function scope(Connection $connection, Project $project, string $resource): array
    {
        $current = $project->fresh();
        $user = User::find($project->user_id);
        $latest = $connection->fresh();
        $generation = $connection->metadata['session_generation'] ?? null;
        if (! $current || $current->user_id !== $project->user_id || $current->tenant_id !== $project->tenant_id || $current->source_ref !== $project->source_ref || $current->status === 'archived' || ! $user?->canStartWork() || $user->role === 'viewer' || ! $latest || $latest->user_id !== $project->user_id || $latest->provider !== 'horus' || $latest->status !== 'connected' || ! is_string($generation) || $generation === '' || ($latest->metadata['session_generation'] ?? null) !== $generation || ($connection->operationAccessVersion !== null && $connection->operationAccessVersion !== $user->access_version)) {
            throw new RuntimeException('O contexto ou o acesso ao projeto mudou. Nenhuma versão será substituída.');
        }

        return ['user_id' => $user->id, 'project_id' => $project->id, 'project_source' => $project->source_ref, 'tenant_id' => (string) $project->tenant_id, 'resource' => $resource, 'connection_id' => $connection->id, 'generation' => $generation, 'access_version' => $user->access_version];
    }

    private function valid(AuditEvent $checkpoint, array $scope, array $document): bool
    {
        $metadata = $checkpoint->metadata ?? [];
        $signature = $metadata['signature'] ?? null;
        unset($metadata['signature']);
        $expectedKeys = [...array_keys($scope), 'relative', 'sha256', 'commit', 'native_clean_verified'];
        $actualScope = array_intersect_key($metadata, $scope);
        ksort($actualScope);
        ksort($scope);

        return count($metadata) === count($expectedKeys) && array_diff(array_keys($metadata), $expectedKeys) === [] && $actualScope === $scope && ($metadata['relative'] ?? null) === $document['relative'] && ($metadata['sha256'] ?? null) === $document['sha256'] && ($metadata['native_clean_verified'] ?? null) === true && is_string($metadata['commit'] ?? null) && preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $metadata['commit']) && is_string($signature) && hash_equals($this->signature($metadata), $signature);
    }

    private function signature(array $metadata): string
    {
        ksort($metadata);

        return hash_hmac('sha256', 'workspace-refresh-v1|'.json_encode($metadata, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function checkpoint(Project $project, array $document): string
    {
        $root = $this->workspaces->path($project);
        if (is_link($root.'/.git') || (file_exists($root.'/.git') && ! is_dir($root.'/.git'))) {
            throw new RuntimeException('O checkpoint local não pôde ser identificado com segurança.');
        }
        foreach ([['init', '--quiet'], ['add', '--force', '--', $document['relative']], ['commit', '--quiet', '--allow-empty', '-m', 'Antes de receber versao atual do BI', '--only', '--', $document['relative']]] as $arguments) {
            $this->git($root, $arguments);
        }

        return trim($this->git($root, ['rev-parse', 'HEAD']));
    }

    private function verifyGit(Project $project, array $proof, array $document): void
    {
        $root = $this->workspaces->path($project);
        if (is_link($root.'/.git') || ! is_dir($root.'/.git') || ! hash_equals($document['sha256'], hash('sha256', $this->git($root, ['show', $proof['commit'].':'.$document['relative']])))) {
            throw new RuntimeException('O checkpoint preservado não pôde ser conferido. Nenhum arquivo será substituído.');
        }
    }

    private function git(string $root, array $arguments): string
    {
        $environment = array_merge(ProcessEnvironment::isolated(), ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']);
        $process = new Process(['git', '--no-replace-objects', '--literal-pathspecs', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', '-c', 'user.name=G-Analise IA', '-c', 'user.email=local@g-analise.invalid', ...$arguments], $root, $environment);
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Não foi possível preservar e conferir a versão local. Nenhum arquivo será substituído.');
        }

        return $process->getOutput();
    }
}
