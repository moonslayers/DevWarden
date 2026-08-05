<?php

use App\Services\Telegram\TelegramHtmlFormatter;

test('formats bold markdown with a Telegram-supported strong tag', function () {
    $html = (new TelegramHtmlFormatter)->format('**x**');

    expect($html)->toBe('<strong>x</strong>');
});

test('formats italic markdown with a Telegram-supported em tag', function () {
    $html = (new TelegramHtmlFormatter)->format('*x*');

    expect($html)->toBe('<em>x</em>');
});

test('formats headings as bold text without an unsupported h tag', function () {
    $html = (new TelegramHtmlFormatter)->format('## Título');

    expect($html)->not->toContain('<h2');
    expect($html)->toContain('<strong>Título</strong>');
});

test('formats inline code with a code tag', function () {
    $html = (new TelegramHtmlFormatter)->format('`x`');

    expect($html)->toBe('<code>x</code>');
});

test('formats fenced code blocks with a pre tag', function () {
    $html = (new TelegramHtmlFormatter)->format("```\ncode\n```");

    expect($html)->toContain('<pre>');
    expect($html)->toContain('code');
    expect($html)->toEndWith('</pre>');
});

test('formats safe links with an anchor tag', function () {
    $html = (new TelegramHtmlFormatter)->format('[ok](https://example.com)');

    expect($html)->toBe('<a href="https://example.com">ok</a>');
});

test('drops unsafe links but keeps their label text', function () {
    $html = (new TelegramHtmlFormatter)->format('[mal](javascript:alert(1))');

    expect($html)->not->toContain('javascript:');
    expect($html)->not->toContain('<a');
    expect($html)->toBe('mal');
});

test('strips raw HTML instead of leaking the tags', function () {
    $formatter = new TelegramHtmlFormatter;

    expect($formatter->format('<b>crudo</b>'))->toBe('crudo');
    expect($formatter->format('<script>alert(1)</script>'))->toBe('');
});

test('formats list items as plain lines with a bullet prefix', function () {
    $html = (new TelegramHtmlFormatter)->format('- item');

    expect($html)->toBe('• item');
    expect($html)->not->toContain('<ul');
    expect($html)->not->toContain('<li');
});

test('renders horizontal rules as nothing instead of an hr tag', function () {
    $html = (new TelegramHtmlFormatter)->format('---');

    expect($html)->toBe('');
    expect($html)->not->toContain('<hr');
});

test('never emits tags unsupported by Telegram HTML mode', function (string $tag) {
    $markdown = <<<'MD'
        # Título 1
        ## Título 2
        - uno
        - dos
        > cita
        ---
        | A | B |
        |---|---|
        | 1 | 2 |
        ![imagen](https://example.com/a.png)
        Texto con `codigo` y **negrita**.
        MD;

    $html = (new TelegramHtmlFormatter)->format($markdown);

    expect($html)->not->toContain($tag);
})->with([
    'heading h1' => '<h1',
    'heading h2' => '<h2',
    'heading h3' => '<h3',
    'heading h4' => '<h4',
    'heading h5' => '<h5',
    'heading h6' => '<h6',
    'unordered list' => '<ul',
    'ordered list' => '<ol',
    'table' => '<table',
    'blockquote' => '<blockquote',
    'horizontal rule' => '<hr',
    'image' => '<img',
    'line break' => '<br',
    'paragraph' => '<p',
]);

test('formats strikethrough with a Telegram-supported s tag', function () {
    $html = (new TelegramHtmlFormatter)->format('~~x~~');

    expect($html)->toBe('<s>x</s>');
    expect($html)->not->toContain('~~');
});

test('formats ordered lists as plain lines with numeric prefixes', function () {
    $html = (new TelegramHtmlFormatter)->format("1. one\n2. two");

    expect($html)->toBe("1. one\n2. two");
    expect($html)->not->toContain('<ol');
    expect($html)->not->toContain('<li');
});

test('escapes HTML-sensitive content inside fenced code blocks', function () {
    $html = (new TelegramHtmlFormatter)->format("```php\n<?php echo 1; ?>\n```");

    expect($html)->toContain('&lt;?php');
    expect($html)->not->toContain('<?php');
    expect($html)->toContain('</pre>');
});

test('formats autolinks as anchor tags for safe URLs', function () {
    $html = (new TelegramHtmlFormatter)->format('<https://example.com>');

    expect($html)->toBe('<a href="https://example.com">https://example.com</a>');
});

test('never renders unsafe autolinks with a javascript href', function () {
    $html = (new TelegramHtmlFormatter)->format('<javascript:alert(1)>');

    expect($html)->not->toContain('href="javascript:');
    expect($html)->not->toContain('<a ');
});

test('round-trips HTML entities without leaking raw ampersands', function () {
    $html = (new TelegramHtmlFormatter)->format('&amp;');

    expect($html)->toBe('&amp;');
});

test('keeps encoded HTML tags escaped instead of leaking raw tags', function () {
    $html = (new TelegramHtmlFormatter)->format('&lt;b&gt;');

    expect($html)->toBe('&lt;b&gt;');
    expect($html)->not->toContain('<b>');
});

test('formats safe images as their label followed by the image url', function () {
    $html = (new TelegramHtmlFormatter)->format('![alt](https://example.com/i.png)');

    expect($html)->toBe('alt (https://example.com/i.png)');
});

test('drops unsafe image urls but keeps the alt text', function () {
    $html = (new TelegramHtmlFormatter)->format('![x](javascript:alert(1))');

    expect($html)->toBe('x');
    expect($html)->not->toContain('javascript:');
    expect($html)->not->toContain('href="');
});

test('returns an empty string for empty or whitespace-only input', function (string $input) {
    $html = (new TelegramHtmlFormatter)->format($input);

    expect($html)->toBe('');
})->with([
    'empty string' => '',
    'whitespace only' => '   ',
]);

test('renders unclosed emphasis markers without emitting unbalanced tags', function () {
    $html = (new TelegramHtmlFormatter)->format('**bold');

    expect($html)->not->toContain('<strong>');
    expect($html)->not->toContain('</strong>');
    expect(substr_count($html, '<strong>'))->toBe(substr_count($html, '</strong>'));
});

test('returns empty output for content made only of unsupported constructs', function (string $input) {
    $html = (new TelegramHtmlFormatter)->format($input);

    expect($html)->toBe('');
})->with([
    'horizontal rule only' => '---',
    'stripped script only' => '<script>x</script>',
]);
