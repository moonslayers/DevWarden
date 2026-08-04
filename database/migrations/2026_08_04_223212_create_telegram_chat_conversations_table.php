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
        Schema::create('telegram_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_conversations');
    }
};
