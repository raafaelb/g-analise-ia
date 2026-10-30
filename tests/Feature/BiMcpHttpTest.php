<?php

namespace Tests\Feature;

use App\Services\BiMcpHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BiMcpHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['horus.mcp.resource' => 'https://bi.example.test/mcp']);
    }

    public function test_streamed_rpc_ignores_notifications_and_other_ids_and_reads_multiline_data(): void
    {
        Http::preventStrayRequests();
        $events = ": heartbeat\r\n\r\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\"}\r\n\r\ndata: {\"jsonrpc\":\"2.0\",\"id\":9,\"result\":{\"wrong\":true}}\r\n\r\nevent: message\r\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\r\ndata: \"result\":{\"tools\":[]}}\r\n\r\n";
        Http::fake(['https://bi.example.test/mcp' => Http::response($events, 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'sse-test'])]);
        $client = app(BiMcpHttp::class);
        $response = $client->send('POST', 'https://bi.example.test/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], token: 'test-secret', rpcId: 2);

        $this->assertSame(['tools' => []], $client->rpc($response, 2));
        $this->assertSame('sse-test', $response['headers']['mcp-session-id']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-secret') && $r->hasHeader('Accept', 'application/json, text/event-stream'));
    }

    #[DataProvider('invalidRpc')]
    public function test_invalid_rpc_responses_never_return_data(string $body): void
    {
        $this->expectException(RuntimeException::class);
        app(BiMcpHttp::class)->rpc(['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => $body], 2);
    }

    public static function invalidRpc(): array
    {
        return [
            'html' => ['<html>provider failure</html>'],
            'wrong request id' => ['{"jsonrpc":"2.0","id":1,"result":{"secret":"discard"}}'],
            'rpc error' => ['{"jsonrpc":"2.0","id":2,"error":{"message":"do not expose"}}'],
            'wrong protocol' => ['{"jsonrpc":"1.0","id":2,"result":{}}'],
            'scalar result' => ['{"jsonrpc":"2.0","id":2,"result":"unexpected"}'],
        ];
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_credentials_cannot_be_sent_to_untrusted_endpoints(string $url): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/unused' => Http::response([])]);
        try {
            app(BiMcpHttp::class)->send('POST', $url, token: 'must-stay-private');
            $this->fail('O destino deveria ser recusado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('domínio configurado', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function unsafeEndpoints(): array
    {
        return [
            'http' => ['http://bi.example.test/mcp'],
            'foreign domain' => ['https://other.example.test/mcp'],
            'suffix' => ['https://bi.example.test.attacker.test/mcp'],
            'userinfo' => ['https://user:pass@bi.example.test/mcp'],
            'port' => ['https://bi.example.test:8443/mcp'],
            'query' => ['https://bi.example.test/mcp?token=secret'],
            'fragment' => ['https://bi.example.test/mcp#auth'],
            'backslash' => ['https://bi.example.test\\@other.example.test/mcp'],
        ];
    }

    public function test_redirects_are_returned_without_forwarding_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/mcp' => Http::response('', 302, ['Location' => 'https://other.example.test/leak'])]);

        $response = app(BiMcpHttp::class)->send('POST', 'https://bi.example.test/mcp', token: 'private-token');

        $this->assertSame(302, $response['status']);
        Http::assertSentCount(1);
    }

    public function test_excessively_large_stream_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/mcp' => Http::response(str_repeat('x', 2_097_153), 200)]);
        try {
            app(BiMcpHttp::class)->send('POST', 'https://bi.example.test/mcp');
            $this->fail('O limite de resposta deveria ser aplicado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('excedeu o limite', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_transport_errors_do_not_expose_credentials_or_internal_details(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://bi.example.test/mcp' => fn () => throw new ConnectionException('Bearer private-secret; provider stack trace')]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível acessar o MCP do G-Analise BI. Tente novamente.');
        app(BiMcpHttp::class)->send('POST', 'https://bi.example.test/mcp', token: 'private-secret');
    }
}
