<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\ArtifactImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactImagesTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Imagens']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        $path = 'exports/'.$user->id.'/'.$project->id.'/image.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
        $artifact = Artifact::create(['user_id' => $user->id, 'project_id' => $project->id, 'kind' => 'dashboard_export', 'name' => 'Teste.png', 'mime' => 'image/png', 'path' => $path]);

        return [$user, $project, $conversation, $run, $artifact];
    }

    public function test_png_cannot_reach_the_model_without_explicit_consent(): void
    {
        [,,,$run,$artifact] = $this->context();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('autorize o envio');
        app(ArtifactImages::class)->content($run, $artifact->id);
    }

    public function test_specific_authorized_image_is_returned_in_official_image_format(): void
    {
        [$user,,$conversation,$run,$artifact] = $this->context();
        $service = app(ArtifactImages::class);
        $service->grant($user, $artifact, $conversation);
        $input = $service->content($run, $artifact->id);
        $this->assertSame('inputImage', $input['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $input['imageUrl']);
        $this->assertSame(Storage::disk('local')->get($artifact->path), base64_decode(substr($input['imageUrl'], 22)));
    }

    public function test_consent_cannot_be_reused_in_another_conversation(): void
    {
        [$user,$project,$conversation,,$artifact] = $this->context();
        $service = app(ArtifactImages::class);
        $service->grant($user, $artifact, $conversation);
        $other = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $other->id]);
        $this->expectException(\RuntimeException::class);
        $service->content($run, $artifact->id);
    }

    public function test_file_changed_after_consent_is_rejected(): void
    {
        [$user,,$conversation,$run,$artifact] = $this->context();
        $service = app(ArtifactImages::class);
        $service->grant($user, $artifact, $conversation);
        Storage::disk('local')->append($artifact->path, 'changed');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mudou após');
        $service->content($run, $artifact->id);
    }

    public function test_other_user_cannot_authorize_or_read_an_image(): void
    {
        [,, $conversation,,$artifact] = $this->context();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/artifacts/'.$artifact->id.'/visual-consent', ['conversation_id' => $conversation->id, 'recipient' => 'openai'])->assertNotFound();
    }

    public function test_grant_endpoint_checks_conversation_project_and_recipient(): void
    {
        [$user,,$conversation,,$artifact] = $this->context();
        $this->actingAs($user)->postJson('/api/artifacts/'.$artifact->id.'/visual-consent', ['conversation_id' => $conversation->id, 'recipient' => 'unapproved'])->assertUnprocessable();
        $this->postJson('/api/artifacts/'.$artifact->id.'/visual-consent', ['conversation_id' => $conversation->id, 'recipient' => 'openai'])->assertOk();
        $this->assertSame($conversation->id, $artifact->fresh()->metadata['visual_consent']['conversation_id']);
    }
}
