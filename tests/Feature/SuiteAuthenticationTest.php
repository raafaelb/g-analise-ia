<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SuiteAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://suite.example.invalid/api/auth/suite/autenticate/check_login.json';

    private const SUBJECT = 'f38b1e22-3b0b-4b1d-92e0-643fbefb753e';

    private const PASSWORD = 'Synthetic-Suite-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['suite.enabled' => true, 'suite.base_url' => 'https://suite.example.invalid', 'suite.application_code' => 'g-analise-ia', 'suite.application_name' => 'G-Analise IA']);
        Http::preventStrayRequests();
    }

    private function identity(array $changes = []): array
    {
        return ['success' => true, 'data' => [...['id' => 123, 'uuid' => self::SUBJECT, 'nome' => 'Colaborador de teste', 'email' => 'employee@example.invalid'], ...$changes]];
    }

    public function test_verified_suite_credentials_create_an_administrator_without_storing_the_suite_password(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->identity())]);

        $this->post('/auth/suite', ['email' => 'EMPLOYEE@example.invalid', 'password' => self::PASSWORD, 'role' => 'owner'])->assertRedirect('/app');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->role);
        $this->assertSame(self::SUBJECT, $user->suite_subject);
        $this->assertFalse($user->local_login_enabled);
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === self::ENDPOINT && $request->hasHeader('User-email', 'employee@example.invalid') && $request->hasHeader('User-password', self::PASSWORD) && $request->hasHeader('X-Inovadora-Application-Code', 'g-analise-ia') && $request->hasHeader('X-Inovadora-Application-Name', 'G-Analise IA') && $request->hasHeader('X-Inovadora-Application-Url', route('login')) && $request->hasHeader('X-Inovadora-Client-IP') && $request->hasHeader('X-Inovadora-Request-Id') && ! str_contains($request->body(), self::PASSWORD));
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.suite.login']);
    }

    public function test_existing_local_account_is_linked_only_after_verification_and_loses_its_local_login(): void
    {
        $user = User::factory()->create(['email' => 'Employee@example.invalid', 'role' => 'editor']);
        DB::table('sessions')->insert(['id' => 'synthetic-old-local-session', 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        $review = Approval::create(['user_id' => $user->id, 'type' => 'manual', 'title' => 'Revisão local anterior', 'payload' => [], 'fingerprint' => 'synthetic', 'expires_at' => now()->addMinutes(10)]);
        Http::fake([self::ENDPOINT => Http::response($this->identity())]);

        $this->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertRedirect('/app');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertFalse($user->fresh()->local_login_enabled);
        $this->assertSame('employee@example.invalid', $user->fresh()->email);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-old-local-session']);
    }

    public function test_stable_external_uuid_updates_the_same_account_when_its_email_changes(): void
    {
        $user = User::factory()->create(['email' => 'old@example.invalid', 'role' => 'owner', 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => self::SUBJECT]);
        Http::fake([self::ENDPOINT => Http::response($this->identity())]);

        $this->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertRedirect('/app');

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('owner', $user->fresh()->role);
        $this->assertSame('employee@example.invalid', $user->fresh()->email);
    }

    public function test_inactive_local_access_cannot_be_reactivated_by_a_successful_suite_response(): void
    {
        $user = User::factory()->create(['email' => 'employee@example.invalid', 'active' => false]);
        Http::fake([self::ENDPOINT => Http::response($this->identity())]);

        $this->from('/login')->post('/auth/suite', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertFalse($user->fresh()->active);
        $this->assertNull($user->fresh()->suite_subject);
    }

    public function test_an_email_collision_does_not_reassign_an_existing_external_identity(): void
    {
        $user = User::factory()->create(['email' => 'employee@example.invalid', 'suite_issuer' => 'https://suite.example.invalid', 'suite_subject' => '46488b75-9315-45c0-bc37-17f62bc83dcc']);
        Http::fake([self::ENDPOINT => Http::response($this->identity())]);

        $this->post('/auth/suite', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('46488b75-9315-45c0-bc37-17f62bc83dcc', $user->fresh()->suite_subject);
    }

    public static function refusedResponses(): array
    {
        return [
            'invalid credentials' => [401, ['success' => false]],
            'server error with success flag' => [500, ['success' => true]],
            'untrusted redirect' => [302, ['success' => true]],
            'string instead of boolean' => [200, ['success' => 'true']],
            'invalid json' => [200, '{not-json'],
            'missing identity' => [200, ['success' => true]],
            'email mismatch' => [200, ['success' => true, 'data' => ['uuid' => self::SUBJECT, 'email' => 'another@example.invalid', 'nome' => 'Other']]],
            'invalid subject' => [200, ['success' => true, 'data' => ['uuid' => 'not-a-uuid', 'email' => 'employee@example.invalid', 'nome' => 'Employee']]],
        ];
    }

    #[DataProvider('refusedResponses')]
    public function test_only_the_documented_positive_response_can_issue_a_local_session(int $status, mixed $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status, ['Location' => 'https://untrusted.example.invalid/'])]);

        $this->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
    }

    public function test_suite_retry_after_blocks_another_request_without_automatic_retries(): void
    {
        $this->freezeTime();
        Http::fake([self::ENDPOINT => Http::response(['success' => false], 429, ['Retry-After' => '300'])]);

        $this->from('/login')->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertHeader('Retry-After', '300')->assertSessionHasErrors('email');
        $this->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertHeader('Retry-After', '300');

        Http::assertSentCount(1);
        $this->assertGuest();
    }

    public function test_network_failure_is_generic_and_never_falls_back_to_local_authentication(): void
    {
        $user = User::factory()->create(['email' => 'employee@example.invalid', 'password' => Hash::make(self::PASSWORD)]);
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('Synthetic connection failure')]);

        $this->post('/auth/suite', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => 'A Suíte está indisponível para autenticação. Tente novamente mais tarde.']);

        $this->assertGuest();
        $this->assertNull($user->fresh()->suite_subject);
    }

    public function test_password_header_injection_is_refused_before_an_outbound_request(): void
    {
        $this->postJson('/auth/suite', ['email' => 'employee@example.invalid', 'password' => "Synthetic\r\nX-Injected: value"])->assertUnprocessable()->assertJsonValidationErrors('password');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_disabled_or_insecure_configuration_cannot_receive_a_password(): void
    {
        config(['suite.base_url' => 'http://suite.example.invalid']);

        $this->post('/auth/suite', ['email' => 'employee@example.invalid', 'password' => self::PASSWORD])->assertSessionHasErrors('email');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_suite_only_accounts_cannot_use_the_local_password_form(): void
    {
        $user = User::factory()->create(['email' => 'employee@example.invalid', 'password' => Hash::make('Synthetic-Local-2026'), 'local_login_enabled' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Synthetic-Local-2026'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_significant_password_edge_spaces_are_not_silently_removed_by_http_headers(): void
    {
        $this->postJson('/auth/suite', ['email' => 'employee@example.invalid', 'password' => ' Synthetic-Suite-2026 '])->assertUnprocessable()->assertJsonValidationErrors('password');

        Http::assertNothingSent();
        $this->assertGuest();
    }
}
