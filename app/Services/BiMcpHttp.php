<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BiMcpHttp
{
    public function resource(): string
    {
        $url = (string) config('horus.mcp.resource');
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\\\\]/', $url) || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || filter_var($parts['host'], FILTER_VALIDATE_IP) || ! str_contains($parts['host'], '.') || isset($parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['port'] ?? 443) !== 443) || empty($parts['path']) || str_ends_with($parts['path'], '/')) {
            throw new RuntimeException('O endereço MCP desta instalação precisa ser configurado pelo administrador.');
        }

        return $url;
    }

    public function origin(): string
    {
        return 'https://'.parse_url($this->resource(), PHP_URL_HOST);
    }

    public function endpoint(string $url): string
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\\\\]/', $url) || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== parse_url($this->resource(), PHP_URL_HOST) || (($parts['port'] ?? 443) !== 443) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || empty($parts['path'])) {
            throw new RuntimeException('O BI informou um endereço MCP fora do domínio configurado.');
        }

        return $url;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function send(string $method, string $url, ?array $payload = null, bool $form = false, ?string $token = null, array $headers = [], int|string|null $rpcId = null): array
    {
        $this->endpoint($url);
        $stream = null;
        try {
            $request = Http::withoutRedirecting()->connectTimeout(5)->timeout(25)->withOptions(['stream' => true, 'read_timeout' => 10])->withHeaders(['Accept' => 'application/json, text/event-stream', ...$headers]);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            if ($form) {
                $request = $request->asForm();
            }
            $response = $request->send($method, $url, $payload === null ? [] : [$form ? 'form_params' : 'json' => $payload]);
            $contentType = strtolower($response->header('Content-Type'));
            $body = '';
            $stream = $response->toPsrResponse()->getBody();
            $deadline = microtime(true) + 25;
            if (! in_array($response->status(), [202, 204], true)) {
                while (! $stream->eof()) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('O BI não concluiu a resposta MCP. Tente novamente.');
                    }
                    $chunk = $stream->read(8192);
                    $body .= $chunk;
                    if (strlen($body) > 2_097_152) {
                        throw new RuntimeException('A resposta MCP excedeu o limite de leitura. Reduza os campos ou o período.');
                    }
                    if ($rpcId !== null && str_contains($contentType, 'text/event-stream') && $this->event($body, $rpcId) !== null) {
                        break;
                    }
                    if ($chunk === '' && ! $stream->eof()) {
                        throw new RuntimeException('O BI não concluiu a resposta MCP. Tente novamente.');
                    }
                }
            }

            return ['status' => $response->status(), 'headers' => ['content-type' => $contentType, 'mcp-session-id' => $response->header('Mcp-Session-Id')], 'body' => $body];
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'A resposta MCP') || str_starts_with($e->getMessage(), 'O BI não concluiu')) {
                throw $e;
            }
            throw new RuntimeException('Não foi possível acessar o MCP do G-Analise BI. Tente novamente.');
        } catch (Throwable) {
            throw new RuntimeException('Não foi possível acessar o MCP do G-Analise BI. Tente novamente.');
        } finally {
            $stream?->close();
        }
    }

    public function json(array $response): array
    {
        try {
            $data = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('O MCP retornou uma resposta em formato desconhecido.');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('O MCP retornou uma resposta em formato desconhecido.');
        }

        return $data;
    }

    public function rpc(array $response, int|string $id): array
    {
        $data = str_contains($response['headers']['content-type'], 'text/event-stream') ? $this->event($response['body'], $id) : $this->json($response);
        if (! is_array($data) || ($data['jsonrpc'] ?? '') !== '2.0' || ($data['id'] ?? null) !== $id || isset($data['error']) || ! is_array($data['result'] ?? null)) {
            throw new RuntimeException('O MCP não concluiu esta operação. Confira a autorização e tente novamente.');
        }

        return $data['result'];
    }

    private function event(string $body, int|string $id): ?array
    {
        $blocks = preg_split('/\r?\n\r?\n/', $body);
        array_pop($blocks);
        foreach ($blocks as $block) {
            $lines = [];
            foreach (preg_split('/\r?\n/', $block) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $lines[] = ltrim(substr($line, 5), ' ');
                }
            }
            $data = json_decode(implode("\n", $lines), true);
            if (is_array($data) && ($data['id'] ?? null) === $id) {
                return $data;
            }
        }

        return null;
    }
}
