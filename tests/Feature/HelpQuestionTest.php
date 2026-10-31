<?php

namespace Tests\Feature;

use App\Jobs\AnswerHelpQuestion;
use App\Models\Connection;
use App\Models\HelpQuestion;
use App\Models\User;
use App\Services\Agent\ProductHelpRuntime;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use App\Services\UserAccessRevocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HelpQuestionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'editor', bool $configured = true): array
    {
        $user = User::factory()->create(['role' => $role]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-plan-token'], 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid()]]);
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('configured')->andReturn($configured);

        return [$user, $connection, $identity];
    }

    private function input(array $overrides = []): array
    {
        return [...['question' => 'Para que servem os projetos no G-Analise IA?', 'page' => 'home', 'request_id' => (string) Str::uuid()], ...$overrides];
    }

    public function test_help_endpoints_require_authentication_and_first_password_change(): void
    {
        $this->getJson('/api/help/questions')->assertUnauthorized();
        $this->postJson('/api/help/questions', $this->input())->assertUnauthorized();
        $this->deleteJson('/api/help/questions')->assertUnauthorized();
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user)->getJson('/api/help/questions')->assertStatus(423);
        $this->postJson('/api/help/questions', $this->input())->assertStatus(423);
        $this->assertDatabaseCount('help_questions', 0);
    }

    public function test_reading_history_does_not_require_ai_and_never_exposes_other_users_or_authorization_fields(): void
    {
        Http::preventStrayRequests();
        [$user, , $identity] = $this->actor(configured: false);
        $identity->shouldNotReceive('accessToken');
        $own = HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'answer' => 'Resposta própria']);
        HelpQuestion::factory()->create(['question' => 'FOREIGN-HELP-CONTENT']);
        $response = $this->actingAs($user)->getJson('/api/help/questions')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'questions')->assertJsonPath('questions.0.id', $own->id)->assertJsonPath('connected', false);
        $this->assertStringNotContainsString('FOREIGN-HELP-CONTENT', $response->getContent());
        $response->assertJsonMissingPath('questions.0.connection_id')->assertJsonMissingPath('questions.0.connection_generation')->assertJsonMissingPath('questions.0.request_hash');
        Http::assertNothingSent();
    }

    public function test_a_valid_help_question_queues_only_the_product_help_job_and_no_bi_operation(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$user] = $this->actor();
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldNotReceive('run');
        $lumo->shouldNotReceive('initialize');
        $input = $this->input();
        $response = $this->actingAs($user)->postJson('/api/help/questions', $input)->assertCreated()->assertJsonPath('reused', false)->assertJsonPath('question.status', 'queued');
        $question = HelpQuestion::findOrFail($response->json('question.id'));
        $this->assertSame($user->id, $question->user_id);
        $this->assertSame($input['question'], $question->question);
        Queue::assertPushed(AnswerHelpQuestion::class, fn ($job): bool => $job->questionId === $question->id && $job->queue === 'help');
        Queue::assertPushed(AnswerHelpQuestion::class, 1);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('projects', 0);
        Http::assertNothingSent();
    }

    public function test_resending_the_same_request_does_not_bill_another_turn_and_different_content_is_rejected(): void
    {
        Queue::fake();
        [$user] = $this->actor();
        $input = $this->input();
        $first = $this->actingAs($user)->postJson('/api/help/questions', $input)->assertCreated()->json('question.id');
        $this->postJson('/api/help/questions', $input)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('question.id', $first);
        $this->postJson('/api/help/questions', [...$input, 'question' => 'Outra pergunta'])->assertConflict();
        $this->assertDatabaseCount('help_questions', 1);
        Queue::assertPushed(AnswerHelpQuestion::class, 1);
    }

    public function test_only_one_question_per_user_waits_for_a_reply_until_it_is_cancelled(): void
    {
        Queue::fake();
        [$user] = $this->actor();
        $first = $this->actingAs($user)->postJson('/api/help/questions', $this->input())->assertCreated()->json('question.id');
        $this->postJson('/api/help/questions', $this->input())->assertConflict();
        $this->postJson('/api/help/questions/'.$first.'/cancel')->assertOk()->assertJsonPath('question.status', 'cancelled');
        $this->postJson('/api/help/questions', $this->input())->assertCreated();
        Queue::assertPushed(AnswerHelpQuestion::class, 2);
    }

    public function test_readers_can_ask_product_questions_without_gaining_bi_write_permissions(): void
    {
        Queue::fake();
        [$user] = $this->actor('viewer');
        $this->actingAs($user)->postJson('/api/help/questions', $this->input())->assertCreated();
        $this->postJson('/api/help/questions', $this->input(['page' => 'users']))->assertForbidden();
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public function test_ai_unavailable_or_not_authorized_leaves_guides_available_and_does_not_queue_a_turn(): void
    {
        Queue::fake();
        [$user, $connection] = $this->actor(configured: false);
        $this->actingAs($user)->postJson('/api/help/questions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('question');
        $this->getJson('/api/guide')->assertOk()->assertJsonStructure(['articles', 'pages']);
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('configured')->andReturnTrue();
        $connection->update(['metadata' => ['plan_usage' => false]]);
        $this->postJson('/api/help/questions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('help_questions', 0);
        Queue::assertNothingPushed();
    }

    public function test_existing_authorizations_receive_a_generation_without_exposing_or_replacing_their_tokens(): void
    {
        Queue::fake();
        [$user, $connection] = $this->actor();
        $connection->update(['metadata' => ['plan_usage' => true, 'issuer' => 'synthetic-issuer']]);
        $response = $this->actingAs($user)->postJson('/api/help/questions', $this->input())->assertCreated();
        $this->assertTrue(Str::isUuid($connection->fresh()->metadata['session_generation']));
        $this->assertSame('synthetic-issuer', $connection->fresh()->metadata['issuer']);
        $this->assertSame('synthetic-plan-token', $connection->fresh()->credentials['access_token']);
        $this->assertStringNotContainsString('synthetic-plan-token', $response->getContent());
    }

    public function test_cross_user_cancel_and_clear_cannot_touch_another_persons_history(): void
    {
        [$user] = $this->actor();
        $own = HelpQuestion::factory()->create(['user_id' => $user->id]);
        $foreign = HelpQuestion::factory()->create();
        $this->actingAs($user)->postJson('/api/help/questions/'.$foreign->id.'/cancel')->assertNotFound();
        $this->deleteJson('/api/help/questions')->assertOk()->assertJsonPath('cleared', true);
        $this->assertNull($own->fresh());
        $this->assertSame('queued', $foreign->fresh()->status);
        $runtime = $this->mock(ProductHelpRuntime::class);
        $runtime->shouldNotReceive('run');
        (new AnswerHelpQuestion($own->id))->handle($runtime);
        $this->assertDatabaseCount('help_questions', 1);
    }

    public function test_cancel_does_not_change_a_completed_answer(): void
    {
        [$user] = $this->actor();
        $question = HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'answer' => 'Resposta concluída']);
        $this->actingAs($user)->postJson('/api/help/questions/'.$question->id.'/cancel')->assertOk()->assertJsonPath('question.status', 'completed')->assertJsonPath('question.answer', 'Resposta concluída');
    }

    public function test_disconnect_chatgpt_and_access_revocation_cancel_product_help_but_bi_disconnect_does_not(): void
    {
        [$user, , $identity] = $this->actor();
        $question = HelpQuestion::factory()->create(['user_id' => $user->id]);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'metadata' => []]);
        $this->actingAs($user)->deleteJson('/api/connections/horus')->assertOk();
        $this->assertSame('queued', $question->fresh()->status);
        $identity->shouldReceive('revoke')->once()->andReturnTrue();
        $this->deleteJson('/api/connections/openai')->assertOk();
        $this->assertSame('cancelled', $question->fresh()->status);
        $another = HelpQuestion::factory()->create(['user_id' => $user->id]);
        app(UserAccessRevocation::class)->revoke($user);
        $this->assertSame('cancelled', $another->fresh()->status);
    }

    public function test_active_product_help_prevents_a_snapshot_and_stale_help_is_not_automatically_replayed(): void
    {
        [$user] = $this->actor();
        $question = HelpQuestion::factory()->create(['user_id' => $user->id, 'created_at' => now()->subMinutes(11)]);
        $this->assertSame(2, Artisan::call('ganalise:backup-state'));
        $snapshot = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $snapshot['active_help']);
        $this->assertSame(1, $snapshot['help_questions']);
        $this->assertSame(0, $snapshot['guide_progress']);
        $this->actingAs($user)->getJson('/api/help/questions')->assertOk()->assertJsonPath('questions.0.status', 'failed');
        $this->assertSame('failed', $question->fresh()->status);
        $this->assertSame(0, Artisan::call('ganalise:backup-state'));
    }

    public function test_a_running_question_without_a_recent_heartbeat_expires_without_touching_fresh_or_completed_help(): void
    {
        $this->freezeTime();
        [$user] = $this->actor();
        $stale = HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'running', 'heartbeat_at' => null, 'created_at' => now()->subMinutes(5)]);
        $fresh = HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'running', 'heartbeat_at' => now(), 'created_at' => now()->subMinutes(6)]);
        $complete = HelpQuestion::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'created_at' => now()->subDay()]);

        $this->actingAs($user)->getJson('/api/help/questions')->assertOk();

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('completed', $complete->fresh()->status);
    }

    #[DataProvider('invalidQuestions')]
    public function test_invalid_or_forged_help_input_does_not_create_a_question(array $overrides, string $field): void
    {
        Queue::fake();
        [$user] = $this->actor();
        $this->actingAs($user)->postJson('/api/help/questions', $this->input($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('help_questions', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidQuestions(): array
    {
        return [[['question' => ''], 'question'], [['question' => str_repeat('a', 4001)], 'question'], [['request_id' => 'invalid'], 'request_id'], [['user_id' => 999], 'question']];
    }
}
