<?php

use App\Models\TelegramChatConversation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('telegram_chat_conversations table exists', function () {
    expect(Schema::hasTable('telegram_chat_conversations'))->toBeTrue();
});

test('belongs to a user', function () {
    $user = User::factory()->create();

    $conversation = TelegramChatConversation::factory()->create(['user_id' => $user->id]);

    expect($conversation->user->is($user))->toBeTrue();
});

test('chat_id is unique', function () {
    $chatId = 123456789;

    TelegramChatConversation::factory()->create(['chat_id' => $chatId]);

    expect(fn () => TelegramChatConversation::factory()->create(['chat_id' => $chatId]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('conversation_id can be null', function () {
    $conversation = TelegramChatConversation::factory()->create(['conversation_id' => null]);

    expect($conversation->conversation_id)->toBeNull();
});

test('factory produces a valid row', function () {
    $conversation = TelegramChatConversation::factory()->create();

    expect($conversation->exists)->toBeTrue();
    expect($conversation->conversation_id)->toBeString();
});
