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
        Schema::create('bot_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->string('source_message_id', 36)->nullable();
            $table->text('content');
            $table->text('summary')->nullable();
            $table->string('category')->nullable();
            $table->unsignedTinyInteger('importance')->default(5);
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->binary('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dim')->nullable();
            $table->timestamps();

            $table->index('chat_id');
            $table->index('category');
            $table->index('source_message_id');
            $table->index(['chat_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_memories');
    }
};
