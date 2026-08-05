<?php

use App\Models\TelegramSetting;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\Exceptions\TelegramNotConfiguredException;
use App\Services\Telegram\TelegramClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

/**
 * Create a TelegramClient backed by a mock Guzzle handler.
 *
 * @param  array<int, string>  $bodies  Raw response bodies to return, in order.
 * @param  array<int, array{request: RequestInterface}>  $container  Filled by reference with captured requests.
 */
function telegramClientWithMock(array $bodies, array &$container): TelegramClient
{
    TelegramSetting::factory()->create();

    $mock = new MockHandler(array_map(
        fn (string $body): Response => new Response(200, [], $body),
        $bodies,
    ));

    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($container));

    return new TelegramClient(new Client(['handler' => $handler]));
}

test('constructing the client without a configured token throws TelegramNotConfiguredException', function () {
    TelegramSetting::singleton();

    expect(fn () => new TelegramClient)
        ->toThrow(TelegramNotConfiguredException::class, 'The Telegram bot token has not been configured.');
});

test('getUpdates normalizes updates into update_id, chat_id and text', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223344,
                'message' => [
                    'message_id' => 42,
                    'date' => 1722800000,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'text' => 'Hola bot',
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect($updates)->toBe([
        ['update_id' => 11223344, 'chat_id' => 123456789, 'text' => 'Hola bot'],
    ]);
});

test('getUpdates omits chat_id and text when the update has no message', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            ['update_id' => 11223345],
        ]]),
    ], $container);

    expect($client->getUpdates())->toBe([
        ['update_id' => 11223345],
    ]);
});

test('getUpdates forwards offset and timeout parameters', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => []]),
    ], $container);

    $client->getUpdates(offset: 99, timeout: 25);

    $sent = json_decode((string) $container[0]['request']->getBody(), true);

    expect($sent)->toBe(['offset' => 99, 'timeout' => 25]);
});

test('sendMessage posts to the bot endpoint with the expected chat and plain-text body', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 43, 'text' => 'Ok']]),
    ], $container);

    $result = $client->sendMessage(123456789, 'Respuesta con <b>tags</b> sin parse');

    $request = $container[0]['request'];
    $sent = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.telegram.org/bot'.TelegramSetting::singleton()->bot_token.'/sendMessage');
    expect($sent)->toBe(['chat_id' => 123456789, 'text' => 'Respuesta con <b>tags</b> sin parse']);
    expect(array_key_exists('parse_mode', $sent))->toBeFalse();
    expect($result)->toBe(['message_id' => 43, 'text' => 'Ok']);
});

test('sendMessage includes parse_mode in the body when one is provided', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 44, 'text' => 'Ok']]),
    ], $container);

    $result = $client->sendMessage(123456789, 'Respuesta con <b>tags</b>', 'HTML');

    $sent = json_decode((string) $container[0]['request']->getBody(), true);

    expect($sent)->toBe([
        'chat_id' => 123456789,
        'text' => 'Respuesta con <b>tags</b>',
        'parse_mode' => 'HTML',
    ]);
    expect($result)->toBe(['message_id' => 44, 'text' => 'Ok']);
});

test('setMyCommands sends the expected commands payload', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => true]),
    ], $container);

    $result = $client->setMyCommands([
        ['command' => 'start', 'description' => 'Inicia el bot'],
        ['command' => 'help', 'description' => 'Ayuda'],
    ]);

    $sent = json_decode((string) $container[0]['request']->getBody(), true);

    expect($sent)->toBe(['commands' => [
        ['command' => 'start', 'description' => 'Inicia el bot'],
        ['command' => 'help', 'description' => 'Ayuda'],
    ]]);
    expect($result)->toBeTrue();
});

test('sendPhoto uploads a local photo as multipart/form-data with a caption', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 50, 'photo' => []]]),
    ], $container);

    $photoPath = tempnam(sys_get_temp_dir(), 'dw-photo-');
    file_put_contents($photoPath, "\x89PNG\r\n\x1a\n-fake-photo-bytes-");

    try {
        $result = $client->sendPhoto(123456789, $photoPath, 'Mira esto');
    } finally {
        unlink($photoPath);
    }

    $request = $container[0]['request'];

    expect((string) $request->getUri())->toEndWith('/sendPhoto');
    expect((string) $request->getHeaderLine('Content-Type'))->toContain('multipart/form-data');

    $body = (string) $request->getBody();
    expect($body)->toContain('name="chat_id"');
    expect($body)->toContain('123456789');
    expect($body)->toContain('name="photo"');
    expect($body)->toContain("\x89PNG\r\n\x1a\n-fake-photo-bytes-");
    expect($body)->toContain('name="caption"');
    expect($body)->toContain('Mira esto');
    expect($result)->toBe(['message_id' => 50, 'photo' => []]);
});

test('sendPhoto omits the caption field when none is provided', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 51, 'photo' => []]]),
    ], $container);

    $photoPath = tempnam(sys_get_temp_dir(), 'dw-photo-');
    file_put_contents($photoPath, "\x89PNG\r\n\x1a\n");

    try {
        $result = $client->sendPhoto(123456789, $photoPath);
    } finally {
        unlink($photoPath);
    }

    $body = (string) $container[0]['request']->getBody();

    expect($body)->toContain('name="photo"');
    expect($body)->not->toContain('name="caption"');
    expect($result)->toBe(['message_id' => 51, 'photo' => []]);
});

test('sendPhoto throws TelegramApiException on a non-ok payload', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS']),
    ], $container);

    $photoPath = tempnam(sys_get_temp_dir(), 'dw-photo-');
    file_put_contents($photoPath, 'bytes');

    try {
        expect(fn () => $client->sendPhoto(123456789, $photoPath))
            ->toThrow(TelegramApiException::class, 'Telegram API request \'sendPhoto\' failed: Bad Request: PHOTO_INVALID_DIMENSIONS');
    } finally {
        unlink($photoPath);
    }
});

test('an error payload throws TelegramApiException', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']),
    ], $container);

    expect(fn () => $client->sendMessage(123456789, 'Hola'))
        ->toThrow(TelegramApiException::class, 'Telegram API request \'sendMessage\' failed: Unauthorized');
});
