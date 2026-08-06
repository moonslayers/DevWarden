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

test('getUpdates normalizes updates into update_id, chat_id, message_id and text without an edit flag', function () {
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

    expect(array_key_exists('edit', $updates[0]))->toBeFalse();
    expect($updates)->toBe([
        ['update_id' => 11223344, 'chat_id' => 123456789, 'message_id' => 42, 'text' => 'Hola bot'],
    ]);
});

test('getUpdates normalizes an edited_message with message_id, the new text and the edit flag', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223348,
                'edited_message' => [
                    'message_id' => 42,
                    'date' => 1722800000,
                    'edit_date' => 1722800100,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'text' => 'Texto corregido',
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect($updates)->toBe([
        ['update_id' => 11223348, 'chat_id' => 123456789, 'message_id' => 42, 'edit' => true, 'text' => 'Texto corregido'],
    ]);
});

test('getUpdates normalizes an edited_message without text into chat_id, message_id and the edit flag', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223349,
                'edited_message' => [
                    'message_id' => 47,
                    'date' => 1722800004,
                    'edit_date' => 1722800104,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'photo' => [
                        ['file_id' => 'edited-photo-id', 'file_unique_id' => 'su-4', 'width' => 200, 'height' => 150, 'file_size' => 2000],
                    ],
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect(array_key_exists('text', $updates[0]))->toBeFalse();
    expect($updates)->toBe([
        ['update_id' => 11223349, 'chat_id' => 123456789, 'message_id' => 47, 'edit' => true, 'photo' => 'edited-photo-id'],
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

test('getUpdates normalizes a callback query update as an update without a message', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223350,
                'callback_query' => [
                    'id' => '4382bfdwdsd323',
                    'from' => ['id' => 123456789, 'is_bot' => false, 'first_name' => 'Test'],
                    'message' => [
                        'message_id' => 42,
                        'date' => 1722800000,
                        'chat' => ['id' => 123456789, 'type' => 'private'],
                    ],
                    'chat_instance' => '-1000000000000',
                    'data' => 'oq:ses_abc:0:1',
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect($updates)->toBe([
        ['update_id' => 11223350],
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

test('editMessageText posts to the editMessageText endpoint with the expected payload', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 43, 'text' => 'Respuesta editada']]),
    ], $container);

    $result = $client->editMessageText(123456789, 43, 'Respuesta editada');

    $request = $container[0]['request'];
    $sent = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.telegram.org/bot'.TelegramSetting::singleton()->bot_token.'/editMessageText');
    expect($sent)->toBe(['chat_id' => 123456789, 'message_id' => 43, 'text' => 'Respuesta editada']);
    expect(array_key_exists('parse_mode', $sent))->toBeFalse();
    expect($result)->toBe(['message_id' => 43, 'text' => 'Respuesta editada']);
});

test('editMessageText includes parse_mode in the body when one is provided', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 44, 'text' => 'Ok']]),
    ], $container);

    $result = $client->editMessageText(123456789, 44, 'Respuesta <b>editada</b>', 'HTML');

    $sent = json_decode((string) $container[0]['request']->getBody(), true);

    expect($sent)->toBe([
        'chat_id' => 123456789,
        'message_id' => 44,
        'text' => 'Respuesta <b>editada</b>',
        'parse_mode' => 'HTML',
    ]);
    expect($result)->toBe(['message_id' => 44, 'text' => 'Ok']);
});

test('deleteMessage posts to the deleteMessage endpoint and returns true', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => true]),
    ], $container);

    $result = $client->deleteMessage(123456789, 44);

    $request = $container[0]['request'];
    $sent = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.telegram.org/bot'.TelegramSetting::singleton()->bot_token.'/deleteMessage');
    expect($sent)->toBe(['chat_id' => 123456789, 'message_id' => 44]);
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
    expect($body)->not->toContain('name="parse_mode"');
    expect($result)->toBe(['message_id' => 50, 'photo' => []]);
});

test('sendPhoto includes parse_mode in the multipart body when a caption and parse mode are provided', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => ['message_id' => 52, 'photo' => []]]),
    ], $container);

    $photoPath = tempnam(sys_get_temp_dir(), 'dw-photo-');
    file_put_contents($photoPath, "\x89PNG\r\n\x1a\n-fake-photo-bytes-");

    try {
        $result = $client->sendPhoto(123456789, $photoPath, 'Mira esto', 'HTML');
    } finally {
        unlink($photoPath);
    }

    $body = (string) $container[0]['request']->getBody();

    expect($body)->toContain('name="caption"');
    expect($body)->toContain('Mira esto');
    expect($body)->toContain('name="parse_mode"');
    expect($body)->toContain('HTML');
    expect($result)->toBe(['message_id' => 52, 'photo' => []]);
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

