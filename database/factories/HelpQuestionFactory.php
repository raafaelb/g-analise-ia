<?php

namespace Database\Factories;

use App\Models\HelpQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HelpQuestion> */
class HelpQuestionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'connection_id' => null, 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'synthetic-help-request'), 'access_version' => 0, 'connection_generation' => (string) Str::uuid(), 'page_key' => 'home', 'guide_version' => '2026-10-30.1', 'question' => 'Para que servem os projetos?', 'status' => 'queued'];
    }
}
