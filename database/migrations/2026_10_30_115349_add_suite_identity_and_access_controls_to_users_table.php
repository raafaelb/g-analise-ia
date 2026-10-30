<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
            $table->boolean('local_login_enabled')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->string('suite_issuer')->nullable();
            $table->uuid('suite_subject')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('access_version')->default(0);
            $table->unsignedInteger('access_revision')->default(0);
            $table->unique(['suite_issuer', 'suite_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['suite_issuer', 'suite_subject']);
            $table->dropColumn(['active', 'local_login_enabled', 'must_change_password', 'suite_issuer', 'suite_subject', 'last_login_at', 'access_version', 'access_revision']);
        });
    }
};
