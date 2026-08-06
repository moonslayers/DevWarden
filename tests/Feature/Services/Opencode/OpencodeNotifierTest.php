<?php

use App\Models\TelegramSetting;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramHtmlFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function notifier(): OpencodeNotifier
{
    return new OpencodeNotifier(new TelegramHtmlFormatter);
}

test('sends the markdown formatted to HTML with parse mode HTML', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()
        ->with(123456789, (new TelegramHtmlFormatter)->format('**Hola**'), 'HTML', null)
        ->andReturn([]);
    app()->instance(TelegramClient::class, $telegram);

    expect(notifier()->notify(123456789, '**Hola**'))->toBeTrue();
});

test('passes the inline keyboard to sendMessage as reply markup when provided', function () {
    $telegram = mock(TelegramClient::class);
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'Sí', 'callback_data' => 'oc:ses_abc:0:0']],
        ],
    ];
    $telegram->shouldReceive('sendMessage')->once()
        ->with(123456789, (new TelegramHtmlFormatter)->format('**Hola**'), 'HTML', $keyboard)
        ->andReturn([]);
    app()->instance(TelegramClient::class, $telegram);

    expect(notifier()->notify(123456789, '**Hola**', $keyboard))->toBeTrue();
});

test('returns false and does not send when the markdown formats to empty html', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');
    app()->instance(TelegramClient::class, $telegram);

    expect(notifier()->notify(123456789, '---'))->toBeFalse();
});

test('returns false when the Telegram API call fails', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(TelegramApiException::class);
    app()->instance(TelegramClient::class, $telegram);

    expect(notifier()->notify(123456789, 'Hola'))->toBeFalse();
});

test('returns false when the bot token is not configured', function () {
    TelegramSetting::factory()->create(['id' => 1, 'bot_token' => null]);

    expect(notifier()->notify(123456789, 'Hola'))->toBeFalse();
});
