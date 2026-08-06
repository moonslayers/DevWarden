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
        Schema::table('opencode_settings', function (Blueprint $table) {
            $table->dateTime('session_watch_boot_reported_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opencode_settings', function (Blueprint $table) {
            $table->dropColumn('session_watch_boot_reported_at');
        });
    }
};
