<?php

namespace App\Services;

use App\Http\Middleware\TrustDeploymentProxies;
use App\Models\Connection;
use Illuminate\Support\Facades\Artisan;

class DeploymentReadiness
{
    /** @return array{ok: bool, scope: string, checks: array<string, array{ok: bool, message: string}>, warnings: list<string>, features: array<string, bool>, operations?: array} */
    public function inspect(bool $runtime = false): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $address = parse_url($url);
        $path = is_array($address) ? ($address['path'] ?? '') : '';
        $host = is_array($address) ? ($address['host'] ?? '') : '';
        $checks = [];
        $warnings = [];
        $check = function (string $name, bool $ok, string $message) use (&$checks): void {
            $checks[$name] = ['ok' => $ok, 'message' => $message];
        };
        $check('environment', config('app.env') === 'production', 'Use APP_ENV=production para a instalação central.');
        $check('debug', config('app.debug') === false, 'APP_DEBUG deve estar desabilitado.');
        $check('preview', config('horus.preview') === false, 'LOCAL_PREVIEW deve estar desabilitado.');
        $check('registration', config('horus.registration') === false, 'Cadastre usuários pela administração; mantenha o cadastro público desabilitado.');
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check('encryption_key', is_string($decoded) && strlen($decoded) === 32, 'Configure uma APP_KEY de 32 bytes e preserve-a nas atualizações e no backup.');
        $check('public_url', $this->httpsAddress($url) && ! isset($address['query'], $address['fragment']) && preg_match('#^(?:/[A-Za-z0-9_-]+)*$#D', $path) === 1, 'Use uma URL HTTPS com o caminho público definitivo.');
        $check('assets_url', rtrim((string) config('app.asset_url'), '/') === $url, 'ASSET_URL deve corresponder à URL pública completa.');
        $check('public_host', config('operations.public_host') === $host, 'PUBLIC_HOST deve corresponder ao domínio da URL pública.');
        $check('public_path', config('operations.public_path') === $path, 'PUBLIC_PATH deve corresponder ao subdiretório da URL pública.');
        $check('session_path', config('session.path') === ($path ?: '/'), 'SESSION_PATH deve corresponder ao caminho público.');
        $check('session_security', config('session.secure') === true && config('session.encrypt') === true && config('session.http_only') === true && config('session.domain') === null, 'Use sessão segura, criptografada, HttpOnly e restrita ao domínio da instalação.');
        $check('database_driver', config('database.default') === 'pgsql', 'Use PostgreSQL no pacote central.');
        $check('shared_services', config('session.driver') === 'database' && config('cache.default') === 'redis' && config('queue.default') === 'redis', 'Use os serviços compartilhados de sessão, cache e filas do Compose.');
        $check('queue_retry', (int) config('queue.connections.redis.retry_after') > 960, 'O prazo de reserva da fila deve superar o timeout de 960 segundos dos workers.');
        $proxies = config('operations.trusted_proxies', []);
        $check('trusted_proxies', is_array($proxies) && count(array_filter($proxies, TrustDeploymentProxies::validAddress(...))) === count($proxies), 'Confie somente nos IPs ou CIDRs explícitos do proxy; não use curingas ou redes /0.');
        if ($proxies === []) {
            $warnings[] = 'O IP original do cliente não será usado até configurar TRUSTED_PROXIES com os endereços corretos do proxy.';
        }
        $reverb = config('reverb.apps.apps.0', []);
        $origins = $reverb['allowed_origins'] ?? [];
        $check('websocket', config('broadcasting.default') === 'reverb' && is_string($reverb['key'] ?? null) && strlen($reverb['key']) >= 16 && is_string($reverb['secret'] ?? null) && strlen($reverb['secret']) >= 32 && in_array($host, $origins, true) && ! in_array('*', $origins, true) && ($reverb['key'] ?? null) !== $key, 'Configure chaves distintas do Reverb e restrinja a origem ao domínio público.');
        $openai = (bool) config('horus.openai.enabled');
        $check('openai_mode', config('horus.openai.mode') === 'hosted', 'O modo OSS local não pode ser utilizado no servidor central.');
        if ($openai) {
            $check('openai_configuration', filled(config('horus.openai.client_id')) && config('horus.openai.plan_enabled') === true && filled(config('horus.openai.inference_scope')) && config('horus.openai.redirect_uri') === $url.'/auth/openai/callback', 'Use o client_id, escopo e callback oficialmente habilitados para a instalação central.');
            $warnings[] = 'Configuração presente não comprova elegibilidade da OpenAI nem isolamento de processos: homologue ambos antes de disponibilizar a IA central.';
        } else {
            $warnings[] = 'ChatGPT central está desabilitado: depende da configuração oficial da OpenAI e da homologação do isolamento no host. O teste local não habilita hospedagem central.';
        }
        if (config('suite.enabled')) {
            $check('suite_configuration', $this->httpsAddress((string) config('suite.base_url')) && filled(config('suite.application_code')), 'Configure o endereço HTTPS e o código da aplicação Suíte.');
        }
        if (config('horus.mcp.enabled')) {
            $check('mcp_configuration', $this->httpsAddress((string) config('horus.mcp.resource')) && config('horus.mcp.redirect_uri') === $url.'/auth/bi-mcp/callback', 'Confira o recurso MCP e o callback da instalação.');
        } else {
            $warnings[] = 'Consultas MCP estão desabilitadas nesta configuração.';
        }
        if (config('horus.viewer.enabled')) {
            $origins = config('horus.viewer.allowed_origins', []);
            $check('viewer_origins', is_array($origins) && $origins !== [] && count(array_filter($origins, $this->httpsAddress(...))) === count($origins), 'Restrinja o visualizador às origens HTTPS homologadas do BI.');
        } else {
            $warnings[] = 'Prévia interativa está desabilitada; homologue o visualizador no domínio final antes de habilitá-lo.';
        }
        $report = ['ok' => true, 'scope' => $runtime ? 'configuration_and_runtime' : 'configuration', 'checks' => [], 'warnings' => $warnings, 'features' => ['chatgpt_enabled' => $openai, 'bi_login_enabled' => (bool) config('horus.lumo.login_enabled'), 'suite_enabled' => (bool) config('suite.enabled'), 'mcp_enabled' => (bool) config('horus.mcp.enabled'), 'viewer_enabled' => (bool) config('horus.viewer.enabled')]];
        if ($runtime) {
            $migrationsReady = false;
            try {
                $migrator = app('migrator');
                $pending = $migrator->repositoryExists() ? array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()) : ['missing'];
                $migrationsReady = $pending === [];
                $check('migrations', $migrationsReady, 'Aplique as migrações desta versão antes de iniciar os workers.');
            } catch (\Throwable) {
                $check('migrations', false, 'Não foi possível conferir as migrações; verifique o banco configurado.');
            }
            $encryptedConnections = false;
            if ($migrationsReady) {
                try {
                    $encryptedConnections = true;
                    foreach (Connection::whereNotNull('credentials')->cursor() as $connection) {
                        if (! is_array($connection->credentials)) {
                            $encryptedConnections = false;
                            break;
                        }
                    }
                } catch (\Throwable) {
                    $encryptedConnections = false;
                }
            }
            $check('encrypted_connections', $encryptedConnections, 'Confira a APP_KEY original e as migrações: as conexões salvas devem continuar legíveis. Esta verificação não altera nem exibe credenciais.');
            $check('assets', is_file(public_path('build/manifest.json')), 'Compile os assets para o domínio e caminho definitivos.');
            $check('private_storage', is_writable(storage_path('app/private')) && is_writable(storage_path('framework')) && is_writable(storage_path('logs')), 'O usuário do serviço precisa escrever no armazenamento privado e no runtime.');
            foreach (['lumo', 'codex'] as $binary) {
                $file = config('horus.'.$binary.'.binary');
                $check($binary.'_binary', is_string($file) && is_file($file) && is_executable($file), 'Confira a presença do executável homologado na imagem.');
            }
            try {
                $exit = Artisan::call('ganalise:health');
                $operations = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
                $check('operations', $exit === 0 && ($operations['ok'] ?? false) === true, 'Confira banco, cache, scheduler e todos os workers; aguarde o primeiro heartbeat.');
                $report['operations'] = $operations;
            } catch (\Throwable) {
                $check('operations', false, 'A verificação operacional falhou; confira a saúde dos serviços.');
            }
        }
        $report['checks'] = $checks;
        $report['ok'] = ! in_array(false, array_column($checks, 'ok'), true);

        return $report;
    }

    private function httpsAddress(string $url): bool
    {
        $address = parse_url($url);

        return is_array($address) && ($address['scheme'] ?? '') === 'https' && filled($address['host'] ?? '') && ! isset($address['user']) && ! isset($address['pass']) && ! isset($address['query']) && ! isset($address['fragment']) && ! preg_match('/[\s\\\\]/', $url);
    }
}
