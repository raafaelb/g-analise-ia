<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_bi_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 80);
            $table->string('resource', 80);
            $table->string('origin', 30);
            $table->string('source_ref', 80)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('receipt');
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_bi_resources');
    }
};
