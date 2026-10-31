<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class BiCloneNames
{
    public function __construct(private LumoClient $lumo, private WorkspaceFiles $workspaces, private TenantPolicy $policy) {}

    /** @return array{verified: bool, name: string, renamed: bool} */
    public function ensure(Connection $connection, Project $project, string $ref, string $name): array
    {
        if (! preg_match('/^(app|flow):([1-9][0-9]*)$/D', $ref, $match) || trim($name) === '' || mb_strlen($name) > 150 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('Nome ou referência inválida para a cópia.');
        }
        $managed = ManagedBiResource::where('user_id', $project->user_id)->where('project_id', $project->id)->where('tenant_id', $project->tenant_id)->where('resource', $ref)->where('status', 'draft')->where('origin', 'clone_'.$match[1])->first();
        if (! $managed || $managed->source_ref === $ref || $connection->user_id !== $project->user_id) {
            throw new RuntimeException('O nome só pode ser ajustado na cópia criada e registrada por este projeto.');
        }
        $generation = $connection->metadata['session_generation'] ?? null;
        $this->assertWritable($connection, $project, $generation, $ref);
        $remote = $this->body($connection, $project, $ref);
        if (($remote['nome'] ?? null) === $name) {
            return ['verified' => true, 'name' => $name, 'renamed' => false];
        }
        $this->lumo->run($connection, ['pull', $ref], $project, 90);
        $this->assertWritable($connection, $project, $generation, $ref);
        [$path, $original, $header, $working, $bodyText] = $this->working($project, $match[1], $match[2]);
        $proposal = $header."---\n".$this->replaceName($bodyText, $name);
        $parts = preg_split('/^---[ \t]*\r?$/m', $proposal, 2);
        $proposed = Yaml::parse($parts[1] ?? '');
        if (! is_array($proposed) || ($proposed['nome'] ?? null) !== $name || $this->configuration($proposed, false) !== $this->configuration($working, false)) {
            throw new RuntimeException('A alteração proposta não ficou restrita ao nome da cópia.');
        }
        $pushStarted = false;
        try {
            File::replace($path, $proposal, 0600);
            $this->lumo->run($connection, ['lint', $path], $project, 90);
            $current = $this->body($connection, $project, $ref);
            if (($current['nome'] ?? null) !== ($remote['nome'] ?? null)) {
                throw new RuntimeException('A cópia mudou durante a validação do nome.');
            }
            $this->assertWritable($connection, $project, $generation, $ref);
            $pushStarted = true;
            $this->lumo->run($connection, ['push', $ref], $project, 90);
            $confirmed = $this->body($connection, $project, $ref);
            if (($confirmed['nome'] ?? null) !== $name) {
                throw new RuntimeException('O BI manteve outro nome após a sincronização.');
            }
            [, , , $saved] = $this->working($project, $match[1], $match[2]);
            if (($saved['nome'] ?? null) !== $name || $this->configuration($saved) !== $this->configuration($working)) {
                throw new RuntimeException('Outras configurações da cópia foram alteradas pelo BI.');
            }
            $this->assertWritable($connection, $project, $generation, $ref);

            return ['verified' => true, 'name' => $name, 'renamed' => true];
        } catch (Throwable $e) {
            if (! $pushStarted && is_file($path) && hash_equals(hash('sha256', $proposal), hash_file('sha256', $path))) {
                File::replace($path, $original, 0600);
            }
            throw $e;
        }
    }

    private function assertWritable(Connection $connection, Project $project, ?string $generation, string $ref): void
    {
        $user = User::find($project->user_id);
        $current = $connection->fresh();
        if (! $user || $user->role === 'viewer' || ! $current || $current->status !== 'connected' || ($current->metadata['session_generation'] ?? null) !== $generation) {
            throw new RuntimeException('A permissão ou a sessão mudou durante o ajuste da cópia.');
        }
        $this->policy->assertCommandAllowed((string) $project->tenant_id, ['push', $ref], $current);
    }

    /** @return array<string, mixed> */
    private function body(Connection $connection, Project $project, string $ref): array
    {
        $data = $this->lumo->run($connection, ['info', $ref, '--full'], $project, 60)['data'];
        $payload = is_array($data) ? ($data['payload'] ?? $data) : [];
        $body = $payload['body'] ?? (str_starts_with($ref, 'flow:') ? $payload : null);
        if (! is_array($body) || (isset($payload['header']['tenantId']) && (string) $payload['header']['tenantId'] !== (string) $project->tenant_id) || (isset($payload['header']['id']) && (string) $payload['header']['id'] !== explode(':', $ref, 2)[1])) {
            throw new RuntimeException('A cópia retornou fora do contexto autorizado.');
        }
        if (str_starts_with($ref, 'flow:') && (string) ($body['id'] ?? '') !== explode(':', $ref, 2)[1]) {
            throw new RuntimeException('O BI retornou outro ETL durante a conferência do nome.');
        }
        $state = is_array($body['_state'] ?? null) ? $body['_state'] : [];
        if ((! array_key_exists('deskId', $body) && ! array_key_exists('deskId', $state)) || isset($body['deskId']) || isset($state['deskId'])) {
            throw new RuntimeException('O ajuste de nome exige uma cópia ainda em rascunho.');
        }

        return $body;
    }

    /** @return array{string, string, string, array<string, mixed>, string} */
    private function working(Project $project, string $kind, string $id): array
    {
        $folder = $this->workspaces->path($project).($kind === 'flow' ? '/flows' : '/apps');
        if (! is_dir($folder) || is_link($folder)) {
            throw new RuntimeException('A autoria da cópia não está disponível.');
        }
        foreach (File::files($folder) as $file) {
            if ($file->isLink() || $file->getSize() > 2000000 || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                continue;
            }
            $text = File::get($file->getPathname());
            $parts = preg_split('/^---[ \t]*\r?$/m', $text, 2);
            $header = Yaml::parse($parts[0]);
            if (! is_array($header) || (string) ($header['id'] ?? '') !== $id || ($header['kind'] ?? '') !== $kind || (string) ($header['tenantId'] ?? '') !== (string) $project->tenant_id) {
                continue;
            }
            $body = Yaml::parse($parts[1] ?? '');
            if (! is_array($body) || ! is_string($body['nome'] ?? null)) {
                throw new RuntimeException('O nome da cópia não está em um formato de autoria válido.');
            }

            return [$file->getPathname(), $text, $parts[0], $body, $parts[1]];
        }
        throw new RuntimeException('O YAML da cópia não foi encontrado neste projeto.');
    }

    private function replaceName(string $body, string $name): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $body);
        $indices = array_keys(array_filter($lines, fn (string $line): bool => (bool) preg_match('/^nome[ \t]*:/', $line)));
        if (count($indices) !== 1) {
            throw new RuntimeException('Não foi possível localizar um único campo de nome na cópia.');
        }
        $start = $indices[0];
        $end = $start + 1;
        while ($end < count($lines) && ($lines[$end] === '' || preg_match('/^[ \t#]/', $lines[$end]))) {
            $end++;
        }
        $field = rtrim(Yaml::dump(['nome' => $name], 2, 2), "\n");
        $replacement = [...array_slice($lines, 0, $start), $field, ...array_slice($lines, $end)];

        return implode("\n", $replacement)."\n";
    }

    /**
     * Native app saves update expression audit fields even for a name-only change.
     * Keep identifiers, formulas and every other authoring field in the comparison.
     */
    private function configuration(array $body, bool $excludeServerMetadata = true): array
    {
        unset($body['nome']);
        if ($excludeServerMetadata) {
            unset($body['_state']);
            foreach ($body['expressions'] ?? [] as $key => $expression) {
                if (is_array($expression)) {
                    unset($expression['atualizado_em'], $expression['atualizado_por']);
                    $body['expressions'][$key] = $expression;
                }
            }
        }

        return $this->canonical($body);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
