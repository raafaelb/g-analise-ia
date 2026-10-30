<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class BiViewer
{
    public function __construct(private BiCatalog $catalog, private LumoClient $lumo) {}

    /** @return array<string, mixed> */
    public function create(User $user, string $tenant, string $appId, ?int $dashboard = null): array
    {
        abort_unless(config('horus.viewer.enabled'), 409, 'A visualização interativa ainda não foi habilitada nesta instalação. Use a prévia visual.');
        abort_if($user->role === 'viewer', 403, 'Seu perfil permite consultar a prévia visual e os dados. A sessão interativa não está disponível para este perfil.');
        $detail = $this->catalog->detail($user, $tenant, 'app', $appId);
        abort_if($detail['tenant']['read_only'] ?? false, 403, 'Este tenant está protegido para consulta. Use a prévia visual e os dados.');
        if ($dashboard !== null) {
            abort_unless(collect($detail['resource']['dashboards'] ?? [])->contains(fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $dashboard), 422, 'Este dashboard não pertence à aplicação selecionada.');
        }
        [$connection, $item] = $this->catalog->access($user, $tenant);
        $project = $this->catalog->context($connection, $item);
        $data = $this->lumo->run($connection, ['app', 'open', 'app:'.$appId, '--session', ...($dashboard !== null ? ['--dashboard', (string) $dashboard] : [])], $project, 60)['data'];
        if (! is_array($data) || ($data['session'] ?? null) !== true || ! is_string($data['url'] ?? null)) {
            throw new RuntimeException('O BI não confirmou uma sessão temporária válida. Use a prévia visual.');
        }
        $url = $data['url'];
        $origin = $this->origin($url);
        $allowed = $this->allowedOrigins();
        if ($origin === null || ! in_array($origin, $allowed, true)) {
            throw new RuntimeException('O visualizador retornou um domínio não autorizado. O administrador precisa conferir a configuração desta instalação.');
        }
        $ticketSeconds = $data['expires_in'] ?? null;
        $sessionMinutes = $data['session_minutes'] ?? null;
        if (! is_int($ticketSeconds) || $ticketSeconds < 1 || $ticketSeconds > 120 || ! is_int($sessionMinutes) || $sessionMinutes < 1 || $sessionMinutes > 30) {
            throw new RuntimeException('O BI não confirmou os prazos da sessão temporária. Use a prévia visual.');
        }
        Audit::record($user->id, 'bi.viewer.created', 'Sessão temporária de visualização preparada.', null, ['tenant_id' => $tenant, 'resource' => 'app:'.$appId, 'dashboard_id' => $dashboard, 'session_minutes' => $sessionMinutes]);

        return ['url' => $url, 'origin' => $origin, 'resource' => 'app:'.$appId, 'dashboard_id' => $dashboard, 'ticket_expires_at' => now()->addSeconds($ticketSeconds)->toIso8601String(), 'session_expires_at' => now()->addMinutes($sessionMinutes)->toIso8601String()];
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $portalOrigin = $this->origin((string) config('app.url'));

        return array_values(array_unique(array_filter(array_map($this->origin(...), config('horus.viewer.allowed_origins', [])), fn (?string $origin): bool => $origin !== null && $origin !== $portalOrigin)));
    }

    private function origin(string $url): ?string
    {
        if (strlen($url) > 8192 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $port = $parts['port'] ?? 443;

        return 'https://'.strtolower($parts['host']).($port === 443 ? '' : ':'.$port);
    }
}
