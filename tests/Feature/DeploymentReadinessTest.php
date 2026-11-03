<?php

namespace Tests\Feature;

use App\Services\DeploymentReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    private function productionConfiguration(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('K', 32)),
            'app.url' => 'https://suite.example.test/ia-ganalise',
            'app.asset_url' => 'https://suite.example.test/ia-ganalise',
            'horus.preview' => false, 'horus.registration' => false,
            'horus.openai.mode' => 'hosted', 'horus.openai.enabled' => false,
            'horus.lumo.login_enabled' => true, 'horus.mcp.enabled' => false, 'horus.viewer.enabled' => false,
            'suite.enabled' => true, 'suite.base_url' => 'https://suite.example.test', 'suite.application_code' => 'g-analise-ia',
            'operations.public_host' => 'suite.example.test', 'operations.public_path' => '/ia-ganalise',
            'operations.trusted_proxies' => ['172.20.0.1', 'fd00::1/128'],
            'session.path' => '/ia-ganalise', 'session.secure' => true, 'session.encrypt' => true, 'session.http_only' => true, 'session.domain' => null,
            'database.default' => 'pgsql', 'session.driver' => 'database', 'cache.default' => 'redis', 'queue.default' => 'redis',
            'queue.connections.redis.retry_after' => 1200,
            'broadcasting.default' => 'reverb',
            'reverb.apps.apps.0' => ['key' => str_repeat('R', 24), 'secret' => str_repeat('S', 32), 'allowed_origins' => ['suite.example.test']],
        ]);
    }

    public function test_configuration_reports_disabled_features_without_contacting_providers_or_exposing_secrets(): void
    {
        $this->productionConfiguration();
        Http::preventStrayRequests();
        Queue::fake();

        $exit = Artisan::call('ganalise:preflight');
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['ok']);
        $this->assertSame('configuration', $report['scope']);
        $this->assertFalse($report['features']['chatgpt_enabled']);
        $this->assertTrue($report['features']['bi_login_enabled']);
        $this->assertCount(3, $report['warnings']);
        $this->assertStringContainsString('configuração oficial', $report['warnings'][0]);
        foreach (['app.key', 'reverb.apps.apps.0.key', 'reverb.apps.apps.0.secret'] as $key) {
            $this->assertStringNotContainsString(config($key), $output);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidConfiguration')]
    public function test_unsafe_or_inconsistent_configuration_fails_the_named_check(string $key, mixed $value, string $check): void
    {
        $this->productionConfiguration();
        config([$key => $value]);

        $report = app(DeploymentReadiness::class)->inspect();

        $this->assertFalse($report['ok']);
        $this->assertFalse($report['checks'][$check]['ok']);
    }

    public static function invalidConfiguration(): array
    {
        return [
            'local environment' => ['app.env', 'local', 'environment'],
            'debug enabled' => ['app.debug', true, 'debug'],
            'demo enabled' => ['horus.preview', true, 'preview'],
            'public registration' => ['horus.registration', true, 'registration'],
            'invalid key' => ['app.key', 'base64:invalid', 'encryption_key'],
            'http address' => ['app.url', 'http://suite.example.test/ia-ganalise', 'public_url'],
            'credentials in address' => ['app.url', 'https://user:password@suite.example.test/ia-ganalise', 'public_url'],
            'query in address' => ['app.url', 'https://suite.example.test/ia-ganalise?token=private', 'public_url'],
            'unsafe path' => ['app.url', 'https://suite.example.test/../ia-ganalise', 'public_url'],
            'wrong asset base' => ['app.asset_url', 'https://suite.example.test', 'assets_url'],
            'wrong public host' => ['operations.public_host', 'another.example.test', 'public_host'],
            'wrong public path' => ['operations.public_path', '', 'public_path'],
            'unscoped cookie' => ['session.path', '/', 'session_path'],
            'insecure cookie' => ['session.secure', false, 'session_security'],
            'unencrypted session' => ['session.encrypt', false, 'session_security'],
            'shared domain cookie' => ['session.domain', '.example.test', 'session_security'],
            'sqlite central database' => ['database.default', 'sqlite', 'database_driver'],
            'local cache' => ['cache.default', 'file', 'shared_services'],
            'retry before job ends' => ['queue.connections.redis.retry_after', 960, 'queue_retry'],
            'wildcard proxy' => ['operations.trusted_proxies', ['*'], 'trusted_proxies'],
            'all IPv4 proxy' => ['operations.trusted_proxies', ['0.0.0.0/0'], 'trusted_proxies'],
            'invalid proxy' => ['operations.trusted_proxies', ['172.20.0.1/33'], 'trusted_proxies'],
            'wildcard websocket origin' => ['reverb.apps.apps.0.allowed_origins', ['suite.example.test', '*'], 'websocket'],
            'OSS local in production' => ['horus.openai.mode', 'oss_local', 'openai_mode'],
            'enabled without hosted settings' => ['horus.openai.enabled', true, 'openai_configuration'],
            'unsafe Suite URL' => ['suite.base_url', 'http://suite.example.test', 'suite_configuration'],
        ];
    }

    public function test_optional_services_require_complete_settings_and_do_not_claim_external_homologation(): void
    {
        $this->productionConfiguration();
        config([
            'horus.openai.enabled' => true, 'horus.openai.client_id' => 'synthetic-client',
            'horus.openai.plan_enabled' => true, 'horus.openai.inference_scope' => 'synthetic-scope',
            'horus.openai.redirect_uri' => 'https://suite.example.test/ia-ganalise/auth/openai/callback',
            'horus.mcp.enabled' => true, 'horus.mcp.resource' => 'https://bi.example.test/mcp',
            'horus.mcp.redirect_uri' => 'https://suite.example.test/ia-ganalise/auth/bi-mcp/callback',
            'horus.viewer.enabled' => true, 'horus.viewer.allowed_origins' => ['https://bi.example.test'],
        ]);

        $report = app(DeploymentReadiness::class)->inspect();

        $this->assertTrue($report['ok']);
        $this->assertStringContainsString('não comprova elegibilidade', $report['warnings'][0]);
        config(['horus.mcp.redirect_uri' => 'https://another.example.test/callback', 'horus.viewer.allowed_origins' => ['https://bi.example.test', '*']]);
        $invalid = app(DeploymentReadiness::class)->inspect();
        $this->assertFalse($invalid['ok']);
        $this->assertFalse($invalid['checks']['mcp_configuration']['ok']);
        $this->assertFalse($invalid['checks']['viewer_origins']['ok']);
    }

    public function test_runtime_check_fails_for_missing_migrations_and_workers_without_running_migrations(): void
    {
        $this->productionConfiguration();
        $this->mock('migrator', function ($migrator): void {
            $migrator->shouldReceive('repositoryExists')->once()->andReturn(false);
        });
        Artisan::shouldReceive('call')->once()->with('ganalise:health')->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('{"ok":false,"checks":{"worker:actions":false}}');

        $report = app(DeploymentReadiness::class)->inspect(true);

        $this->assertFalse($report['ok']);
        $this->assertSame('configuration_and_runtime', $report['scope']);
        $this->assertFalse($report['checks']['migrations']['ok']);
        $this->assertFalse($report['checks']['operations']['ok']);
        $this->assertFalse($report['operations']['checks']['worker:actions']);
    }
}
