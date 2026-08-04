<?php

use App\Jobs\SendTelegramReply;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;

use function Pest\Laravel\mock;

test('sends the message with the chat id and text', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->with(123456789, 'Hola desde el bot');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, 'Hola desde el bot'), 'handle']);
});

test('skips sending when the text is empty', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, ''), 'handle']);
});

test('skips sending when the text is null', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, null), 'handle']);
});

test('fails (so the job retries) when the Telegram client throws', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(TelegramApiException::class);

    app()->instance(TelegramClient::class, $telegram);

    expect(fn () => app()->call([new SendTelegramReply(123456789, 'Hola'), 'handle']))
        ->toThrow(TelegramApiException::class);
});
