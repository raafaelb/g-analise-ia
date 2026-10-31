<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedAction;
use App\Jobs\RunAgentTurn;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\Agent\CodexRuntime;
use App\Services\BiCredentialDrafts;
use App\Services\LumoClient;
use App\Services\OpenAiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $changes = []): array
    {
        return [...['name' => 'Pessoa autorizada', 'email' => 'person@example.invalid', 'role' => 'editor', 'password' => 'Synthetic-Initial-2026', 'password_confirmation' => 'Synthetic-Initial-2026'], ...$changes];
    }

    private function updateInput(User $user, array $changes = []): array
    {
        return [...$user->only(['name', 'email', 'role', 'active']), 'version' => (string) $user->access_revision, ...$changes];
    }

    public function test_a_stale_workspace_tab_cannot_use_another_accounts_session_or_log_the_new_account_out(): void
    {
        $previous = User::factory()->create();
        $current = User::factory()->create();
        Queue::fake();

        $this->actingAs($current)->withHeader('X-GAnalise-User', (string) $previous->id)->getJson('/api/bootstrap')->assertUnauthorized();
        $this->postJson('/api/help/questions', ['question' => 'Contexto da aba antiga', 'page' => 'home', 'request_id' => '00000000-0000-4000-8000-000000000001'])->assertUnauthorized();

        $this->assertAuthenticatedAs($current);
        $this->assertDatabaseCount('help_questions', 0);
        Queue::assertNothingPushed();
        $this->withHeader('X-GAnalise-User', (string) $current->id)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $current->id);
        $this->withHeader('X-GAnalise-User', (string) $previous->id)->getJson('/api/guide')->assertUnauthorized();
        $this->withoutHeader('X-GAnalise-User')->getJson('/api/guide')->assertOk();
    }

    public function test_admin_can_create_local_access_without_disclosing_password_hashes_or_provider_identifiers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/users', $this->input())->assertCreated()->assertJsonPath('user.auth_provider', 'local')->assertJsonPath('user.must_change_password', true)->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.suite_subject');

        $user = User::findOrFail($response->json('user.id'));
        $this->assertTrue(Hash::check('Synthetic-Initial-2026', $user->password));
        $this->assertTrue($user->active);
        $this->assertTrue($user->local_login_enabled);
        $this->assertSame('editor', $user->role);
        $this->assertStringNotContainsString('Synthetic-Initial-2026', $response->getContent());
        $this->assertDatabaseHas('audit_events', ['user_id' => $admin->id, 'action' => 'users.created']);
        $this->getJson('/api/users')->assertOk()->assertJsonCount(2, 'users')->assertJsonMissingPath('users.0.password')->assertJsonMissingPath('users.0.remember_token');
    }

    public static function nonAdministrativeRoles(): array
    {
        return [['editor'], ['viewer']];
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_non_admins_cannot_list_or_create_users(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', $this->input())->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_and_inactive_users_cannot_access_user_administration(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $user = User::factory()->create(['role' => 'admin', 'active' => false]);

        $this->actingAs($user)->getJson('/api/users')->assertUnauthorized();
    }

    public function test_local_user_creation_refuses_owner_role_and_case_insensitive_duplicate_email(): void
    {
        $admin = User::factory()->create(['role' => 'owner']);
        User::factory()->create(['email' => 'Person@example.invalid']);

        $this->actingAs($admin)->postJson('/api/users', $this->input(['email' => 'new@example.invalid', 'role' => 'owner']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/users', $this->input())->assertUnprocessable();

        $this->assertDatabaseCount('users', 2);
    }

    public function test_inactivation_revokes_sessions_and_pending_work_only_for_the_selected_user(): void
    {
        $admin = User::factory()->create(['role' => 'owner']);
        $target = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create();
        foreach ([$target, $other] as $user) {
            DB::table('sessions')->insert(['id' => 'synthetic-session-'.$user->id, 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        }
        $approval = Approval::create(['user_id' => $target->id, 'project_id' => null, 'type' => 'manual', 'title' => 'Teste privado', 'status' => 'approved', 'payload' => ['action' => ['action' => 'create_credential']], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);
        Cache::put(app(BiCredentialDrafts::class)->key($approval), 'synthetic-encrypted-input', 600);
        $project = Project::create(['user_id' => $target->id, 'name' => 'Projeto de teste']);
        $conversation = Conversation::create(['user_id' => $target->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $target->id, 'conversation_id' => $conversation->id, 'status' => 'queued']);

        $this->actingAs($admin)->patchJson('/api/users/'.$target->id, $this->updateInput($target, ['active' => false]))->assertOk()->assertJsonPath('user.active', false);

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull(Cache::get(app(BiCredentialDrafts::class)->key($approval)));
        $this->assertSame(1, $target->fresh()->access_version);
        $this->assertTrue($other->fresh()->active);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_stale_changes_cannot_overwrite_a_newer_access_revision(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();
        $input = $this->updateInput($target, ['name' => 'Primeira revisão']);
        $this->actingAs($admin)->patchJson('/api/users/'.$target->id, $input)->assertOk();

        $this->patchJson('/api/users/'.$target->id, [...$input, 'name' => 'Revisão antiga'])->assertConflict();

        $this->assertSame('Primeira revisão', $target->fresh()->name);
    }

    public function test_an_admin_cannot_disable_or_demote_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patchJson('/api/users/'.$admin->id, $this->updateInput($admin, ['active' => false]))->assertUnprocessable();
        $this->patchJson('/api/users/'.$admin->id, $this->updateInput($admin, ['role' => 'viewer']))->assertUnprocessable();

        $this->assertTrue($admin->fresh()->active);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_regular_admin_cannot_modify_the_principal_administrator(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($admin)->patchJson('/api/users/'.$owner->id, $this->updateInput($owner, ['active' => false]))->assertForbidden();

        $this->assertTrue($owner->fresh()->active);
    }

    public function test_suite_identity_role_and_password_are_immutable_in_the_local_user_form(): void
    {
        $admin = User::factory()->create(['role' => 'owner']);
        $employee = User::factory()->create(['name' => str_repeat('A', 150), 'role' => 'admin', 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => 'f38b1e22-3b0b-4b1d-92e0-643fbefb753e', 'local_login_enabled' => false]);

        $this->actingAs($admin)->patchJson('/api/users/'.$employee->id, $this->updateInput($employee, ['role' => 'viewer']))->assertUnprocessable();
        $this->patchJson('/api/users/'.$employee->id, $this->updateInput($employee, ['password' => 'Synthetic-Reset-2026', 'password_confirmation' => 'Synthetic-Reset-2026']))->assertUnprocessable();
        $this->patchJson('/api/users/'.$employee->id, $this->updateInput($employee, ['active' => false]))->assertOk();

        $this->assertSame('admin', $employee->fresh()->role);
        $this->assertFalse($employee->fresh()->active);
        $this->assertFalse($employee->fresh()->local_login_enabled);
    }

    public function test_initial_password_requires_personal_change_before_workspace_data_or_bi_actions_are_available(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Synthetic-Initial-2026'), 'must_change_password' => true]);
        Project::create(['user_id' => $user->id, 'name' => 'Conteúdo privado do usuário']);

        $this->actingAs($user)->getJson('/api/bootstrap')->assertStatus(423)->assertJsonPath('requires_password_change', true);
        $this->get('/app')->assertRedirect('/app/account');
        $this->get('/app/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace', false)->where('initial.projects', [])->where('initial.connections', []));
        $this->postJson('/api/catalog/100/credentials/actions', [])->assertStatus(423);

        $this->assertDatabaseCount('approvals', 0);
    }

    public function test_personal_password_change_validates_current_password_and_unlocks_the_account(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Synthetic-Initial-2026'), 'must_change_password' => true]);
        DB::table('sessions')->insert(['id' => 'synthetic-another-password-session', 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        $review = Approval::create(['user_id' => $user->id, 'type' => 'manual', 'title' => 'Revisão antes da troca de senha', 'payload' => [], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);
        $this->actingAs($user)->patchJson('/api/account/password', ['current_password' => 'wrong', 'password' => 'Synthetic-Personal-2026', 'password_confirmation' => 'Synthetic-Personal-2026'])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/account/password', ['current_password' => 'Synthetic-Initial-2026', 'password' => 'Synthetic-Personal-2026', 'password_confirmation' => 'Synthetic-Personal-2026'])->assertOk()->assertJsonPath('user.must_change_password', false)->assertJsonMissingPath('user.password');

        $this->assertTrue(Hash::check('Synthetic-Personal-2026', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(1, session('user_access_version'));
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-another-password-session']);
        $this->getJson('/api/bootstrap')->assertOk();
    }

    public function test_admin_password_reset_invalidates_previous_session_versions_and_requires_a_new_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $this->actingAs($admin)->patchJson('/api/users/'.$target->id, $this->updateInput($target, ['password' => 'Synthetic-Reset-2026', 'password_confirmation' => 'Synthetic-Reset-2026']))->assertOk();

        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Synthetic-Reset-2026', $target->fresh()->password));
        $this->actingAs($target->fresh())->withSession(['user_access_version' => 0])->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_inactive_user_cannot_execute_a_queued_approval_or_an_actual_lumo_command(): void
    {
        $user = User::factory()->create(['active' => false]);
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'type' => 'manual', 'title' => 'Configuração', 'status' => 'approved', 'payload' => ['origin' => 'manual', 'action' => ['action' => 'create_credential']], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);

        (new ExecuteApprovedAction($approval->id))->handle(app(ActionBroker::class));

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseCount('managed_bi_resources', 0);
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => [], 'metadata' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O acesso do usuário está inativo');
        app(LumoClient::class)->run($connection, ['list', 'app']);
    }

    public function test_inactive_queued_agent_run_never_starts_inference(): void
    {
        $user = User::factory()->create(['active' => false]);
        $project = Project::create(['user_id' => $user->id, 'name' => 'Projeto de teste']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'queued']);
        $runtime = $this->mock(CodexRuntime::class);
        $runtime->shouldNotReceive('run');

        (new RunAgentTurn($run->id))->handle($runtime);

        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_linked_chatgpt_sign_in_cannot_bypass_the_suite_for_an_employee_account(): void
    {
        $employee = User::factory()->create(['role' => 'admin', 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => 'f38b1e22-3b0b-4b1d-92e0-643fbefb753e', 'local_login_enabled' => false]);
        $connection = Connection::create(['user_id' => $employee->id, 'provider' => 'openai', 'subject' => hash('sha256', 'https://auth.openai.com|synthetic-client|synthetic-subject'), 'status' => 'connected', 'credentials' => ['access_token' => 'old-synthetic-token']]);
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldNotReceive('consume');
        $identity->shouldNotReceive('exchange');

        $this->withSession(['openai_oauth_state' => 'synthetic-state'])->get('/auth/openai/callback?state=synthetic-state&code=synthetic-code')->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame('old-synthetic-token', $connection->fresh()->credentials['access_token']);
    }

    public static function invalidRealtimeAccess(): array
    {
        return [[['active' => false], 401], [['must_change_password' => true], 423], [['access_version' => 1], 401]];
    }

    #[DataProvider('invalidRealtimeAccess')]
    public function test_revoked_or_unfinished_accounts_cannot_open_a_private_realtime_subscription(array $state, int $status): void
    {
        $property = array_key_first($state);
        $user = User::factory()->create([$property => $state[$property]]);
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');

        $response = $this->actingAs($user)->withSession(['user_access_version' => 0])->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.'.$user->id]);
        $status === 401 ? $response->assertUnauthorized() : $response->assertStatus(423);
    }

    public function test_revoked_local_sessions_cannot_finish_an_oauth_callback(): void
    {
        $user = User::factory()->create(['active' => false]);
        $this->mock(OpenAiIdentity::class)->shouldNotReceive('consume');

        $this->actingAs($user)->withSession(['openai_oauth_state' => 'synthetic-state'])->get('/auth/openai/callback?state=synthetic-state&code=synthetic-code')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_an_executing_review_cannot_resume_after_an_access_change_and_reactivation(): void
    {
        $user = User::factory()->create();
        $approval = Approval::create(['user_id' => $user->id, 'project_id' => null, 'type' => 'manual', 'title' => 'Revisão já iniciada', 'status' => 'executing', 'decided_at' => now(), 'payload' => ['origin' => 'manual', 'action' => ['action' => 'create_credential']], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);
        $user->forceFill(['access_version' => 1, 'active' => true, 'must_change_password' => false])->save();
        $this->mock(LumoClient::class)->shouldNotReceive('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('versão anterior do seu acesso');
        app(ActionBroker::class)->performApproved($approval);
    }

    public function test_access_stamp_cannot_be_updated_or_copied_to_another_review(): void
    {
        $user = User::factory()->create();
        $attributes = ['user_id' => $user->id, 'project_id' => null, 'type' => 'manual', 'title' => 'Revisão', 'payload' => [], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)];
        $first = Approval::create($attributes);
        $this->assertTrue($first->permitsUserAccess($user));
        $user->forceFill(['access_version' => 1])->save();
        $forged = $first->payload;
        $forged['portal_access']['version'] = 1;
        $first->update(['payload' => $forged]);
        $this->assertFalse($first->permitsUserAccess($user));
        $current = Approval::create($attributes);
        $other = Approval::create($attributes);
        $other->update(['payload' => ['portal_access' => $current->payload['portal_access']]]);
        $this->assertTrue($current->permitsUserAccess($user));
        $this->assertFalse($other->permitsUserAccess($user));
    }

    public function test_native_commands_do_not_resume_an_older_connection_context_after_access_changes(): void
    {
        $user = User::factory()->create();
        $connection = Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'synthetic']], 'metadata' => []]);
        $connection->operationAccessVersion = 0;
        $user->forceFill(['access_version' => 1])->save();
        config(['horus.lumo.binary' => '/bin/false']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Seu acesso ao portal foi alterado');
        app(LumoClient::class)->run($connection, ['list', 'app']);
    }

    public function test_local_console_provisioning_revokes_old_access_and_uses_the_same_author_profile(): void
    {
        $user = User::factory()->create(['email' => 'person@example.invalid']);
        DB::table('sessions')->insert(['id' => 'synthetic-console-session', 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        $review = Approval::create(['user_id' => $user->id, 'type' => 'manual', 'title' => 'Revisão anterior', 'payload' => [], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);

        $this->artisan('horus:user', ['email' => 'PERSON@example.invalid', '--role' => 'author'])
            ->expectsQuestion('Senha (mínimo de 12 caracteres)', 'Synthetic-Console-2026')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('editor', $user->fresh()->role);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertTrue(Hash::check('Synthetic-Console-2026', $user->fresh()->password));
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-console-session']);
    }

    public function test_console_provisioning_cannot_replace_a_suite_identity_or_its_password(): void
    {
        $user = User::factory()->create(['email' => 'employee@example.invalid', 'role' => 'admin', 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => 'f38b1e22-3b0b-4b1d-92e0-643fbefb753e', 'local_login_enabled' => false]);

        $this->artisan('horus:user', ['email' => 'employee@example.invalid', '--role' => 'viewer'])->assertFailed();

        $this->assertSame('admin', $user->fresh()->role);
        $this->assertFalse($user->fresh()->local_login_enabled);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_a_stale_administrator_model_cannot_issue_user_updates_after_demotion(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();
        User::whereKey($actor->id)->update(['role' => 'viewer', 'access_version' => 1]);

        $this->actingAs($actor)->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', $this->input())->assertForbidden();
        $this->patchJson('/api/users/'.$target->id, $this->updateInput($target, ['name' => 'Não deve salvar']))->assertForbidden();

        $this->assertSame($target->name, $target->fresh()->name);
        $this->assertDatabaseCount('users', 2);
    }

    public static function unsupportedLocalPasswords(): array
    {
        return [['Synthetic-A1'.str_repeat('ç', 35)], ["Synthetic-A1\0-invalid"]];
    }

    #[DataProvider('unsupportedLocalPasswords')]
    public function test_passwords_unsupported_by_the_hash_driver_are_validation_errors(string $password): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('Synthetic-Current-2026')]);

        $this->actingAs($admin)->postJson('/api/users', $this->input(['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/api/account/password', ['current_password' => 'Synthetic-Current-2026', 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('Synthetic-Current-2026', $admin->fresh()->password));
    }

    public function test_legacy_author_profiles_remain_editable_in_the_user_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Z Administrador']);
        $author = User::factory()->create(['role' => 'author', 'name' => 'A Autor legado']);

        $response = $this->actingAs($admin)->getJson('/api/users')->assertOk()->assertJsonPath('users.0.role', 'editor');
        $input = $this->updateInput($author, ['role' => $response->json('users.0.role')]);
        $this->patchJson('/api/users/'.$author->id, $input)->assertOk()->assertJsonPath('user.role', 'editor');

        $this->assertSame('editor', $author->fresh()->role);
        $this->assertTrue($author->fresh()->active);
    }

    public function test_portal_login_exposes_only_local_and_suite_authentication(): void
    {
        config(['suite.enabled' => true]);
        $this->mock(OpenAiIdentity::class)->shouldNotReceive('configured');

        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth', false)->where('suiteConfigured', true)->missing('openaiConfigured'));
    }

    public function test_guests_cannot_start_a_chatgpt_connection(): void
    {
        $this->mock(OpenAiIdentity::class)->shouldNotReceive('begin');

        $this->get('/auth/openai')->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_authenticated_oauth_only_links_the_current_portal_account(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'local_login_enabled' => false, 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => 'f38b1e22-3b0b-4b1d-92e0-643fbefb753e']);
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('begin')->once()->with($user->id)->andReturn(['state' => 'synthetic-state', 'url' => 'https://auth.openai.com/authorize?state=synthetic-state']);
        $identity->shouldReceive('consume')->once()->with('synthetic-state')->andReturn(['user_id' => $user->id]);
        $identity->shouldReceive('exchange')->once()->andReturn(['identity' => ['iss' => 'https://auth.openai.com', 'sub' => 'synthetic-subject'], 'client_id' => 'synthetic-client', 'tokens' => ['access_token' => 'new-synthetic-token', 'scope' => 'openid chatgpt.tokens.use.direct'], 'mode' => 'oss_local']);
        $identity->shouldReceive('planAuthorized')->once()->andReturn(true);

        $this->actingAs($user)->get('/auth/openai')->assertRedirect('https://auth.openai.com/authorize?state=synthetic-state')->assertSessionHas('openai_oauth_state', 'synthetic-state');
        $this->get('/auth/openai/callback?state=synthetic-state&code=synthetic-code')->assertRedirect('/app/connections');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'provider' => 'openai', 'status' => 'connected']);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertFalse($user->fresh()->local_login_enabled);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'connection.openai.connected']);
    }

    public function test_an_old_anonymous_oauth_transaction_cannot_sign_in_or_switch_portal_users(): void
    {
        $user = User::factory()->create();
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('consume')->once()->with('synthetic-state')->andReturn(['user_id' => null]);
        $identity->shouldNotReceive('exchange');

        $this->actingAs($user)->withSession(['openai_oauth_state' => 'synthetic-state'])->get('/auth/openai/callback?state=synthetic-state&code=synthetic-code')->assertRedirect('/app/connections')->assertSessionHas('error');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('connections', 0);
    }

    public function test_access_changed_during_oauth_exchange_cannot_issue_a_fresh_portal_session(): void
    {
        $user = User::factory()->create();
        $identity = $this->mock(OpenAiIdentity::class);
        $identity->shouldReceive('consume')->once()->with('synthetic-state')->andReturn(['user_id' => $user->id]);
        $identity->shouldReceive('exchange')->once()->andReturnUsing(function () use ($user) {
            User::whereKey($user->id)->update(['access_version' => 1]);

            return ['identity' => ['iss' => 'https://auth.openai.com', 'sub' => 'synthetic-subject'], 'client_id' => 'synthetic-client', 'tokens' => ['access_token' => 'new-synthetic-token'], 'mode' => 'oss_local'];
        });

        $this->actingAs($user)->withSession(['openai_oauth_state' => 'synthetic-state', 'user_access_version' => 0])->get('/auth/openai/callback?state=synthetic-state&code=synthetic-code')->assertRedirect('/app/connections')->assertSessionHas('error');

        $this->assertSame(0, session('user_access_version'));
        $this->assertDatabaseCount('connections', 0);
        $this->actingAs($user->fresh())->getJson('/api/bootstrap')->assertUnauthorized();
    }
}
