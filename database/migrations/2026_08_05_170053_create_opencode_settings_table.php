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
        Schema::create('opencode_settings', function (Blueprint $table) {
            $table->id();
            $table->string('root_projects_path')->default('/home/junior/Projects');
            $table->string('mcp_command')->default('opencode-mcp');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opencode_settings');
    }
};
