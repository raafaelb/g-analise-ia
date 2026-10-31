<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->unsignedInteger('access_version');
            $table->uuid('connection_generation');
            $table->string('page_key', 40);
            $table->string('guide_version', 40);
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('model')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_questions');
    }
};
