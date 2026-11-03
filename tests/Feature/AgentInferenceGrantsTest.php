<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentInferenceGrants;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AgentInferenceGrantsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Ponte sintética', 'tenant_id' => '100', 'source_ref' => 'app:42']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Conversa sintética', 'model' => 'gpt-synthetic']);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running', 'started_at' => now(), 'heartbeat_at' => now()]);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'openai', 'subject' => 'synthetic-'.Str::uuid(), 'status' => 'connected', 'credentials' => ['access_token' => 'SYNTHETIC-REAL-GRANT-NEVER-FORWARDED'], 'metadata' => ['plan_usage' => true, 'session_generation' => (string) Str::uuid(), 'client_id' => 'synthetic-client', 'auth_mode' => 'hosted']]);

        return [$user, $project, $conversation, $run, $connection];
    }

    public function test_a_temporary_grant_resolves_only_its_task_and_never_exposes_the_real_oauth_credential(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        [$user, $project, , $run, $connection] = $this->context();
        $writes = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes): void {
            $writes[] = ['key' => $event->key, 'value' => $event->value];
        });
        $service = app(AgentInferenceGrants::class);

        $grant = $service->issue($run, 'gpt-synthetic');
        $scope = $service->claim($grant->authorization(), 'gpt-synthetic');

        $this->assertSame($run->id, $scope['run']->id);
        $this->assertSame($user->id, $scope['user']->id);
        $this->assertSame($project->id, $scope['project']->id);
        $this->assertSame($connection->id, $scope['connection']->id);
        $this->assertStringNotContainsString($grant->authorization(), json_encode($grant, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($grant->authorization(), print_r($grant, true));
        $this->assertStringNotContainsString($grant->authorization(), var_export($grant, true));
        $this->assertStringNotContainsString($grant->authorization(), json_encode($writes, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SYNTHETIC-REAL-GRANT-NEVER-FORWARDED', json_encode($writes, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_temporary_grants_cannot_be_serialized_into_jobs_or_persistent_storage(): void
    {
        [, , , $run] = $this->context();
        $grant = app(AgentInferenceGrants::class)->issue($run, 'gpt-synthetic');

        $this->expectException(LogicException::class);

        serialize($grant);
    }

    public function test_separate_users_receive_distinct_grants_bound_to_their_own_projects(): void
    {
        [$firstUser, $firstProject, , $firstRun] = $this->context();
        [$secondUser, $secondProject, , $secondRun] = $this->context();
        $service = app(AgentInferenceGrants::class);

        $first = $service->issue($firstRun, 'gpt-synthetic');
        $second = $service->issue($secondRun, 'gpt-synthetic');
        $firstScope = $service->claim($first->authorization(), 'gpt-synthetic');
        $secondScope = $service->claim($second->authorization(), 'gpt-synthetic');

        $this->assertNotSame($first->authorization(), $second->authorization());
        $this->assertSame($firstUser->id, $firstScope['user']->id);
        $this->assertSame($firstProject->id, $firstScope['project']->id);
        $this->assertSame($secondUser->id, $secondScope['user']->id);
        $this->assertSame($secondProject->id, $secondScope['project']->id);
    }

    public function test_rotating_a_task_grant_invalidates_the_previous_one_without_revoking_the_new_one(): void
    {
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $old = $service->issue($run, 'gpt-synthetic');
        $current = $service->issue($run, 'gpt-synthetic');
        $service->revoke($old);

        try {
            $service->claim($old->authorization(), 'gpt-synthetic');
            $this->fail('A rotated grant must no longer authorize requests.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        $this->assertSame($run->id, $service->claim($current->authorization(), 'gpt-synthetic')['run']->id);
    }

    public function test_expired_and_revoked_grants_cannot_authorize_another_request(): void
    {
        $this->freezeTime();
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $revoked = $service->issue($run, 'gpt-synthetic');
        $service->revoke($revoked);
        $this->assertDenied($service, $revoked->authorization(), 401);
        $expiring = $service->issue($run, 'gpt-synthetic');
        $this->travel(16)->minutes();

        $this->assertDenied($service, $expiring->authorization(), 401);
    }

    public function test_another_model_is_rejected_without_consuming_the_task_request_budget(): void
    {
        config(['horus.codex.gateway_max_requests' => 1]);
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');

        try {
            $service->claim($grant->authorization(), 'another-model');
            $this->fail('The task grant must not authorize a different model.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame($run->id, $service->claim($grant->authorization(), 'gpt-synthetic')['run']->id);
        $this->assertDenied($service, $grant->authorization(), 429);
    }

    public function test_a_request_budget_prevents_further_model_requests(): void
    {
        config(['horus.codex.gateway_max_requests' => 2]);
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');
        $service->claim($grant->authorization(), 'gpt-synthetic');
        $service->claim($grant->authorization(), 'gpt-synthetic');

        $this->assertDenied($service, $grant->authorization(), 429);
    }

    public function test_a_tampered_cache_record_cannot_rebind_a_grant_to_another_project(): void
    {
        [, $project, , $run] = $this->context();
        [, $otherProject] = $this->context();
        $record = null;
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$record): void {
            if ($record === null && is_array($event->value)) {
                $record = ['key' => $event->key, 'value' => $event->value];
            }
        });
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');
        $this->assertNotNull($record);
        $this->assertSame($project->id, $record['value']['scope']['project_id']);
        $record['value']['scope']['project_id'] = $otherProject->id;
        Cache::put($record['key'], $record['value'], 930);

        $this->assertDenied($service, $grant->authorization(), 401);
    }

    public function test_rotating_a_grant_does_not_reset_the_same_attempt_request_budget(): void
    {
        config(['horus.codex.gateway_max_requests' => 1]);
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $first = $service->issue($run, 'gpt-synthetic');
        $service->claim($first->authorization(), 'gpt-synthetic');

        $rotated = $service->issue($run, 'gpt-synthetic');

        $this->assertDenied($service, $rotated->authorization(), 429);
    }

    public function test_a_request_overlapping_grant_rotation_is_refused_instead_of_losing_its_budget_charge(): void
    {
        config(['horus.codex.gateway_max_requests' => 1]);
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $first = $service->issue($run, 'gpt-synthetic');
        $armed = true;
        $overlappingStatus = null;
        Event::listen(CacheHit::class, function (CacheHit $event) use (&$armed, &$overlappingStatus, $service, $first): void {
            if (! $armed || ! is_array($event->value) || ! isset($event->value['scope'], $event->value['requests'])) {
                return;
            }
            $armed = false;
            try {
                $service->claim($first->authorization(), 'gpt-synthetic');
                $overlappingStatus = 200;
            } catch (HttpExceptionInterface $exception) {
                $overlappingStatus = $exception->getStatusCode();
            }
        });

        $rotated = $service->issue($run, 'gpt-synthetic');

        $this->assertSame(409, $overlappingStatus);
        $this->assertSame($run->id, $service->claim($rotated->authorization(), 'gpt-synthetic')['run']->id);
        $this->assertDenied($service, $rotated->authorization(), 429);
    }

    public function test_revoking_and_reissuing_does_not_reset_the_same_attempt_request_budget(): void
    {
        config(['horus.codex.gateway_max_requests' => 1]);
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $first = $service->issue($run, 'gpt-synthetic');
        $service->claim($first->authorization(), 'gpt-synthetic');
        $service->revoke($first);
        $service->revoke($first);

        $reissued = $service->issue($run, 'gpt-synthetic');

        $this->assertDenied($service, $reissued->authorization(), 429);
    }

    public function test_late_issue_cannot_extend_the_original_attempt_deadline(): void
    {
        $this->freezeTime();
        [, , , $run] = $this->context();
        $this->travel(10)->minutes();
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');
        $this->travel(6)->minutes();

        $this->assertDenied($service, $grant->authorization(), 401);
    }

    public function test_copying_a_signed_record_to_another_grant_does_not_transfer_its_authority(): void
    {
        [, , , $firstRun] = $this->context();
        [, , , $secondRun] = $this->context();
        $records = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$records): void {
            if (is_array($event->value)) {
                $records[] = ['key' => $event->key, 'value' => $event->value];
            }
        });
        $service = app(AgentInferenceGrants::class);
        $first = $service->issue($firstRun, 'gpt-synthetic');
        $second = $service->issue($secondRun, 'gpt-synthetic');
        $this->assertCount(2, $records);
        Cache::put($records[1]['key'], $records[0]['value'], 930);

        $this->assertDenied($service, $second->authorization(), 401);
        $this->assertSame($firstRun->id, $service->claim($first->authorization(), 'gpt-synthetic')['run']->id);
    }

    public function test_waiting_for_human_review_still_allows_the_same_turn_to_finish_its_explanation(): void
    {
        [, , , $run] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');
        $run->update(['status' => 'awaiting_approval']);

        $this->assertSame($run->id, $service->claim($grant->authorization(), 'gpt-synthetic')['run']->id);
    }

    public static function invalidLiveScopes(): array
    {
        return array_map(fn (string $case): array => [$case], array_combine(
            $cases = ['completed', 'cancel_requested', 'queued', 'inactive', 'viewer', 'password_change', 'access_version', 'archived', 'tenant_changed', 'project_owner', 'conversation_owner', 'run_owner', 'disconnected', 'generation_changed', 'plan_revoked', 'provider_changed', 'restarted'], $cases));
    }

    #[DataProvider('invalidLiveScopes')]
    public function test_scope_or_access_changes_prevent_reusing_a_grant(string $change): void
    {
        [$user, $project, $conversation, $run, $connection] = $this->context();
        $service = app(AgentInferenceGrants::class);
        $grant = $service->issue($run, 'gpt-synthetic');
        $other = User::factory()->create();
        match ($change) {
            'completed', 'cancel_requested', 'queued' => $run->update(['status' => $change]),
            'inactive' => $user->forceFill(['active' => false])->save(),
            'viewer' => $user->forceFill(['role' => 'viewer'])->save(),
            'password_change' => $user->forceFill(['must_change_password' => true])->save(),
            'access_version' => $user->forceFill(['access_version' => 1])->save(),
            'archived' => $project->update(['status' => 'archived']),
            'tenant_changed' => $project->update(['tenant_id' => '101']),
            'project_owner' => $project->update(['user_id' => $other->id]),
            'conversation_owner' => $conversation->update(['user_id' => $other->id]),
            'run_owner' => $run->update(['user_id' => $other->id]),
            'disconnected' => $connection->update(['status' => 'disconnected']),
            'generation_changed' => $connection->update(['metadata' => [...$connection->metadata, 'session_generation' => (string) Str::uuid()]]),
            'plan_revoked' => $connection->update(['metadata' => [...$connection->metadata, 'plan_usage' => false]]),
            'provider_changed' => $connection->update(['provider' => 'horus']),
            'restarted' => $run->update(['started_at' => now()->addSecond()]),
        };

        $this->assertDenied($service, $grant->authorization(), 403);
    }

    private function assertDenied(AgentInferenceGrants $service, string $token, int $status): void
    {
        try {
            $service->claim($token, 'gpt-synthetic');
            $this->fail('The temporary grant must not authorize this request.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertStringNotContainsString($token, $exception->getMessage());
        }
    }
}
