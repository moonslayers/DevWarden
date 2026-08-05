<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('opencode_workflows', function (Blueprint $table) {
            $table->index('status', 'opencode_workflows_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opencode_workflows', function (Blueprint $table) {
            $table->dropIndex('opencode_workflows_status_index');
        });
    }
};
