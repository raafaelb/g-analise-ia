<?php

namespace Tests\Feature;

use App\Jobs\AnswerHelpQuestion;
use App\Models\Connection;
use App\Models\HelpQuestion;
use App\Models\User;
use App\Services\Agent\ProductHelpRuntime;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductHelpRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function question(string $role = 'editor'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-help-oauth'], 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid()]]);
        $question = HelpQuestion::factory()->create(['user_id' => $user->id, 'connection_id' => $connection->id, 'access_version' => $user->access_version, 'connection_generation' => $connection->metadata['session_generation']]);
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('configured')->andReturnTrue();
        $identity->shouldReceive('accessToken')->once()->withArgs(fn (Connection $value): bool => $value->id === $connection->id)->andReturn('synthetic-help-oauth');

        return [$question, $user, $connection];
    }

    private function event(array $value): string
    {
        return 'data: '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\r\n\r\n";
    }

    private function completed(string $text = 'Um projeto organiza objetivo, conversas e arquivos.'): string
    {
        return $this->event(['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]]]);
    }

    private function fake(string $stream, int $status = 200, string $contentType = 'text/event-stream'): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'synthetic-hidden', 'visibility' => 'hide'], ['slug' => 'synthetic-plan-model', 'visibility' => 'list'], ['slug' => 'synthetic-other-model', 'visibility' => 'list']]]),
            'https://api.openai.com/v1/responses' => Http::response($stream, $status, ['Content-Type' => $contentType]),
        ]);
    }

    private function answer(HelpQuestion $question): void
    {
        (new AnswerHelpQuestion($question->id))->handle(app(ProductHelpRuntime::class));
    }

    public function test_help_stream_completes_with_the_accounts_visible_model_and_without_bi_tools(): void
    {
        [$question] = $this->question();
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldNotReceive('run');
        $stream = ':'.str_repeat('x', 8183)."\r\n\r\n";
        $stream .= "event: response.output_text.delta\r\ndata: {\"type\":\"response.output_text.delta\",\r\ndata: \"delta\":\"Um projeto organiza\",\"output_index\":0,\"content_index\":0}\r\n\r\n";
        $stream .= $this->event(['type' => 'response.output_text.delta', 'delta' => ' objetivo, conversas e arquivos.']);
        $stream .= $this->completed();
        $this->fake($stream);

        $this->answer($question);
        $this->answer($question);

        $this->assertSame('completed', $question->fresh()->status);
        $this->assertSame('Um projeto organiza objetivo, conversas e arquivos.', $question->fresh()->answer);
        $this->assertSame('synthetic-plan-model', $question->fresh()->model);
        $this->assertNotNull($question->fresh()->finished_at);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses' && $request->hasHeader('Authorization', 'Bearer synthetic-help-oauth') && $request['model'] === 'synthetic-plan-model' && $request['store'] === false && $request['stream'] === true && $request['tools'] === [] && $request['tool_choice'] === 'none' && ! isset($request['max_output_tokens']) && ! isset($request['previous_response_id']) && str_contains($request['instructions'], 'não cria recursos');
        });
        Http::assertSentCount(2);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_the_answer_uses_current_role_filtered_guides_and_only_own_same_authorization_history(): void
    {
        [$question, $user, $connection] = $this->question('viewer');
        HelpQuestion::factory()->create(['user_id' => $user->id, 'connection_generation' => $connection->metadata['session_generation'], 'access_version' => $user->access_version, 'status' => 'completed', 'question' => 'OWN-HELP-QUESTION', 'answer' => 'OWN-HELP-ANSWER', 'created_at' => $question->created_at->subSecond()]);
        HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'answer' => 'OLD-AUTHORIZATION-ANSWER']);
        HelpQuestion::factory()->create(['status' => 'completed', 'answer' => 'FOREIGN-HELP-ANSWER']);
        $this->fake($this->completed());

        $this->answer($question);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }
            $text = $request['input'][0]['content'];

            return str_contains($text, 'Perfil atual: viewer') && str_contains($text, 'Para que servem os projetos?') && str_contains($text, 'OWN-HELP-ANSWER') && ! str_contains($text, 'OLD-AUTHORIZATION-ANSWER') && ! str_contains($text, 'FOREIGN-HELP-ANSWER') && ! str_contains($text, 'Como disponibilizo acesso a outra pessoa?') && ! str_contains($text, 'Como adiciono uma nova conexão de dados?');
        });
        $this->assertSame('completed', $question->fresh()->status);
    }

    #[DataProvider('incompleteStreams')]
    public function test_an_unconfirmed_or_unsafe_stream_never_becomes_a_completed_answer(string $stream): void
    {
        [$question] = $this->question();
        $this->fake($stream);

        $this->answer($question);

        $this->assertSame('failed', $question->fresh()->status);
        $this->assertStringContainsString('guias continuam disponíveis', $question->fresh()->error_message);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('approvals', 0);
        Http::assertSentCount(2);
    }

    public static function incompleteStreams(): array
    {
        return [
            'eof without completion' => ["data: {\"type\":\"response.output_text.delta\",\"delta\":\"Parcial\"}\n\ndata: [DONE]\n\n"],
            'incomplete' => ["data: {\"type\":\"response.incomplete\",\"response\":{\"status\":\"incomplete\"}}\n\n"],
            'failed' => ["data: {\"type\":\"response.failed\",\"response\":{\"status\":\"failed\"}}\n\n"],
            'invalid json' => ["data: invalid-json\n\n"],
            'tool item' => ["data: {\"type\":\"response.output_item.added\",\"item\":{\"type\":\"function_call\",\"name\":\"delete_all\"}}\n\n"],
            'tool final' => ["data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[{\"type\":\"custom_tool_call\",\"name\":\"delete_all\"}]}}\n\n"],
            'empty completed' => ["data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[]}}\n\n"],
            'text size' => ['data: {"type":"response.output_text.delta","delta":"'.str_repeat('a', 64001).'"}'."\n\n"],
        ];
    }

    #[DataProvider('changedContexts')]
    public function test_late_provider_results_cannot_overwrite_cancelled_cleared_or_revoked_help(string $change, ?string $expectedStatus): void
    {
        [$question, $user, $connection] = $this->question();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'synthetic-plan-model']]]),
            'https://api.openai.com/v1/responses' => function () use ($change, $question, $user, $connection) {
                match ($change) {
                    'cancel' => $question->update(['status' => 'cancelled']),
                    'clear' => $question->delete(),
                    'disconnect' => $connection->update(['status' => 'disconnected']),
                    'new_grant' => $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]),
                    'access' => $user->forceFill(['access_version' => $user->access_version + 1])->save(),
                    'password' => $user->forceFill(['must_change_password' => true])->save(),
                };

                return Http::response($this->completed('LATE-PROVIDER-ANSWER'), 200, ['Content-Type' => 'text/event-stream']);
            },
        ]);

        $this->answer($question);

        $current = HelpQuestion::find($question->id);
        $this->assertSame($expectedStatus, $current?->status);
        $this->assertNull($current?->answer);
        Http::assertSentCount(2);
    }

    public static function changedContexts(): array
    {
        return [['cancel', 'cancelled'], ['clear', null], ['disconnect', 'failed'], ['new_grant', 'failed'], ['access', 'failed'], ['password', 'failed']];
    }

    public function test_completed_streamed_text_is_valid_when_the_completion_omits_a_redundant_output_copy(): void
    {
        [$question] = $this->question();
        $stream = $this->event(['type' => 'response.output_text.delta', 'delta' => 'Orientação recebida em partes.']);
        $stream .= $this->event(['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]]);
        $this->fake($stream);

        $this->answer($question);

        $this->assertSame('completed', $question->fresh()->status);
        $this->assertSame('Orientação recebida em partes.', $question->fresh()->answer);
        Http::assertSentCount(2);
    }

    public function test_a_valid_event_stream_does_not_depend_on_the_gateways_mime_header(): void
    {
        [$question] = $this->question();
        $this->fake($this->completed('Orientação confirmada.'), contentType: 'application/json');

        $this->answer($question);

        $this->assertSame('completed', $question->fresh()->status);
        $this->assertSame('Orientação confirmada.', $question->fresh()->answer);
        Http::assertSentCount(2);
    }

    public function test_successful_http_without_a_confirmed_event_stream_is_still_a_failed_answer(): void
    {
        [$question] = $this->question();
        $this->fake('{"status":"completed","text":"NOT-A-STREAM"}', contentType: 'application/json');

        $this->answer($question);

        $this->assertSame('failed', $question->fresh()->status);
        $this->assertNull($question->fresh()->answer);
        Http::assertSentCount(2);
    }

    public function test_an_account_without_visible_models_does_not_start_inference(): void
    {
        [$question] = $this->question();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'synthetic-hidden', 'visibility' => 'hide']]])]);

        $this->answer($question);

        $this->assertSame('failed', $question->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_a_provider_limit_error_is_not_retried_and_does_not_expose_raw_secrets(): void
    {
        [$question] = $this->question();
        $this->fake('{"error":{"message":"synthetic-private-provider-detail"}}', 429, 'application/json');

        $this->answer($question);

        $this->assertSame('failed', $question->fresh()->status);
        $this->assertStringNotContainsString('synthetic-private-provider-detail', $question->fresh()->error_message);
        $this->assertNull($question->fresh()->answer);
        Http::assertSentCount(2);
    }
}
