<?php

namespace Tests\Feature;

use App\Jobs\OperationsHeartbeat;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_detects_missing_scheduler_and_workers_and_recovers_with_heartbeats(): void
    {
        $this->freezeTime();
        Queue::fake();
        $this->artisan('ganalise:health')->assertFailed();
        Cache::put('operations:scheduler', now()->timestamp, 300);
        (new OperationsHeartbeat('agents'))->handle();
        (new OperationsHeartbeat('documents'))->handle();
        $this->artisan('ganalise:health')->assertFailed();
        (new OperationsHeartbeat('help'))->handle();
        $this->artisan('ganalise:health')->assertFailed();
        (new OperationsHeartbeat('actions'))->handle();
        $this->artisan('ganalise:health')->assertSuccessful();
        Cache::put('operations:worker:actions', now()->subMinutes(21)->timestamp, 300);
        $this->artisan('ganalise:health')->assertFailed();
        (new OperationsHeartbeat('actions'))->handle();
        Cache::put('operations:worker:help', now()->subMinutes(6)->timestamp, 300);
        $this->artisan('ganalise:health')->assertFailed();
        (new OperationsHeartbeat('help'))->handle();
        Queue::assertNothingPushed();
        Cache::put('operations:worker:agents', now()->subMinutes(21)->timestamp, 300);
        $this->artisan('ganalise:health')->assertFailed();
    }

    public function test_retention_is_dry_run_and_preserves_uploads_and_other_project_paths(): void
    {
        Storage::fake('local');
        $u = User::factory()->create();
        $p = Project::create(['user_id' => $u->id, 'name' => 'Retenção']);
        $prefix = 'exports/'.$u->id.'/'.$p->id.'/';
        foreach (['old' => 'dashboard_export', 'recent' => 'dashboard_export', 'attachment' => 'attachment', 'foreign' => 'dashboard_export'] as $key => $kind) {
            $path = $key === 'foreign' ? 'workspaces/999/other/exports/image.png' : $prefix.$key.'.png';
            Storage::disk('local')->put($path, 'image');
            $a = Artifact::create(['user_id' => $u->id, 'project_id' => $p->id, 'name' => $key, 'path' => $path, 'kind' => $kind]);
            if ($key !== 'recent') {
                $a->created_at = now()->subDays(40);
                $a->save();
            }
        }
        $this->artisan('ganalise:retention')->assertSuccessful();
        $this->assertDatabaseCount('artifacts', 4);
        $this->artisan('ganalise:retention --apply')->assertFailed();
        $this->assertDatabaseMissing('artifacts', ['name' => 'old']);
        $this->assertDatabaseCount('artifacts', 3);
        Storage::disk('local')->assertExists($prefix.'attachment.png');
        Storage::disk('local')->assertExists('workspaces/999/other/exports/image.png');
    }
}
