<?php

use App\Ai\Tools\DownloadImageTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use Tests\TestCase;

uses(TestCase::class);

function pngImageFixture(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function gifImageFixture(): string
{
    return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

test('handle downloads a valid image, stores it and returns the image marker', function () {
    Storage::fake('local');

    Http::fake([
        'https://example.com/*' => Http::response(pngImageFixture(), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/photo.png']));

    expect($result)->toBeString()
        ->toContain('Image downloaded and stored at telegram-media/')
        ->toContain('.png')
        ->toContain('[IMAGE:telegram-media/');

    preg_match('/\[IMAGE:([^\]]+)\]/', $result, $matches);

    expect($matches)->toHaveCount(2);

    $path = $matches[1];

    expect($path)->toStartWith('telegram-media/')
        ->toEndWith('.png');

    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe(pngImageFixture());

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/photo.png'
        && str_contains($request->header('User-Agent')[0], 'Mozilla/5.0'));
});

test('handle derives the extension from the detected content type', function () {
    Storage::fake('local');

    Http::fake([
        'https://example.com/*' => Http::response(gifImageFixture(), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/animation']));

    expect($result)->toContain('.gif');

    preg_match('/\[IMAGE:([^\]]+)\]/', $result, $matches);

    Storage::disk('local')->assertExists($matches[1]);
});

test('handle rejects non-public URLs without making an HTTP request', function (string $url) {
    Http::fake();

    $result = (new DownloadImageTool)->handle(new Request(['url' => $url]));

    expect($result)->toBeString()
        ->toStartWith('Error: URL [')
        ->toContain('not allowed');

    Http::assertNothingSent();
})->with([
    'loopback ip' => 'http://127.0.0.1/image.jpg',
    'localhost hostname' => 'http://localhost/image.jpg',
    'private ip' => 'http://192.168.1.1/image.jpg',
    'non-http scheme' => 'ftp://example.com/image.jpg',
    'decimal integer loopback' => 'http://2130706433/image.jpg',
    'hexadecimal loopback' => 'http://0x7f000001/image.jpg',
    'octal dotted loopback' => 'http://0177.0.0.1/image.jpg',
    'shorthand dotted loopback' => 'http://127.1/image.jpg',
    'shorthand dotted-decimal loopback' => 'http://127.0.1/image.jpg',
    'hex dotted loopback' => 'http://0x7f.0.0.1/image.jpg',
]);

test('handle rejects content that is not an image', function (string $body, string $contentType) {
    Storage::fake('local');

    Http::fake([
        'https://example.com/*' => Http::response($body, 200, ['Content-Type' => $contentType]),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/not-an-image']));

    expect($result)->toBe('Error: the content at [https://example.com/not-an-image] is not a supported image. Only JPEG, PNG, GIF and WebP images are allowed.');

    Storage::disk('local')->assertDirectoryEmpty('telegram-media');
})->with([
    'html body' => ['<!DOCTYPE html><html><body>this is a page, not an image</body></html>', 'text/html'],
    'plain text' => ['this is just plain text', 'text/plain'],
]);

test('handle returns a readable error when the download fails', function () {
    Http::fake([
        'https://example.com/*' => Http::response('internal error', 500),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/image.jpg']));

    expect($result)->toBe('Error: could not download the image from [https://example.com/image.jpg]. Please make sure the URL is correct and publicly accessible.');

    Http::assertSentCount(1);
});

test('handle rejects oversized content', function () {
    Storage::fake('local');

    $maxSize = (new ReflectionClass(DownloadImageTool::class))->getConstant('MAX_FILE_SIZE');

    Http::fake([
        'https://example.com/*' => Http::response(str_repeat('a', $maxSize + 1), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/big.png']));

    expect($result)->toBe('Error: the image is too large to download.');

    Storage::disk('local')->assertDirectoryEmpty('telegram-media');
});

test('handle refuses to follow a redirect to a non-public host and stores nothing', function (string $target) {
    Storage::fake('local');

    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => $target]),
        '*' => Http::response('should never be requested', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/photo.png']));

    expect($result)->toBeString()
        ->toContain('Error: could not download the image from [https://example.com/photo.png].')
        ->toContain('non-public');

    Storage::disk('local')->assertDirectoryEmpty('telegram-media');
    Http::assertNotSent(fn ($request) => $request->url() === $target);
})->with([
    'loopback ip' => 'http://127.0.0.1/secret',
    'metadata service ip' => 'http://169.254.169.254/latest/meta-data/',
]);

test('handle rejects a response whose Content-Length header already exceeds the limit', function () {
    Storage::fake('local');

    $maxSize = (new ReflectionClass(DownloadImageTool::class))->getConstant('MAX_FILE_SIZE');

    Http::fake([
        'https://example.com/*' => Http::response('', 200, [
            'Content-Length' => (string) ($maxSize + 1),
            'Content-Type' => 'image/png',
        ]),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/huge.png']));

    expect($result)->toBe('Error: the image is too large to download.');

    Storage::disk('local')->assertDirectoryEmpty('telegram-media');
});

test('handle rejects an empty or missing url argument without making a request', function () {
    Http::fake();

    $tool = new DownloadImageTool;

    expect($tool->handle(new Request))->toBe('Error: missing required "url" argument.')
        ->and($tool->handle(new Request(['url' => '   '])))->toBe('Error: missing required "url" argument.');

    Http::assertNothingSent();
});
