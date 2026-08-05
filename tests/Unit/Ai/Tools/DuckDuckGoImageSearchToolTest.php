<?php

use App\Ai\Tools\DuckDuckGoImageSearchTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

uses(TestCase::class);

function duckDuckGoImageSearchPageFixture(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DuckDuckGo Image Search</title></head>
<body>
<script>
    var vqd = '4-123456789012345678901234567890';
</script>
</body>
</html>
HTML;
}

function duckDuckGoImageResultsFixture(): string
{
    return json_encode([
        'query' => 'laravel logo',
        'results' => [
            [
                'id' => '0',
                'title' => 'Laravel logo PNG',
                'image' => 'https://laravel.com/img/logomark.svg',
                'thumbnail' => 'https://tse1.mm.bing.net/th?id=OIP.abc',
                'url' => 'https://laravel.com/',
                'height' => 512,
                'width' => 512,
                'content' => 'Official Laravel logomark.',
                'source' => 'laravel.com',
            ],
            [
                'id' => '1',
                'title' => 'Laravel crest wallpaper',
                'image' => 'https://example.com/crest.jpg',
                'thumbnail' => 'https://tse2.mm.bing.net/th?id=OIP.def',
                'url' => 'https://example.com/wallpaper',
                'height' => 1080,
                'width' => 1920,
                'content' => 'Wallpaper with the Laravel crest.',
                'source' => 'example.com',
            ],
        ],
    ]);
}

test('handle obtains a vqd token and returns direct image URLs with titles', function () {
    Http::fake([
        'duckduckgo.com/i.js*' => Http::response(duckDuckGoImageResultsFixture(), 200, ['Content-Type' => 'application/json']),
        'duckduckgo.com/*' => Http::response(duckDuckGoImageSearchPageFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'laravel logo']));

    expect($result)->toBeString()
        ->toContain('[1] Laravel logo PNG')
        ->toContain('Image URL: https://laravel.com/img/logomark.svg')
        ->toContain('Source: https://laravel.com/')
        ->toContain('Description: Official Laravel logomark.')
        ->toContain('Size: 512x512')
        ->toContain('[2] Laravel crest wallpaper')
        ->toContain('Image URL: https://example.com/crest.jpg')
        ->not->toContain('tse1.mm.bing.net')
        ->not->toContain('tse2.mm.bing.net');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'duckduckgo.com/i.js')
        && $request['q'] === 'laravel logo'
        && $request['vqd'] === '4-123456789012345678901234567890'
        && $request['o'] === 'json');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'duckduckgo.com/?q=laravel')
        && $request['iax'] === 'images'
        && $request['ia'] === 'images');
});

test('handle honors the limit argument', function () {
    Http::fake([
        'duckduckgo.com/i.js*' => Http::response(duckDuckGoImageResultsFixture(), 200, ['Content-Type' => 'application/json']),
        'duckduckgo.com/*' => Http::response(duckDuckGoImageSearchPageFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'laravel', 'limit' => 1]));

    expect($result)->toContain('[1] Laravel logo PNG')
        ->not->toContain('[2]');
});

test('handle reports no images found when the JSON endpoint returns an empty result set', function () {
    Http::fake([
        'duckduckgo.com/i.js*' => Http::response(json_encode(['results' => []]), 200, ['Content-Type' => 'application/json']),
        'duckduckgo.com/*' => Http::response(duckDuckGoImageSearchPageFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'zzzznonexistentzzzz']));

    expect($result)->toBe('No images found for "zzzznonexistentzzzz".');
});

test('handle reports a readable error when the image search page returns a non-200 anomaly page', function () {
    Http::fake([
        'duckduckgo.com/*' => Http::response('anomaly', 202),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'laravel']));

    expect($result)->toBe('Image search failed: DuckDuckGo is currently unavailable. Please try again later.');
});

test('handle reports a readable error when the JSON endpoint returns a non-200 response', function () {
    Http::fake([
        'duckduckgo.com/i.js*' => Http::response('anomaly', 202),
        'duckduckgo.com/*' => Http::response(duckDuckGoImageSearchPageFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'laravel']));

    expect($result)->toBe('Image search failed: DuckDuckGo is currently unavailable. Please try again later.');
});

test('handle reports a readable error when no vqd token can be extracted', function () {
    Http::fake([
        'duckduckgo.com/*' => Http::response('<html><body><p>no token here</p></body></html>', 200),
    ]);

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request(['query' => 'laravel']));

    expect($result)->toBe('Image search failed: DuckDuckGo is currently unavailable. Please try again later.');
});

test('handle rejects an empty query without making any request', function () {
    Http::fake();

    $result = (new DuckDuckGoImageSearchTool)->handle(new Request);

    expect($result)->toBe('Image search failed: no query provided.');

    Http::assertNothingSent();
});

test('schema exposes a required query and an integer limit defaulting to 5 with min 1 and max 10', function () {
    $properties = (new DuckDuckGoImageSearchTool)->schema(new JsonSchemaTypeFactory);

    $schema = (new ObjectSchema($properties))->toSchema();

    expect($schema['required'])->toContain('query')
        ->and($schema['properties']['query']['type'])->toBe('string')
        ->and($schema['properties']['limit']['type'])->toBe('integer')
        ->and($schema['properties']['limit']['default'])->toBe(5)
        ->and($schema['properties']['limit']['minimum'])->toBe(1)
        ->and($schema['properties']['limit']['maximum'])->toBe(10);
});
