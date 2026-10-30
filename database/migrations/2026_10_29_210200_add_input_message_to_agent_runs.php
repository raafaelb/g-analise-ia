<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', fn (Blueprint $table) => $table->foreignUuid('input_message_id')->nullable()->constrained('messages')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('agent_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('input_message_id'));
    }
};
