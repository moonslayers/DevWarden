<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ValidatesPublicUrl;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FetchWebPageTool implements Tool
{
    use ValidatesPublicUrl;

    /** Maximum number of characters returned to the model. */
    protected const MAX_TEXT_LENGTH = 6000;

    /** Maximum response body size accepted from a URL, in bytes. */
    protected const MAX_BODY_SIZE = 3_000_000;

    /** Timeout for the HTTP request, in seconds. */
    protected const TIMEOUT_SECONDS = 10;

    /** Tags stripped from the page before extracting readable text. */
    protected const STRIP_TAGS = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript'];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Opens a single URL and returns the readable text of the page so you can read the contents of a link. Read-only: it cannot click buttons or interact with JavaScript.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $url = $request['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return 'Error: missing required "url" argument.';
        }

        if (! $this->isPublicUrl($url)) {
            return "Error: URL [$url] is not allowed. Only public http(s) URLs are supported; local, private and loopback hosts are blocked.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => true])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; DevWardenBot/1.0)'])
                ->get($url);
        } catch (ConnectionException $e) {
            return "Error: could not reach [$url]. Please make sure the URL is correct and publicly accessible.";
        }

        if ($response->failed()) {
            return "Error: request to [$url] failed with HTTP status {$response->status()}.";
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BODY_SIZE) {
            return 'Error: the page is too large to fetch.';
        }

        if (! $this->looksLikeHtml($response->header('Content-Type'))) {
            return 'Error: the URL does not point to an HTML page.';
        }

        $text = $this->extractReadableText($body);

        if (trim($text) === '') {
            return 'Error: the page contains no readable text.';
        }

        return $this->truncate($text);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->required()->format('uri'),
        ];
    }

    /**
     * Determine whether the given Content-Type header points to an HTML document.
     */
    protected function looksLikeHtml(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return true;
        }

        $type = strtolower(explode(';', $contentType)[0]);

        return in_array(trim($type), ['text/html', 'application/xhtml+xml'], true);
    }

    /**
     * Extract readable text from an HTML body.
     */
    protected function extractReadableText(string $html): string
    {
        $internalErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);

            foreach (self::STRIP_TAGS as $tag) {
                foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $element) {
                    $element->parentNode?->removeChild($element);
                }
            }

            $title = $dom->getElementsByTagName('title')->item(0)?->textContent;

            $container = $dom->getElementsByTagName('main')->item(0)
                ?? $dom->getElementsByTagName('article')->item(0)
                ?? $dom->getElementsByTagName('body')->item(0);

            $text = $container instanceof DOMElement
                ? $container->textContent
                : '';

            if ($title !== null && trim($title) !== '') {
                $text = 'Title: '.trim($title)."\n\n".$text;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        return $this->normalizeWhitespace($text);
    }

    /**
     * Collapse repeated spaces and blank lines.
     */
    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Truncate the text to the maximum length, marking truncated output.
     */
    protected function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_TEXT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_TEXT_LENGTH)."\n\n...[truncated]";
    }
}
