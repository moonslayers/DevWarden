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
        Schema::create('opencode_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->string('project_path');
            $table->string('opencode_session_id')->nullable();
            $table->string('template');
            $table->string('status');
            $table->string('confirmation_mode')->nullable();
            $table->string('current_step')->nullable();
            $table->text('last_summary')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opencode_workflows');
    }
};
