<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['approvals', 'managed_bi_resources'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('project_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['approvals', 'managed_bi_resources'] as $name) {
            if (DB::table($name)->whereNull('project_id')->exists()) {
                throw new RuntimeException('Há operações de tenant sem projeto. Preserve esses registros antes de reverter esta migração.');
            }
        }
        foreach (['approvals', 'managed_bi_resources'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('project_id')->nullable(false)->change();
            });
        }
    }
};
