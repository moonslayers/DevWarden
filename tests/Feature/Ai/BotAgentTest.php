<?php

use App\Ai\Agents\BotAgent;
use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramChatConversation;
use App\Models\User;
use App\Services\AiConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);
});

test('respond on a new chat persists a TelegramChatConversation with the conversation id', function () {
    BotAgent::fake(['Hello from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hi there', $this->owner);

    expect($reply)->toBe('Hello from the bot.');

    $mapping = TelegramChatConversation::query()->where('chat_id', 123456789)->first();

    expect($mapping)->not->toBeNull()
        ->and($mapping->user_id)->toBe($this->owner->id)
        ->and($mapping->conversation_id)->not->toBeNull();

    expect(Conversation::query()->where('id', $mapping->conversation_id)->exists())->toBeTrue();
});

test('respond on an existing chat resumes the same conversation without creating a new row', function () {
    $existing = TelegramChatConversation::factory()->create([
        'chat_id' => 555555555,
        'user_id' => $this->owner->id,
    ]);

    BotAgent::fake(['Continuing the chat.']);

    $reply = app(BotAgent::class)->respond(555555555, 'Tell me more', $this->owner);

    expect($reply)->toBe('Continuing the chat.')
        ->and(TelegramChatConversation::count())->toBe(1)
        ->and($existing->fresh()->conversation_id)->toBe($existing->conversation_id);

    $stored = ConversationMessage::query()->where('conversation_id', $existing->conversation_id)->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('role')->all())->toBe(['user', 'assistant']);
});

test('respond passes the failover chain from the syncer to the prompt', function () {
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create([
        'api_key' => 'sk-anthropic',
        'failover_order' => 1,
    ]);

    BotAgent::fake(['Fake reply']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Fake reply')
        ->and(app(AiConfigSyncer::class)->chain())->toBe(['openai', 'anthropic']);

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->provider()->name() === 'openai');
});

test('instructions returns the BotSetting system prompt when configured', function () {
    BotSetting::query()->update(['system_prompt' => 'You are a specialized Telegram development assistant.']);

    expect(app(BotAgent::class)->instructions())->toBe('You are a specialized Telegram development assistant.');
});

test('instructions falls back to the default prompt when BotSetting system prompt is empty', function () {
    BotSetting::query()->update(['system_prompt' => null]);

    $instructions = app(BotAgent::class)->instructions();

    expect($instructions)->toBe(BotAgent::DEFAULT_INSTRUCTIONS)
        ->and($instructions)->not->toBeEmpty();
});

test('respond propagates an exception when every provider in the chain fails', function () {
    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app(BotAgent::class)->respond(123456789, 'Hello', $this->owner))
        ->toThrow(RuntimeException::class, 'All providers are down.');
});

test('respond fills in a missing conversation id on an existing mapping without creating a new row', function () {
    $mapping = TelegramChatConversation::factory()->create([
        'chat_id' => 777777777,
        'user_id' => $this->owner->id,
        'conversation_id' => null,
    ]);

    BotAgent::fake(['Filled conversation.']);

    $reply = app(BotAgent::class)->respond(777777777, 'Hello', $this->owner);

    expect($reply)->toBe('Filled conversation.')
        ->and(TelegramChatConversation::count())->toBe(1)
        ->and($mapping->fresh()->conversation_id)->not->toBeNull();

    expect(Conversation::query()->where('id', $mapping->fresh()->conversation_id)->exists())->toBeTrue();
});

test('respond fails over to the next provider when the first one is rate limited', function () {
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create([
        'api_key' => 'sk-anthropic',
        'model_text' => 'claude-3-5-haiku-latest',
        'failover_order' => 1,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit exceeded.']], 429),
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-5-haiku-latest',
            'content' => [['type' => 'text', 'text' => 'Reply from the fallback provider.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ]),
    ]);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Reply from the fallback provider.');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.openai.com/v1/responses'));
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.anthropic.com/v1/messages'));
});
