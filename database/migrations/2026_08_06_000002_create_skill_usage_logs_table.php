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
        Schema::create('skill_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->timestamp('created_at');

            $table->foreign('skill_id')
                ->references('id')
                ->on('bot_skills')
                ->cascadeOnDelete();

            $table->index(['skill_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skill_usage_logs');
    }
};
