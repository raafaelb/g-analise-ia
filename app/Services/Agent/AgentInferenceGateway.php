<?php

namespace App\Services\Agent;

use App\Services\OpenAiIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AgentInferenceGateway
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const FORBIDDEN_FIELDS = [
        'background', 'conversation', 'max_output_tokens', 'max_tool_calls', 'metadata',
        'moderation', 'multi_agent', 'prompt', 'prompt_cache_retention', 'safety_identifier',
        'temperature', 'top_logprobs', 'top_p', 'truncation', 'user', 'previous_response_id',
        'user_id', 'project_id', 'tenant_id', 'connection_id', 'api_key', 'access_token',
        'authorization', 'base_url', 'url', 'endpoint',
    ];

    public function __construct(private AgentInferenceGrants $grants, private OpenAiIdentity $identity) {}

    public function respond(#[SensitiveParameter] string $grant, #[SensitiveParameter] array $payload): Response
    {
        $this->validate($payload);
        $scope = $this->grants->claim($grant, $payload['model']);
        try {
            $accessToken = $this->identity->accessToken($scope['connection']);
        } catch (RuntimeException) {
            return $this->error(401);
        }
        $this->grants->active($grant);
        try {
            $upstream = Http::withoutRedirecting()
                ->withToken($accessToken)
                ->accept('text/event-stream')
                ->withHeaders(['originator' => 'g_analise_ia', 'User-Agent' => 'g_analise_ia/1.0'])
                ->connectTimeout(10)
                ->timeout((int) config('horus.codex.timeout', 900))
                ->withOptions(['stream' => true, 'read_timeout' => 15])
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            return $this->error(502);
        } finally {
            unset($accessToken);
        }
        $body = $upstream->toPsrResponse()->getBody();
        try {
            $this->grants->active($grant);
        } catch (Throwable $exception) {
            $body->close();
            throw $exception;
        }
        if (! $upstream->successful()) {
            $body->close();
            $status = in_array($upstream->status(), [401, 403, 429], true) ? $upstream->status() : 502;
            $retryAfter = $upstream->header('Retry-After');
            $headers = $status === 429 && is_string($retryAfter) && preg_match('/^[0-9]{1,4}$/D', $retryAfter) && (int) $retryAfter <= 3600 ? ['Retry-After' => $retryAfter] : [];

            return $this->error($status, $headers);
        }
        if (strtolower(trim(explode(';', $upstream->header('Content-Type') ?? '')[0])) !== 'text/event-stream') {
            $body->close();

            return $this->error(502);
        }

        return response()->stream(fn () => $this->forward($grant, $body), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function validate(#[SensitiveParameter] array $payload): void
    {
        abort_unless(
            is_string($payload['model'] ?? null) &&
            ($payload['stream'] ?? null) === true && ($payload['store'] ?? null) === false &&
            is_array($payload['input'] ?? null) && array_is_list($payload['input']) && $payload['input'] !== [] &&
            array_intersect(self::FORBIDDEN_FIELDS, array_keys($payload)) === [],
            422, 'A solicitação não corresponde ao fluxo de IA autorizado.'
        );
    }

    private function forward(#[SensitiveParameter] string $grant, StreamInterface $body): void
    {
        try {
            $this->grants->active($grant);
            $checkedAt = now()->timestamp;
            while (! $body->eof() && ! connection_aborted()) {
                if ($checkedAt !== now()->timestamp) {
                    $this->grants->active($grant);
                    $checkedAt = now()->timestamp;
                }
                $chunk = $body->read(32768);
                if ($checkedAt !== now()->timestamp) {
                    $this->grants->active($grant);
                    $checkedAt = now()->timestamp;
                }
                if ($chunk !== '') {
                    echo $chunk;
                    if (ob_get_level() > 0 && (ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE) !== 0) {
                        ob_flush();
                    }
                    flush();
                }
            }
        } catch (HttpExceptionInterface|RuntimeException) {
            // A revoked or interrupted stream must end without fabricating completion events.
        } finally {
            $body->close();
        }
    }

    private function error(int $status, array $headers = []): JsonResponse
    {
        [$code, $message] = match ($status) {
            401 => ['authentication_required', 'Conecte o ChatGPT novamente para continuar.'],
            403 => ['permission_denied', 'O provedor não autorizou esta solicitação.'],
            429 => ['rate_limit_exceeded', 'O limite disponível foi atingido. Tente novamente mais tarde.'],
            default => ['upstream_unavailable', 'Não foi possível concluir a comunicação com a IA.'],
        };

        return response()->json(['error' => ['type' => $code, 'code' => $code, 'message' => $message]], $status, [
            'Cache-Control' => 'no-store, private', ...$headers,
        ]);
    }
}
