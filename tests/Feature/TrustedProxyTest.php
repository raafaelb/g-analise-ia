<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    #[DataProvider('proxyRequests')]
    public function test_forwarded_address_and_scheme_are_accepted_only_from_explicit_valid_proxies(array $configured, string $remote, string $expectedIp, bool $expectedSecure): void
    {
        config(['operations.trusted_proxies' => $configured]);
        Route::get('/proxy-test', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure(), 'host' => $request->getHost(), 'path' => $request->getPathInfo()]));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $remote])->getJson('http://portal.example.test/proxy-test', [
            'X-Forwarded-For' => '198.51.100.42', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443',
            'X-Forwarded-Host' => 'injected.example.test', 'X-Forwarded-Prefix' => '/injected',
        ]);

        $response->assertOk()->assertExactJson(['ip' => $expectedIp, 'secure' => $expectedSecure, 'host' => 'portal.example.test', 'path' => '/proxy-test']);
    }

    public static function proxyRequests(): array
    {
        return [
            'explicit address' => [['192.0.2.8'], '192.0.2.8', '198.51.100.42', true],
            'explicit network' => [['192.0.2.0/24'], '192.0.2.8', '198.51.100.42', true],
            'IPv6 address' => [['2001:db8::8'], '2001:db8::8', '198.51.100.42', true],
            'untrusted sender' => [['192.0.2.9'], '192.0.2.8', '192.0.2.8', false],
            'no configuration' => [[], '192.0.2.8', '192.0.2.8', false],
            'wildcard refused' => [['*'], '192.0.2.8', '192.0.2.8', false],
            'IPv4 catchall refused' => [['0.0.0.0/0'], '192.0.2.8', '192.0.2.8', false],
            'IPv6 catchall refused' => [['::/0'], '2001:db8::8', '2001:db8::8', false],
            'invalid network refused' => [['192.0.2.0/33'], '192.0.2.8', '192.0.2.8', false],
            'remote addr alias refused' => [['REMOTE_ADDR'], '192.0.2.8', '192.0.2.8', false],
        ];
    }
}
