<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class BiScheduleTiming
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function frequency(array $triggers): string
    {
        $labels = [];
        foreach (array_slice($triggers, 0, 100) as $trigger) {
            $labels[] = is_array($trigger) ? $this->description($trigger) : 'Frequência a conferir';
        }
        if (count($triggers) > 100) {
            $labels[] = (count($triggers) - 100).' gatilhos adicionais a conferir';
        }

        return $labels === [] ? 'Frequência a conferir' : implode('; ', $labels);
    }

    public function daily(array $triggers): bool
    {
        if (count($triggers) > 1000) {
            return false;
        }
        $weekdays = [];
        foreach ($triggers as $trigger) {
            if (! is_array($trigger) || ! $this->unrestricted($trigger) || ! $this->current($trigger)) {
                continue;
            }
            $kind = $trigger['type'] ?? null;
            if ($kind === 'Day' && $this->clock($trigger['start_when'] ?? null) !== null) {
                return true;
            }
            if ($kind === 'Week' && $this->clock($trigger['start_when'] ?? null) !== null && ($weekday = $this->integer($trigger['period'] ?? null, 0, 6)) !== null) {
                $weekdays[$weekday] = true;
            }
            if (in_array($kind, ['Hour', 'Minute'], true) && $this->intervalDaily($trigger)) {
                return true;
            }
        }

        return count($weekdays) === 7;
    }

    public function intervalMinutes(array $trigger): ?int
    {
        return match ($trigger['type'] ?? null) {
            'Day' => $this->clock($trigger['start_when'] ?? null) === null ? null : 1440,
            'Hour' => ($period = $this->integer($trigger['period'] ?? null, 1, 24)) === null ? null : $period * 60,
            'Minute' => $this->integer($trigger['period'] ?? null, 1, 1440),
            default => null,
        };
    }

    private function description(array $trigger): string
    {
        $time = $this->clock($trigger['start_when'] ?? null) === null ? null : $trigger['start_when'];
        $period = $this->integer($trigger['period'] ?? null, 0, 86400);
        $label = match ($trigger['type'] ?? null) {
            'Day' => $time === null ? 'Horário diário a conferir' : 'Diariamente às '.$time,
            'Week' => $time === null || $period === null || $period > 6 ? 'Horário semanal a conferir' : 'Semanalmente, '.['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'][$period].', às '.$time,
            'Month' => $time === null || $period === null || $period < 1 || $period > 31 ? 'Horário mensal a conferir' : 'Mensalmente, dia '.$period.', às '.$time.($period > 28 ? ' (ajustado ao último dia nos meses mais curtos)' : ''),
            'Hour', 'Minute' => $this->intervalDescription($trigger),
            'CDC' => $period === null || $period < 5 || $period > 3600 ? 'Sondagem de mudanças a conferir' : 'Quando os dados mudarem · sondagem a cada '.$period.' segundos'.$this->windowLabel($trigger, true),
            default => 'Frequência a conferir',
        };

        if (! empty($trigger['end_when'])) {
            $end = $this->instant($trigger['end_when']);
            $label .= $end === null ? ' · término a conferir' : ' · '.($end->isFuture() ? 'termina' : 'encerrado').' em '.$end->setTimezone(self::TIMEZONE)->format('d/m/Y H:i').' (Brasília)';
        }

        return $label.($this->unrestricted($trigger) ? '' : ' · dias restritos ou a conferir');
    }

    private function intervalDescription(array $trigger): string
    {
        $minutes = $this->intervalMinutes($trigger);
        if ($minutes === null) {
            return 'Intervalo a conferir';
        }
        $hours = $trigger['type'] === 'Hour';
        $period = $hours ? intdiv($minutes, 60) : $minutes;
        $unit = $hours ? ($period === 1 ? 'hora' : 'horas') : ($period === 1 ? 'minuto' : 'minutos');

        $anchor = $this->instant($trigger['start_when'] ?? null);
        $note = $anchor === null ? ' · início a conferir' : ($anchor->isFuture() ? ' · começa em '.$anchor->setTimezone(self::TIMEZONE)->format('d/m/Y H:i').' (Brasília)' : '');
        if ($anchor !== null && ! $anchor->isFuture() && $this->window($trigger) !== null && ! $this->intervalDaily($trigger)) {
            $note .= ' · não garante carga todos os dias';
        }

        return 'A cada '.$period.' '.$unit.$this->windowLabel($trigger).$note;
    }

    private function windowLabel(array $trigger, bool $optional = false): string
    {
        if ($optional && empty($trigger['start_time']) && empty($trigger['end_time'])) {
            return '';
        }
        if ($this->window($trigger) === null) {
            return ' · janela a conferir';
        }

        return ' · das '.$trigger['start_time'].' às '.$trigger['end_time'].' (Brasília)';
    }

    private function intervalDaily(array $trigger): bool
    {
        $minutes = $this->intervalMinutes($trigger);
        $anchor = $this->instant($trigger['start_when'] ?? null);
        $window = $this->window($trigger);
        if ($minutes === null || $anchor === null || $anchor->isFuture() || $window === null) {
            return false;
        }
        $gcd = $this->gcd($minutes, 1440);
        $cycleDays = intdiv($minutes, $gcd);
        $cycleSeconds = $cycleDays * 86400;
        $reference = now(self::TIMEZONE)->startOfDay()->getTimestamp();
        $seconds = $minutes * 60;
        $first = (($anchor->getTimestamp() - $reference) % $seconds + $seconds) % $seconds;
        $days = [];
        for ($point = $first; $point < $cycleSeconds; $point += $minutes * 60) {
            $clock = intdiv($point % 86400, 60);
            if ($clock >= $window[0] && $clock <= $window[1]) {
                $days[intdiv($point, 86400)] = true;
            }
        }

        return count($days) === $cycleDays;
    }

    /** @return array{int, int}|null */
    private function window(array $trigger): ?array
    {
        $start = $this->clock($trigger['start_time'] ?? null);
        $end = $this->clock($trigger['end_time'] ?? null);

        return $start !== null && $end !== null && $start < $end ? [$start, $end] : null;
    }

    private function current(array $trigger): bool
    {
        if (empty($trigger['end_when'])) {
            return true;
        }
        $end = $this->instant($trigger['end_when']);

        return $end !== null && $end->isFuture();
    }

    private function unrestricted(array $trigger): bool
    {
        foreach (['days', 'days_of_week', 'weekdays'] as $field) {
            $days = $trigger[$field] ?? [];
            if ($days === [] || $days === null) {
                continue;
            }
            if (! is_array($days) || ! array_is_list($days)) {
                return false;
            }
            $values = [];
            foreach ($days as $day) {
                $value = $this->integer($day, 0, 7);
                if ($value === null) {
                    return false;
                }
                $values[$value] = true;
            }
            $keys = array_keys($values);
            sort($keys);
            if ($keys !== range(0, 6) && $keys !== range(1, 7)) {
                return false;
            }
        }

        return true;
    }

    private function clock(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $value, $match)) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    private function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[0-9]{1,6}$/D', (string) $value)) {
            return null;
        }
        $value = (int) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function instant(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:\.\d{1,6})?(Z|[+-](\d{2}):([0-5][0-9]))$/D', $value, $match) || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1]) || (isset($match[8]) && ((int) $match[8] > 14 || ((int) $match[8] === 14 && (int) $match[9] !== 0)))) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function gcd(int $first, int $second): int
    {
        while ($second !== 0) {
            [$first, $second] = [$second, $first % $second];
        }

        return $first;
    }
}
