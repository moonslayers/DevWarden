<?php

use App\Ai\Tools\DuckDuckGoSearchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

uses(TestCase::class);

function realisticDuckDuckGoFixture(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DuckDuckGo Search</title></head>
<body>
<div class="result results_links results_links_deep web-result">
    <h2 class="result__title">
        <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Flaravel.com%2Fdocs%2F12.x%2Frate-limiting&amp;rut=abc123">Rate Limiting - Laravel 12.x</a>
    </h2>
    <a class="result__snippet" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Flaravel.com%2Fdocs%2F12.x%2Frate-limiting">Learn how to rate limit inbound requests to your Laravel application.</a>
</div>
<div class="result results_links results_links_deep web-result">
    <h2 class="result__title">
        <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FWeb_development&amp;rut=def456">Web development - Wikipedia</a>
    </h2>
    <a class="result__snippet" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FWeb_development">Web development is the work involved in developing a website for the Internet.</a>
</div>
</body>
</html>
HTML;
}

test('handle queries the html endpoint and returns titles, real URLs and snippets', function () {
    Http::fake([
        'https://html.duckduckgo.com/html/*' => Http::response(realisticDuckDuckGoFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoSearchTool)->handle(new Request(['query' => 'laravel rate limiting']));

    expect($result)->toBeString()
        ->toContain('[1] Rate Limiting - Laravel 12.x')
        ->toContain('URL: https://laravel.com/docs/12.x/rate-limiting')
        ->toContain('Description: Learn how to rate limit inbound requests to your Laravel application.')
        ->toContain('[2] Web development - Wikipedia')
        ->toContain('URL: https://en.wikipedia.org/wiki/Web_development')
        ->not->toContain('uddg')
        ->not->toContain('duckduckgo.com/l/');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'https://html.duckduckgo.com/html/')
        && $request['q'] === 'laravel rate limiting');
});

test('handle honors the limit argument', function () {
    Http::fake([
        'https://html.duckduckgo.com/html/*' => Http::response(realisticDuckDuckGoFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new DuckDuckGoSearchTool)->handle(new Request(['query' => 'laravel', 'limit' => 1]));

    expect($result)->toContain('[1] Rate Limiting - Laravel 12.x')
        ->not->toContain('[2]');
});

test('handle returns a clear message when the html endpoint has no results and the lite fallback is empty too', function () {
    Http::fake([
        'https://html.duckduckgo.com/html/*' => Http::response('<html><body><p>no results</p></body></html>', 200),
        'https://lite.duckduckgo.com/lite/*' => Http::response('<html><body><p>no results</p></body></html>', 200),
    ]);

    $result = (new DuckDuckGoSearchTool)->handle(new Request(['query' => 'zzzznonexistentzzzz']));

    expect($result)->toBe('No results found for "zzzznonexistentzzzz".');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'https://lite.duckduckgo.com/lite/'));
});

test('handle reports search failure when both endpoints return a non-200 anomaly page', function () {
    Http::fake([
        'https://html.duckduckgo.com/html/*' => Http::response('anomaly', 202),
        'https://lite.duckduckgo.com/lite/*' => Http::response('anomaly', 202),
    ]);

    $result = (new DuckDuckGoSearchTool)->handle(new Request(['query' => 'laravel']));

    expect($result)->toBe('Search failed: DuckDuckGo is currently unavailable. Please try again later.');
});

test('handle rejects an empty query without making any request', function () {
    Http::fake();

    $result = (new DuckDuckGoSearchTool)->handle(new Request);

    expect($result)->toBe('Search failed: no query provided.');

    Http::assertNothingSent();
});