test('getUpdates normalizes a photo update into the largest photo file_id and caption text', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223346,
                'message' => [
                    'message_id' => 45,
                    'date' => 1722800002,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'caption' => 'Mira mi foto',
                    'photo' => [
                        ['file_id' => 'small-file-id', 'file_unique_id' => 'su-1', 'width' => 100, 'height' => 80, 'file_size' => 1000],
                        ['file_id' => 'large-file-id', 'file_unique_id' => 'su-2', 'width' => 800, 'height' => 600, 'file_size' => 10000],
                        ['file_id' => 'medium-file-id', 'file_unique_id' => 'su-3', 'width' => 400, 'height' => 300, 'file_size' => 4000],
                    ],
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect($updates)->toBe([
        ['update_id' => 11223346, 'chat_id' => 123456789, 'message_id' => 45, 'text' => 'Mira mi foto', 'photo' => 'large-file-id'],
    ]);
});

test('getUpdates leaves a plain text message unchanged without a photo key', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            [
                'update_id' => 11223347,
                'message' => [
                    'message_id' => 46,
                    'date' => 1722800003,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'text' => 'Solo texto',
                ],
            ],
        ]]),
    ], $container);

    $updates = $client->getUpdates();

    expect(array_key_exists('photo', $updates[0]))->toBeFalse();
    expect($updates)->toBe([
        ['update_id' => 11223347, 'chat_id' => 123456789, 'message_id' => 46, 'text' => 'Solo texto'],
    ]);
});

test('getFile posts to the getFile method with the file_id and returns the file_path', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => true, 'result' => [
            'file_id' => 'large-file-id',
            'file_unique_id' => 'su-2',
            'file_size' => 10000,
            'file_path' => 'photos/file_1.jpg',
        ]]),
    ], $container);

    $result = $client->getFile('large-file-id');

    $request = $container[0]['request'];
    $sent = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.telegram.org/bot'.TelegramSetting::singleton()->bot_token.'/getFile');
    expect($sent)->toBe(['file_id' => 'large-file-id']);
    expect($result)->toBe([
        'file_id' => 'large-file-id',
        'file_unique_id' => 'su-2',
        'file_size' => 10000,
        'file_path' => 'photos/file_1.jpg',
    ]);
});

test('getFile throws TelegramApiException on a non-ok payload', function () {
    $container = [];
    $client = telegramClientWithMock([
        json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: file is too big']),
    ], $container);

    expect(fn () => $client->getFile('large-file-id'))
        ->toThrow(TelegramApiException::class, 'Telegram API request \'getFile\' failed: Bad Request: file is too big');
});

test('downloadFile GETs the file endpoint and writes the bytes to the destination', function () {
    $container = [];
    $client = telegramClientWithMock([
        "\x89PNG\r\n\x1a\n-photo-bytes-from-telegram-",
    ], $container);

    $destination = tempnam(sys_get_temp_dir(), 'dw-download-');

    try {
        $client->downloadFile('photos/file_1.jpg', $destination);

        $request = $container[0]['request'];

        expect((string) $request->getMethod())->toBe('GET');
        expect((string) $request->getUri())->toBe('https://api.telegram.org/file/bot'.TelegramSetting::singleton()->bot_token.'/photos/file_1.jpg');
        expect(file_get_contents($destination))->toBe("\x89PNG\r\n\x1a\n-photo-bytes-from-telegram-");
    } finally {
        unlink($destination);
    }
});

test('downloadFile throws TelegramApiException on an HTTP error response', function () {
    TelegramSetting::factory()->create();

    $container = [];
    $mock = new MockHandler([new Response(404, [], 'Not Found')]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($container));

    $client = new TelegramClient(new Client(['handler' => $handler]));

    $destination = tempnam(sys_get_temp_dir(), 'dw-download-');

    try {
        expect(fn () => $client->downloadFile('photos/missing.jpg', $destination))
            ->toThrow(TelegramApiException::class, 'Telegram file download \'photos/missing.jpg\' failed: HTTP 404.');
    } finally {
        unlink($destination);
    }
});
