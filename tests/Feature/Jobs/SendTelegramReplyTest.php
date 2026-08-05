<?php

use App\Jobs\SendTelegramReply;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramHtmlFormatter;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\mock;

test('sends the message with the chat id and text', function () {
    $telegram = mock(TelegramClient::class);
    $formatter = new TelegramHtmlFormatter;
    $telegram->shouldReceive('sendMessage')->once()->with(123456789, $formatter->format('Hola desde el bot'), 'HTML');

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

test('sends the image as a photo and deletes the file after a successful send', function () {
    Storage::disk('local')->put('telegram-media/test.jpg', 'bytes');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->with(
        123456789,
        Storage::disk('local')->path('telegram-media/test.jpg'),
        'Aquí tienes la imagen',
    );
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, 'Aquí tienes la imagen [IMAGE:telegram-media/test.jpg]'), 'handle']);

    expect(Storage::disk('local')->exists('telegram-media/test.jpg'))->toBeFalse();
});

test('falls back to a text message when the image file is missing', function () {
    $formatter = new TelegramHtmlFormatter;

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->with(123456789, $formatter->format('Respuesta'), 'HTML');
    $telegram->shouldNotReceive('sendPhoto');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, 'Respuesta [IMAGE:telegram-media/missing.jpg]'), 'handle']);
});

test('keeps the image file on disk when the photo send fails so the retry can reuse it', function () {
    Storage::disk('local')->put('telegram-media/test.jpg', 'bytes');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->andThrow(TelegramApiException::class);

    app()->instance(TelegramClient::class, $telegram);

    expect(fn () => app()->call([new SendTelegramReply(123456789, 'Captura [IMAGE:telegram-media/test.jpg]'), 'handle']))
        ->toThrow(TelegramApiException::class);

    expect(Storage::disk('local')->exists('telegram-media/test.jpg'))->toBeTrue();
});

test('falls back to a text message without touching the filesystem for an unsafe image marker', function (string $text, string $expectedCaption) {
    Storage::disk('local')->put('secret.txt', 'do-not-delete');

    $formatter = new TelegramHtmlFormatter;

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->with(123456789, $formatter->format($expectedCaption), 'HTML');
    $telegram->shouldNotReceive('sendPhoto');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, $text), 'handle']);

    expect(Storage::disk('local')->exists('secret.txt'))->toBeTrue();
})->with([
    'parent directory traversal' => ['Mira esto [IMAGE:../../../.env]', 'Mira esto'],
    'absolute path' => ['Mira esto [IMAGE:/etc/passwd]', 'Mira esto'],
    'marker escaping telegram-media' => ['Mira esto [IMAGE:telegram-media/../secret]', 'Mira esto'],
]);

test('skips sending entirely when the image file is missing and the stripped caption is empty', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');
    $telegram->shouldNotReceive('sendPhoto');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, '[IMAGE:telegram-media/missing.jpg]'), 'handle']);
});

test('sends one photo using the first marker and strips all markers from the caption', function () {
    Storage::disk('local')->put('telegram-media/a.jpg', 'bytes');
    Storage::disk('local')->put('telegram-media/b.jpg', 'bytes');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->with(
        123456789,
        Storage::disk('local')->path('telegram-media/a.jpg'),
        'A  B',
    );
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, 'A [IMAGE:telegram-media/a.jpg] B [IMAGE:telegram-media/b.jpg]'), 'handle']);

    expect(Storage::disk('local')->exists('telegram-media/a.jpg'))->toBeFalse()
        ->and(Storage::disk('local')->exists('telegram-media/b.jpg'))->toBeTrue();
});

test('sends a photo with an empty caption when the reply is only a valid marker and then deletes the file', function () {
    Storage::disk('local')->put('telegram-media/x.jpg', 'bytes');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->with(
        123456789,
        Storage::disk('local')->path('telegram-media/x.jpg'),
        '',
    );
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, '[IMAGE:telegram-media/x.jpg]'), 'handle']);

    expect(Storage::disk('local')->exists('telegram-media/x.jpg'))->toBeFalse();
});

test('truncates the photo caption to Telegram\'s 1024-character limit', function () {
    Storage::disk('local')->put('telegram-media/long.jpg', 'bytes');

    $caption = 'Encabezado '.str_repeat('a', 1500);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->withArgs(function (int $chatId, string $path, string $photoCaption) {
        expect($chatId)->toBe(123456789);
        expect($path)->toBe(Storage::disk('local')->path('telegram-media/long.jpg'));
        expect(mb_strlen($photoCaption))->toBeLessThanOrEqual(1024);
        expect($photoCaption)->toEndWith('…');

        return true;
    });
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);

    app()->call([new SendTelegramReply(123456789, $caption.' [IMAGE:telegram-media/long.jpg]'), 'handle']);

    expect(Storage::disk('local')->exists('telegram-media/long.jpg'))->toBeFalse();
});

test('skips sending when the raw text formats to empty html', function (string $input) {
    $formatter = new TelegramHtmlFormatter;
    expect($formatter->format($input))->toBe('');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');

    app()->instance(TelegramClient::class, $telegram);
    app()->instance(TelegramHtmlFormatter::class, $formatter);

    app()->call([new SendTelegramReply(123456789, $input), 'handle']);
})->with([
    'horizontal rule only' => '---',
    'stripped script only' => '<script>x</script>',
]);
