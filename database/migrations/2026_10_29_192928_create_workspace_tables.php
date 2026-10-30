<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('author');
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('tenant_id')->nullable();
            $t->string('tenant_name')->nullable();
            $t->string('color')->default('violet');
            $t->string('status')->default('draft');
            $t->text('instructions')->nullable();
            $t->boolean('is_example')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title')->default('Nova conversa');
            $t->string('model')->nullable();
            $t->string('thread_id')->nullable();
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('role');
            $t->longText('content');
            $t->string('status')->default('completed');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('status')->default('disconnected');
            $t->string('account_label')->nullable();
            $t->string('subject')->nullable();
            $t->longText('credentials')->nullable();
            $t->json('scopes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'provider']);
            $t->unique(['provider', 'subject']);
        });
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('queued');
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'status']);
        });
        Schema::create('approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('agent_run_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('type')->default('command');
            $t->string('title');
            $t->text('description')->nullable();
            $t->json('payload');
            $t->string('fingerprint', 64);
            $t->string('status')->default('pending');
            $t->timestamp('expires_at');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('artifacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('kind')->default('document');
            $t->string('mime')->default('text/plain');
            $t->string('path')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('refresh_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('source_id');
            $t->string('tenant_id');
            $t->string('remote_id')->nullable();
            $t->string('time', 5)->default('03:00');
            $t->string('timezone')->default('America/Sao_Paulo');
            $t->string('status')->default('pending_validation');
            $t->boolean('active')->default(false);
            $t->timestamp('last_success_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'tenant_id', 'source_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('action');
            $t->string('resource_id')->nullable();
            $t->string('summary');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'refresh_schedules', 'artifacts', 'approvals', 'agent_runs', 'connections', 'messages', 'conversations', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
