<?php

use App\Ai\Agents\BotAgent;
use App\Enums\AiProviderType;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramReply;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['id' => 1, 'owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);
});

test('dispatches the reply to the send job when the agent succeeds', function () {
    Queue::fake();

    BotAgent::fake(['Hola desde el bot']);

    app()->call([new ProcessTelegramUpdate(123456789, 'Hola bot'), 'handle']);

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === 'Hola bot');

    Queue::assertPushed(SendTelegramReply::class, fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Hola desde el bot');
});

test('passes the chat id and text through and maps a new conversation for the chat', function () {
    Queue::fake();

    BotAgent::fake(['Respuesta']);

    app()->call([new ProcessTelegramUpdate(555111, 'Mi consulta'), 'handle']);

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === 'Mi consulta');

    Queue::assertPushed(SendTelegramReply::class, fn ($job): bool => $job->chatId === 555111 && $job->text === 'Respuesta');

    expect(TelegramChatConversation::query()->where('chat_id', 555111)->exists())->toBeTrue();
});

test('dispatches the friendly message and does not fail when the AI generation fails', function () {
    Queue::fake();

    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app()->call([new ProcessTelegramUpdate(123456789, 'Hola bot'), 'handle']))
        ->not->toThrow(RuntimeException::class);

    Queue::assertPushed(SendTelegramReply::class, fn ($job): bool => $job->chatId === 123456789 && $job->text === ProcessTelegramUpdate::FRIENDLY_ERROR_MESSAGE);
});

test('skips processing and dispatches nothing when no owner user is configured', function () {
    Queue::fake();

    BotSetting::query()->update(['owner_user_id' => null]);

    app()->call([new ProcessTelegramUpdate(123456789, 'Hola bot'), 'handle']);

    BotAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
});

test('skips processing and dispatches nothing when the owner user has been deleted', function () {
    Queue::fake();

    $this->owner->delete();

    app()->call([new ProcessTelegramUpdate(123456789, 'Hola bot'), 'handle']);

    BotAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
    expect(BotSetting::singleton()->owner_user_id)->toBeNull();
});
