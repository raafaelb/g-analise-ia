<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ManagedBiResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\ActionBroker;
use App\Services\LumoClient;
use App\Services\RefreshCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedBiResourceTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'tenant_id' => '100', 'name' => 'Test']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_id' => $project->id]);
        $run = AgentRun::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => 'running']);
        Connection::create(['user_id' => $user->id, 'provider' => 'horus', 'status' => 'connected', 'credentials' => ['auth' => ['token' => 'fake']]]);

        return [$project, $run];
    }

    public function test_clone_uses_explicit_name_and_records_new_resource_not_source(): void
    {
        [$project,$run] = $this->context();
        $this->mock(LumoClient::class)->shouldReceive('run')->once()->withArgs(fn ($c, $args, $p, $timeout) => $args === ['app', 'clone', 'app:1000', '--name', 'Homologação'] && $p->is($project))->andReturn(['exit_code' => 0, 'data' => ['ref' => 'app:2000']]);
        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'clone_app', 'resource' => 'app:1000', 'name' => 'Homologação']);
        $this->assertSame('app:2000', $result['managed_resource']);
        $this->assertDatabaseHas('managed_bi_resources', ['resource' => 'app:2000', 'source_ref' => 'app:1000', 'project_id' => $project->id]);
        $this->assertDatabaseMissing('managed_bi_resources', ['resource' => 'app:1000']);
    }

    public function test_ambiguous_clone_response_never_marks_original_as_deletable(): void
    {
        [$project,$run] = $this->context();
        $this->mock(LumoClient::class)->shouldReceive('run')->once()->andReturn(['exit_code' => 0, 'data' => ['id' => 1000]]);
        $result = app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'clone_app', 'resource' => 'app:1000']);
        $this->assertArrayHasKey('management_warning', $result);
        $this->assertDatabaseCount('managed_bi_resources', 0);
    }

    public function test_preexisting_app_cannot_be_deleted(): void
    {
        [$project] = $this->context();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recursos preexistentes');
        app(ActionBroker::class)->command(['action' => 'delete_draft', 'resource' => 'app:1000'], $project);
    }

    public function test_only_same_project_tenant_and_user_can_delete_recorded_draft(): void
    {
        [$project] = $this->context();
        ManagedBiResource::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2000', 'origin' => 'clone_app', 'receipt' => []]);
        $broker = app(ActionBroker::class);
        $this->assertSame(['rm', 'app:2000', '--force'], $broker->command(['action' => 'delete_draft', 'resource' => 'app:2000'], $project));
        $other = Project::create(['user_id' => $project->user_id, 'tenant_id' => '100', 'name' => 'Outro']);
        $this->expectException(\RuntimeException::class);
        $broker->command(['action' => 'delete_draft', 'resource' => 'app:2000'], $other);
    }

    public function test_published_resource_is_not_eligible_for_delete_draft(): void
    {
        [$project] = $this->context();
        ManagedBiResource::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2000', 'origin' => 'clone_app', 'status' => 'published', 'receipt' => []]);
        $this->expectException(\RuntimeException::class);
        app(ActionBroker::class)->command(['action' => 'delete_draft', 'resource' => 'app:2000'], $project);
    }

    public function test_remote_publish_is_rechecked_before_review_or_deletion(): void
    {
        [$project,$run] = $this->context();
        ManagedBiResource::create(['user_id' => $project->user_id, 'project_id' => $project->id, 'tenant_id' => '100', 'resource' => 'app:2000', 'origin' => 'clone_app', 'receipt' => []]);
        $this->mock(LumoClient::class)->shouldReceive('run')->once()->withArgs(fn ($c, $args) => $args === ['info', 'app:2000'])->andReturn(['data' => ['payload' => ['body' => ['_state' => ['deskId' => 10]]]]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('já foi publicada');
        app(ActionBroker::class)->execute($run, 'horus_action', ['action' => 'delete_draft', 'resource' => 'app:2000']);
    }

    public function test_newly_created_dashboard_cannot_publish_before_confirmed_refresh(): void
    {
        [$p,$run] = $this->context();
        ManagedBiResource::create(['user_id' => $p->user_id, 'project_id' => $p->id, 'tenant_id' => '100', 'resource' => 'app:2000', 'origin' => 'new', 'receipt' => []]);
        $this->mock(RefreshCoverage::class)->shouldReceive('inspect')->once()->andReturn(['verified' => false]);
        $this->mock(LumoClient::class)->shouldNotReceive('run');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atualização pendente');
        // O gate é conferido novamente na execução já autorizada, antes de qualquer escrita.
        $broker = app(ActionBroker::class);
        $method = new \ReflectionMethod($broker, 'perform');
        $method->invoke($broker, User::find($p->user_id), $p, Connection::first(), ['action' => 'publish_app', 'resource' => 'app:2000', 'desk' => 10]);
    }
}
