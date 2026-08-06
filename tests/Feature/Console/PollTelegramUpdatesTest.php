<?php

use App\Jobs\HandleTelegramCallbackQuery;
use App\Jobs\ProcessTelegramPendingBatch;
use App\Models\TelegramPendingMessage;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramMessageBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/**
 * Bind a fake TelegramClient that returns the given updates.
 *
 * @param  array<int, array{update_id: int, chat_id?: int|string, message_id?: int, text?: string, photo?: string, edit?: bool}>  $updates
 */
function fakeTelegramClient(array $updates): object
{
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('getUpdates')->andReturn($updates);

    app()->instance(TelegramClient::class, $telegram);

    return $telegram;
}

test('does nothing and enqueues nothing when polling is disabled', function () {
    Queue::fake();

    TelegramSetting::factory()->create(['id' => 1, 'polling_enabled' => false]);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('getUpdates');
    app()->instance(TelegramClient::class, $telegram);

    $this->artisan('telegram:poll')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(TelegramPendingMessage::query()->count())->toBe(0);
});

test('buffers an authorized text update and arms exactly one debounced batch job', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('getUpdates')->with(101, 50)->andReturn([
        ['update_id' => 101, 'chat_id' => 123456789, 'message_id' => 1, 'text' => 'Hola bot'],
    ]);
    app()->instance(TelegramClient::class, $telegram);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->chat_id)->toBe(123456789);
    expect($message->message_id)->toBe(1);
    expect($message->text)->toBe('Hola bot');
    expect($message->update_id)->toBe(101);
    expect($message->is_edit)->toBeFalse();
    expect($message->photo_file_id)->toBeNull();

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('buffers an authorized photo update with caption as text plus the photo file_id', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        ['update_id' => 101, 'chat_id' => 123456789, 'message_id' => 1, 'text' => 'Mira mi foto', 'photo' => 'file-id-123'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->text)->toBe('Mira mi foto');
    expect($message->photo_file_id)->toBe('file-id-123');
    expect($message->message_id)->toBe(1);

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);
    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('buffers an authorized photo update without caption with empty text plus the photo file_id', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        ['update_id' => 101, 'chat_id' => 123456789, 'message_id' => 1, 'photo' => 'file-id-456'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->text)->toBe('');
    expect($message->photo_file_id)->toBe('file-id-456');

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);
    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('discards an authorized update that is neither text nor photo but still advances the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        ['update_id' => 101, 'chat_id' => 123456789, 'message_id' => 1],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(0);

    Queue::assertNothingPushed();
    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('coalesces two messages of the same chat into a single batch job', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        ['update_id' => 101, 'chat_id' => 123456789, 'message_id' => 1, 'text' => 'Uno'],
        ['update_id' => 102, 'chat_id' => 123456789, 'message_id' => 2, 'text' => 'Dos'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(2);
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->pluck('text')->all())->toBe(['Uno', 'Dos']);

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789);
});

test('upserts an authorized edited message in place and arms one batch job', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    app(TelegramMessageBuffer::class)->storeMessage(123456789, 1, 'Hola bot', 101);

    fakeTelegramClient([
        ['update_id' => 102, 'chat_id' => 123456789, 'message_id' => 1, 'text' => 'Hola bot, corregido', 'edit' => true],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->text)->toBe('Hola bot, corregido');
    expect($message->update_id)->toBe(102);
    expect($message->is_edit)->toBeTrue();

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(102);
});

test('discards unauthorized and non-text updates but still advances the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 200,
    ]);

    fakeTelegramClient([
        ['update_id' => 201, 'chat_id' => 999999, 'message_id' => 1, 'text' => 'No autorizado'],
        ['update_id' => 202],
        ['update_id' => 203, 'chat_id' => 123456789, 'message_id' => 2, 'text' => 'Autorizado'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);
    expect(TelegramPendingMessage::query()->first()->text)->toBe('Autorizado');

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(203);
});

test('exits gracefully with a warning when the bot token is not configured', function () {
    Queue::fake();

    TelegramSetting::factory()->create(['id' => 1, 'bot_token' => null]);

    $this->artisan('telegram:poll')
        ->expectsOutputToContain('Telegram polling skipped')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    expect(TelegramPendingMessage::query()->count())->toBe(0);
});

test('dispatches one batch job per affected chat and stores the highest update id', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123, 456],
        'last_update_id' => 500,
    ]);

    fakeTelegramClient([
        ['update_id' => 501, 'chat_id' => 123, 'message_id' => 1, 'text' => 'Uno'],
        ['update_id' => 502, 'chat_id' => 789, 'message_id' => 1, 'text' => 'No'],
        ['update_id' => 503, 'chat_id' => 456, 'message_id' => 1, 'text' => 'Dos'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(2);

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 2);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 456);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(503);
});

test('routes an authorized callback query to the callback job without buffering it and advances the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        [
            'update_id' => 101,
            'callback_query_id' => '4382bfdwdsd323',
            'chat_id' => 123456789,
            'callback_data' => 'oq:ses_abc:0:1',
            'callback_message_id' => 42,
        ],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(0);

    Queue::assertPushed(HandleTelegramCallbackQuery::class, 1);
    Queue::assertPushed(HandleTelegramCallbackQuery::class, fn ($job): bool => $job->callbackQueryId === '4382bfdwdsd323'
        && $job->chatId === 123456789
        && $job->callbackData === 'oq:ses_abc:0:1'
        && $job->callbackMessageId === 42
    );
    Queue::assertNotPushed(ProcessTelegramPendingBatch::class);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('discards a callback query from an unauthorized chat but still advances the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    fakeTelegramClient([
        [
            'update_id' => 101,
            'callback_query_id' => 'cb-unauthorized',
            'chat_id' => 999999,
            'callback_data' => 'oq:ses_abc:0:1',
        ],
        [
            'update_id' => 102,
            'chat_id' => 123456789,
            'message_id' => 1,
            'text' => 'Hola bot',
        ],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    Queue::assertPushed(HandleTelegramCallbackQuery::class, 0);
    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(102);
});
