<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 40);
            $table->string('first_content_version', 40);
            $table->string('last_content_version', 40);
            $table->timestamp('first_started_at');
            $table->timestamp('last_started_at');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status', 20)->default('started');
            $table->uuid('last_instance_id');
            $table->string('last_step_id', 80)->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'page_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guide_progress');
    }
};
