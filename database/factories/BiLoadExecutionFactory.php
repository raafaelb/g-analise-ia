<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\BiLoadExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BiLoadExecution> */
class BiLoadExecutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'approval_id' => fn (array $attributes): string => Approval::create(['user_id' => $attributes['user_id'], 'type' => 'manual', 'title' => 'Executar agenda de teste', 'payload' => ['origin' => 'manual', 'action' => ['action' => 'run_schedule_now', 'resource' => 'schedule:20', 'request_id' => (string) Str::uuid()]], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(12)])->id,
            'tenant_id' => '321',
            'schedule_id' => '20',
            'status' => 'accepted',
            'metadata' => ['access_version' => 0, 'schedule_name' => 'Carga de teste', 'bindings' => [['flow_id' => '10', 'name' => 'Receitas', 'context' => 'Total']], 'expected_flow_ids' => ['10'], 'configuration_hash' => str_repeat('a', 64), 'connection_generation' => (string) Str::uuid(), 'run_id' => null, 'before_run_ids' => [], 'correlation' => 'awaiting_history'],
            'requested_at' => now(),
        ];
    }
}
