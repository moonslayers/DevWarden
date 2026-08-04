<?php

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/**
 * Bind a fake TelegramClient that returns the given updates.
 *
 * @param  array<int, array{update_id: int, chat_id?: int|string, text?: string}>  $updates
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
});

test('dispatches a job for an authorized text update and persists the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 100,
    ]);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('getUpdates')->with(101, 50)->andReturn([
        ['update_id' => 101, 'chat_id' => 123456789, 'text' => 'Hola bot'],
    ]);
    app()->instance(TelegramClient::class, $telegram);

    $this->artisan('telegram:poll')->assertSuccessful();

    Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Hola bot');

    expect(TelegramSetting::singleton()->last_update_id)->toBe(101);
});

test('discards unauthorized and non-text updates but still advances the offset', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123456789],
        'last_update_id' => 200,
    ]);

    fakeTelegramClient([
        ['update_id' => 201, 'chat_id' => 999999, 'text' => 'No autorizado'],
        ['update_id' => 202],
        ['update_id' => 203, 'chat_id' => 123456789, 'text' => 'Autorizado'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Autorizado');
    expect(Queue::pushed(ProcessTelegramUpdate::class))->toHaveCount(1);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(203);
});

test('exits gracefully with a warning when the bot token is not configured', function () {
    Queue::fake();

    TelegramSetting::factory()->create(['id' => 1, 'bot_token' => null]);

    $this->artisan('telegram:poll')
        ->expectsOutputToContain('Telegram polling skipped')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches every authorized text update and stores the highest update id', function () {
    Queue::fake();

    TelegramSetting::factory()->create([
        'id' => 1,
        'allowed_user_ids' => [123, 456],
        'last_update_id' => 500,
    ]);

    fakeTelegramClient([
        ['update_id' => 501, 'chat_id' => 123, 'text' => 'Uno'],
        ['update_id' => 502, 'chat_id' => 789, 'text' => 'No'],
        ['update_id' => 503, 'chat_id' => 456, 'text' => 'Dos'],
    ]);

    $this->artisan('telegram:poll')->assertSuccessful();

    Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job): bool => $job->chatId === 123 && $job->text === 'Uno');
    Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job): bool => $job->chatId === 456 && $job->text === 'Dos');
    expect(Queue::pushed(ProcessTelegramUpdate::class))->toHaveCount(2);

    expect(TelegramSetting::singleton()->last_update_id)->toBe(503);
});
