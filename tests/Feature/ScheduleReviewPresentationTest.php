<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleReviewPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_reviews_receive_current_timing_labels_without_changing_the_signed_proposal(): void
    {
        $this->travelTo('2026-10-31T12:00:00Z');
        $user = User::factory()->create();
        $approval = Approval::create(['user_id' => $user->id, 'type' => 'manual', 'title' => 'Conferir carga', 'status' => 'completed', 'payload' => ['origin' => 'manual', 'action' => ['action' => 'run_schedule', 'resource' => 'schedule:50'], 'revision' => ['tenant_id' => '321', 'schedule' => ['triggers' => [['type' => 'Week', 'period' => 0, 'start_when' => '05:00'], ['type' => 'Day', 'start_when' => '03:00'], ['type' => 'CDC', 'period' => 10, 'config' => ['credential' => 'PRIVATE-KEY', 'sql' => 'PRIVATE-QUERY']]]]]], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
        $payload = $approval->getRawOriginal('payload');

        $response = $this->actingAs($user)->getJson('/api/bi-actions/'.$approval->id)->assertOk()->assertJsonPath('approval.schedule_timing.daily_or_more_frequent', true)->assertJsonPath('approval.schedule_timing.triggers', ['Semanalmente, domingo, às 05:00', 'Diariamente às 03:00', 'Quando os dados mudarem · sondagem a cada 10 segundos']);

        $this->assertStringNotContainsString('PRIVATE-', json_encode($response->json('approval.schedule_timing'), JSON_THROW_ON_ERROR));
        $this->assertSame($payload, $approval->fresh()->getRawOriginal('payload'));
        $this->assertDatabaseHas('approvals', ['id' => $approval->id, 'status' => 'completed', 'fingerprint' => str_repeat('a', 64)]);
    }

    public function test_editor_reviews_explain_previous_timing_without_inserting_derived_text_into_the_payload(): void
    {
        $approval = new Approval(['payload' => ['revision' => ['schedule' => ['triggers' => [['type' => 'Week', 'period' => 1, 'start_when' => '03:00']]], 'schedule_edit' => ['previous' => ['triggers' => [['type' => 'Day', 'start_when' => '05:00']]]]]]]);
        $payload = $approval->payload;

        $this->assertSame('Diariamente às 05:00', $approval->toArray()['schedule_timing']['previous_frequency']);
        $this->assertSame('Semanalmente, segunda-feira, às 03:00', $approval->toArray()['schedule_timing']['frequency']);
        $this->assertSame($payload, $approval->payload);
    }

    public function test_a_malformed_legacy_trigger_map_does_not_claim_readable_timing(): void
    {
        $approval = new Approval(['payload' => ['revision' => ['schedule' => ['triggers' => ['unexpected' => 'value']]]]]);

        $this->assertNull($approval->toArray()['schedule_timing']);
    }

    public function test_an_ordinary_review_does_not_gain_schedule_information(): void
    {
        $approval = new Approval(['payload' => ['action' => ['action' => 'clone_app', 'resource' => 'app:10']]]);

        $this->assertNull($approval->toArray()['schedule_timing']);
    }

    public function test_a_future_start_is_explained_in_brasilia_without_mutating_the_approval_after_time_passes(): void
    {
        $this->travelTo('2026-10-31T12:00:00Z');
        $approval = new Approval(['payload' => ['revision' => ['schedule' => ['triggers' => [['type' => 'Hour', 'period' => 1, 'start_when' => '2026-10-31T12:30:00Z', 'start_time' => '08:00', 'end_time' => '18:00']]]]], 'fingerprint' => str_repeat('b', 64)]);
        $payload = $approval->payload;

        $this->assertSame('A cada 1 hora · das 08:00 às 18:00 (Brasília) · começa em 31/10/2026 09:30 (Brasília)', $approval->toArray()['schedule_timing']['frequency']);
        $this->assertFalse($approval->toArray()['schedule_timing']['daily_or_more_frequent']);
        $this->travelTo('2026-10-31T13:00:00Z');

        $this->assertSame('A cada 1 hora · das 08:00 às 18:00 (Brasília)', $approval->toArray()['schedule_timing']['frequency']);
        $this->assertTrue($approval->toArray()['schedule_timing']['daily_or_more_frequent']);
        $this->assertSame($payload, $approval->payload);
        $this->assertSame(str_repeat('b', 64), $approval->fingerprint);
    }
}
