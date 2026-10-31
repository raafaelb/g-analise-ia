<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserGuideProgress;
use App\Services\LumoClient;
use App\Services\ProductGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductGuideTest extends TestCase
{
    use RefreshDatabase;

    private function start(User $user, string $page = 'home', string $mode = 'auto', ?string $instance = null): array
    {
        return $this->actingAs($user)->postJson('/api/guide/'.$page.'/start', ['mode' => $mode, 'instance_id' => $instance ?? (string) Str::uuid()])->assertOk()->json();
    }

    public function test_guide_and_progress_endpoints_require_authentication_and_initial_password_change(): void
    {
        $this->getJson('/api/guide')->assertUnauthorized();
        $this->postJson('/api/guide/home/start', [])->assertUnauthorized();
        $this->postJson('/api/guide/home/events', [])->assertUnauthorized();
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user)->getJson('/api/guide')->assertStatus(423)->assertJsonPath('requires_password_change', true);
        $this->postJson('/api/guide/account/start', ['mode' => 'auto', 'instance_id' => (string) Str::uuid()])->assertStatus(423);
        $this->assertDatabaseCount('user_guide_progress', 0);
    }

    public function test_reader_gets_product_concepts_without_administration_or_creation_steps_and_no_external_operations(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $lumo = $this->mock(LumoClient::class);
        $lumo->shouldNotReceive('initialize');
        $lumo->shouldNotReceive('run');
        $user = User::factory()->create(['role' => 'viewer']);
        $response = $this->actingAs($user)->getJson('/api/guide')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('pages.users');
        $this->assertContains('project', array_keys($response->json('pages')));
        $this->assertNotContains('home-request', array_column($response->json('pages.home.steps'), 'id'));
        $projects = collect($response->json('articles'))->firstWhere('id', 'projects');
        $this->assertNotNull($projects);
        $this->assertStringContainsString('O projeto não é a própria dashboard', $projects['body']);
        $this->assertNotContains('users-access', array_column($response->json('articles'), 'id'));
        $this->assertNotContains('financial-dashboard', array_column($response->json('articles'), 'id'));
        $this->assertNotContains('etl-clones', array_column($response->json('articles'), 'id'));
        $this->assertNotContains('etl-publications', array_column($response->json('articles'), 'id'));
        $this->assertNotContains('source-relationships', array_column($response->json('articles'), 'id'));
        $this->assertNotContains('catalog-source-relationships', array_column($response->json('pages.catalog.steps'), 'id'));
        $this->assertNotContains('catalog-edit', array_column($response->json('pages.catalog.steps'), 'id'));
        $this->assertSame([], $response->json('progress'));
        $this->assertDatabaseCount('user_guide_progress', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_administrator_sees_the_users_tour_and_article(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $data = $this->actingAs($user)->getJson('/api/guide')->assertOk()->json();
        $this->assertArrayHasKey('users', $data['pages']);
        $this->assertContains('users-access', array_column($data['articles'], 'id'));
        $this->assertTrue($this->start($user, 'users')['should_start']);
    }

    public function test_automatic_start_is_once_per_user_and_page_and_seen_is_recorded_only_after_display(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $first = $this->start($user);
        $this->assertTrue($first['should_start']);
        $this->assertNull($first['progress']['first_seen_at']);
        $this->assertSame(0, $first['progress']['replay_count']);
        $again = $this->start($user);
        $this->assertFalse($again['should_start']);
        $this->assertSame($first['progress']['last_instance_id'], $again['progress']['last_instance_id']);
        $this->postJson('/api/guide/home/events', ['instance_id' => $first['progress']['last_instance_id'], 'event' => 'seen', 'step_id' => 'home-intro'])->assertOk()->assertJsonPath('accepted', true);
        $this->assertSame(now()->toISOString(), UserGuideProgress::firstOrFail()->first_seen_at->toISOString());
        $this->assertTrue($this->start($user, 'catalog')['should_start']);
        $other = User::factory()->create();
        $this->assertTrue($this->start($other)['should_start']);
        $this->assertDatabaseCount('user_guide_progress', 3);
    }

    public function test_closing_a_tour_does_not_repeat_it_and_manual_replay_is_idempotent(): void
    {
        $user = User::factory()->create();
        $first = $this->start($user, 'project');
        $this->postJson('/api/guide/project/events', ['instance_id' => $first['progress']['last_instance_id'], 'event' => 'dismissed', 'step_id' => 'project-intro'])->assertOk()->assertJsonPath('progress.last_status', 'dismissed');
        $this->assertFalse($this->start($user, 'project')['should_start']);
        $instance = (string) Str::uuid();
        $replay = $this->start($user, 'project', 'manual', $instance);
        $retry = $this->start($user, 'project', 'manual', $instance);
        $this->assertTrue($replay['should_start']);
        $this->assertTrue($retry['should_start']);
        $this->assertSame(1, $retry['progress']['replay_count']);
        $this->assertSame($first['progress']['first_started_at'], $retry['progress']['first_started_at']);
        $this->postJson('/api/guide/project/events', ['instance_id' => $instance, 'event' => 'completed', 'step_id' => 'project-control'])->assertOk()->assertJsonPath('progress.last_status', 'completed');
        $this->postJson('/api/guide/project/events', ['instance_id' => $instance, 'event' => 'dismissed'])->assertOk()->assertJsonPath('accepted', false)->assertJsonPath('progress.last_status', 'completed');
        $this->assertFalse($this->start($user, 'project', 'manual', $instance)['should_start']);
        $this->assertDatabaseCount('user_guide_progress', 1);
    }

    public function test_old_tab_callbacks_cannot_overwrite_a_new_manual_replay(): void
    {
        $user = User::factory()->create();
        $first = $this->start($user, 'catalog');
        $new = $this->start($user, 'catalog', 'manual');
        $this->postJson('/api/guide/catalog/events', ['instance_id' => $first['progress']['last_instance_id'], 'event' => 'completed'])->assertOk()->assertJsonPath('accepted', false)->assertJsonPath('progress.last_instance_id', $new['progress']['last_instance_id'])->assertJsonPath('progress.last_status', 'started');
        $this->assertNull(UserGuideProgress::firstOrFail()->completed_at);
    }

    public function test_content_updates_do_not_repeat_the_automatic_tour_or_erase_first_version_and_dates(): void
    {
        $user = User::factory()->create();
        $first = $this->start($user);
        $version = 'next-version';
        $mock = $this->partialMock(ProductGuide::class);
        $mock->shouldReceive('page')->andReturn(['version' => $version, 'steps' => [['id' => 'home-new-step']]]);
        $this->assertFalse($this->start($user)['should_start']);
        $replay = $this->start($user, 'home', 'manual');
        $this->assertTrue($replay['should_start']);
        $this->assertSame($first['progress']['first_content_version'], $replay['progress']['first_content_version']);
        $this->assertSame('next-version', $replay['progress']['last_content_version']);
        $this->assertSame($first['progress']['first_started_at'], $replay['progress']['first_started_at']);
    }

    public function test_history_and_updates_are_scoped_to_the_authenticated_person(): void
    {
        $other = User::factory()->create();
        $foreign = UserGuideProgress::factory()->create(['user_id' => $other->id, 'page_key' => 'home']);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/guide')->assertOk()->assertJsonPath('progress', []);
        $this->postJson('/api/guide/home/events', ['instance_id' => $foreign->last_instance_id, 'event' => 'completed'])->assertNotFound();
        $this->postJson('/api/guide/home/start', ['mode' => 'auto', 'instance_id' => (string) Str::uuid(), 'user_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('guide');
        $this->assertSame('started', $foreign->fresh()->last_status);
        $this->assertDatabaseCount('user_guide_progress', 1);
    }

    public function test_unknown_or_forbidden_pages_cannot_create_progress(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $input = ['mode' => 'auto', 'instance_id' => (string) Str::uuid()];
        $this->actingAs($user)->postJson('/api/guide/not-a-page/start', $input)->assertNotFound();
        $this->postJson('/api/guide/users/start', $input)->assertForbidden();
        $this->assertDatabaseCount('user_guide_progress', 0);
    }

    #[DataProvider('invalidStarts')]
    public function test_invalid_start_requests_are_refused_without_writing_progress(array $input, string $field): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/guide/home/start', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('user_guide_progress', 0);
    }

    public static function invalidStarts(): array
    {
        return [
            [[], 'mode'],
            [['mode' => 'reset', 'instance_id' => '00000000-0000-4000-8000-000000000001'], 'mode'],
            [['mode' => 'auto', 'instance_id' => 'invalid'], 'instance_id'],
        ];
    }

    public function test_an_event_must_belong_to_the_current_page_and_known_instance(): void
    {
        $user = User::factory()->create();
        $first = $this->start($user);
        $id = $first['progress']['last_instance_id'];
        $this->postJson('/api/guide/home/events', ['instance_id' => $id, 'event' => 'seen', 'step_id' => 'catalog-tenant'])->assertUnprocessable()->assertJsonValidationErrors('step_id');
        $this->postJson('/api/guide/home/events', ['instance_id' => $id, 'event' => 'reset'])->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->postJson('/api/guide/home/events', ['instance_id' => $id, 'event' => 'completed', 'user_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('guide');
        $this->assertNull(UserGuideProgress::firstOrFail()->first_seen_at);
    }

    public function test_a_user_access_change_during_start_does_not_claim_the_tour(): void
    {
        $user = User::factory()->create();
        User::whereKey($user->id)->increment('access_version');
        $this->actingAs($user)->postJson('/api/guide/home/start', ['mode' => 'auto', 'instance_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('user_guide_progress', 0);
    }
}
