<?php

use App\Ai\Tools\FetchWebPageTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use Tests\TestCase;

uses(TestCase::class);

function realisticWebPageFixture(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Landing Page</title>
    <style>body { color: red; }</style>
    <script>console.log('secret script content');</script>
</head>
<body>
    <header>Header banner content here</header>
    <nav>Navigation links content</nav>
    <main>
        <h1>Main Article Heading</h1>
        <p>This is the main body paragraph that should appear in the extracted text.</p>
        <nav>Nav inside main should be stripped</nav>
        <script>alert('script inside main should be stripped');</script>
    </main>
    <footer>Footer legal content</footer>
    <noscript>Noscript fallback content</noscript>
</body>
</html>
HTML;
}

test('handle extracts readable text and strips script, style, nav, header, footer and noscript', function () {
    Http::fake([
        'https://example.com/*' => Http::response(realisticWebPageFixture(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new FetchWebPageTool)->handle(new Request(['url' => 'https://example.com/article']));

    expect($result)->toBeString()
        ->toContain('Title: Test Landing Page')
        ->toContain('Main Article Heading')
        ->toContain('This is the main body paragraph that should appear in the extracted text.')
        ->not->toContain('secret script content')
        ->not->toContain('Nav inside main should be stripped')
        ->not->toContain('script inside main should be stripped')
        ->not->toContain('Header banner content here')
        ->not->toContain('Navigation links content')
        ->not->toContain('Footer legal content')
        ->not->toContain('Noscript fallback content');
});

test('handle truncates text longer than the maximum length with the truncation marker', function () {
    $maxLength = (new ReflectionClass(FetchWebPageTool::class))->getConstant('MAX_TEXT_LENGTH');

    $longText = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 250);

    $html = '<!DOCTYPE html><html><head><title>Long Page</title></head>'
        .'<body><main><p>'.$longText.'</p></main></body></html>';

    Http::fake([
        'https://example.com/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new FetchWebPageTool)->handle(new Request(['url' => 'https://example.com/long']));

    expect($result)->toEndWith('...[truncated]')
        ->and(mb_strlen($result))->toBe($maxLength + mb_strlen("\n\n...[truncated]"));
});

test('handle keeps short pages intact without the truncation marker', function () {
    Http::fake([
        'https://example.com/*' => Http::response('<html><head><title>Short</title></head><body><main><p>Short content.</p></main></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new FetchWebPageTool)->handle(new Request(['url' => 'https://example.com/short']));

    expect($result)->toContain('Title: Short')
        ->toContain('Short content.')
        ->not->toContain('...[truncated]');
});

test('handle blocks non-public URLs without making an HTTP request', function (string $url) {
    Http::fake();

    $result = (new FetchWebPageTool)->handle(new Request(['url' => $url]));

    expect($result)->toBeString()
        ->toStartWith('Error: URL [')
        ->toContain('not allowed');

    Http::assertNothingSent();
})->with([
    'loopback ip' => 'http://127.0.0.1/',
    'localhost hostname' => 'http://localhost/',
    'private ip' => 'http://192.168.1.1/',
    'non-http scheme' => 'ftp://example.com/file',
    'decimal integer loopback' => 'http://2130706433/',
    'hexadecimal loopback' => 'http://0x7f000001/',
    'octal dotted loopback' => 'http://0177.0.0.1/',
    'shorthand dotted loopback' => 'http://127.1/',
    'shorthand dotted-decimal loopback' => 'http://127.0.1/',
    'hex dotted loopback' => 'http://0x7f.0.0.1/',
]);

test('handle returns a clear error when the url argument is missing', function () {
    Http::fake();

    $result = (new FetchWebPageTool)->handle(new Request);

    expect($result)->toBe('Error: missing required "url" argument.');

    Http::assertNothingSent();
});
