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
        Schema::create('opencode_session_watches', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('project_path')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('last_seen_status')->nullable();
            $table->string('last_notified_event')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_status', 'opencode_session_watches_last_seen_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opencode_session_watches');
    }
};
