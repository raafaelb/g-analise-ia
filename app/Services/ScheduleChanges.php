<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use RuntimeException;

class ScheduleChanges
{
    public function inspect(User $user, Project $project, string $ref, bool $resume): void
    {
        $catalog = app(BiCatalog::class);
        $candidate = $catalog->workingMetadata($project)[$ref] ?? null;
        if (! is_array($candidate)) {
            throw new RuntimeException('A configuração da agenda precisa estar disponível antes de alterar sua execução.');
        }
        if ($resume) {
            $candidate['ativo'] = true;
        }
        $snapshot = $catalog->snapshot($user, (string) $project->tenant_id, true);
        if (($snapshot['capabilities']['schedule']['status'] ?? null) !== 'available' || $snapshot['warnings'] !== []) {
            throw new RuntimeException('Não foi possível conferir todas as agendas existentes. Atualize os acessos antes de ativar novas cargas.');
        }
        [$connection,$tenant] = $catalog->access($user, (string) $project->tenant_id);
        $metadata = $catalog->workingMetadata($catalog->context($connection, $tenant));
        $schedules = [];
        foreach ($snapshot['resources']['schedule'] as $row) {
            $body = $metadata['schedule:'.$row['id']] ?? null;
            if (! is_array($body)) {
                throw new RuntimeException('Metadados de agendas existentes indisponíveis; a nova carga não foi ativada.');
            }
            $schedules[(string) $row['id']] = $body;
        }
        $this->assertNoAdditionalLoad(substr($ref, 9), $candidate, $schedules);
    }

    public function assertNoAdditionalLoad(string $id, array $candidate, array $schedules): void
    {
        $this->preserveCadence($candidate, $schedules[$id] ?? []);
        if (($candidate['ativo'] ?? false) !== true) {
            return;
        }
        $loads = fn ($schedule) => array_values(array_map(fn ($binding) => (string) $binding['flow_etl_id'], array_filter($schedule['flows'] ?? [], fn ($binding) => isset($binding['flow_etl_id']) && ($binding['context'] ?? '') !== 'KeySweep')));
        $proposed = $loads($candidate);
        if (count($proposed) !== count(array_unique($proposed))) {
            throw new RuntimeException('A agenda contém uma fonte repetida. Vincule cada fonte apenas uma vez.');
        }
        $current = $schedules[$id] ?? [];
        $alreadyActive = ($current['ativo'] ?? false) === true ? $loads($current) : [];
        foreach (array_diff($proposed, $alreadyActive) as $flow) {
            foreach ($schedules as $otherId => $other) {
                if ((string) $otherId === $id || ($other['ativo'] ?? false) !== true) {
                    continue;
                }
                if (in_array($flow, $loads($other), true)) {
                    throw new RuntimeException('A fonte flow:'.$flow.' já possui a agenda ativa schedule:'.$otherId.'. Reutilize a agenda existente e preserve a frequência; uma carga duplicada não foi criada.');
                }
            }
        }
    }

    private function preserveCadence(array $candidate, array $current): void
    {
        $coverage = app(RefreshCoverage::class);
        $loads = fn (array $schedule) => array_map(fn (array $binding) => (string) $binding['flow_etl_id'], array_filter($schedule['flows'] ?? [], fn (array $binding) => isset($binding['flow_etl_id']) && ($binding['context'] ?? '') !== 'KeySweep'));
        if (($current['ativo'] ?? false) !== true || ! $loads($current) || ! $coverage->daily($current['triggers'] ?? [])) {
            return;
        }
        $message = 'Preserve a agenda diária ou mais frequente existente: não é permitido desativá-la, retirar suas fontes, reduzir a frequência ou restringir seus horários de execução.';
        if (($candidate['ativo'] ?? false) !== true || array_diff($loads($current), $loads($candidate)) || ($candidate['timezone'] ?? null) !== ($current['timezone'] ?? null)) {
            throw new RuntimeException($message);
        }
        foreach ($current['triggers'] ?? [] as $before) {
            $preserved = false;
            foreach ($candidate['triggers'] ?? [] as $after) {
                if ($this->canonical($before) === $this->canonical($after)) {
                    $preserved = true;
                    break;
                }
                $oldInterval = $this->intervalMinutes($before);
                $newInterval = $this->intervalMinutes($after);
                $restrictions = fn (array $trigger) => $this->canonical(array_diff_key($trigger, array_flip(['type', 'period'])));
                if ($oldInterval !== null && $newInterval !== null && $newInterval <= $oldInterval && $restrictions($before) === $restrictions($after)) {
                    $preserved = true;
                    break;
                }
            }
            if (! $preserved) {
                throw new RuntimeException($message);
            }
        }
    }

    private function intervalMinutes(array $trigger): ?int
    {
        return app(BiScheduleTiming::class)->intervalMinutes($trigger);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
