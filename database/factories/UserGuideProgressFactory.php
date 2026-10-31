<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserGuideProgress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserGuideProgress> */
class UserGuideProgressFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'page_key' => 'home', 'first_content_version' => '2026-10-30.1', 'last_content_version' => '2026-10-30.1', 'first_started_at' => now(), 'last_started_at' => now(), 'last_status' => 'started', 'last_instance_id' => (string) Str::uuid()];
    }
}
