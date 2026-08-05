<?php

use App\Ai\Tools\DownloadImageTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

uses(TestCase::class);

test('the image marker emitted by DownloadImageTool satisfies the SendTelegramReply marker contract', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    Storage::fake('local');

    Http::fake([
        'https://example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new DownloadImageTool)->handle(new Request(['url' => 'https://example.com/photo.png']));

    // Same charset/path contract as SendTelegramReply::IMAGE_MARKER_PATTERN,
    // replicated here (not referencing the job's private constant) so the two
    // classes cannot drift apart silently.
    preg_match('/\[IMAGE:([A-Za-z0-9._\/-]+)\]/', $result, $matches);

    expect($matches)->toHaveCount(2);

    $path = $matches[1];

    expect($path)->toStartWith('telegram-media/')
        ->not->toContain('..')
        ->not->toStartWith('/');

    Storage::disk('local')->assertExists($path);
});
