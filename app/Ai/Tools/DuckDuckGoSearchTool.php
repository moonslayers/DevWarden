<?php

namespace App\Ai\Tools;

use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class DuckDuckGoSearchTool implements Tool
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIMEOUT_SECONDS = 15;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search the web using DuckDuckGo. Returns a plain-text list of ranked results, each with a title, its URL, and a short description. Use the returned URLs to fetch and read the exact pages.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Search failed: no query provided.';
        }

        $limit = max(1, min(10, $request->integer('limit', 5)));

        $results = $this->searchHtml($query, $limit);
        $failed = $results === null;

        if (empty($results)) {
            $results = $this->searchLite($query, $limit);
            $failed = $failed && $results === null;
        }

        if ($failed) {
            return 'Search failed: DuckDuckGo is currently unavailable. Please try again later.';
        }

        if (empty($results)) {
            return "No results found for \"{$query}\".";
        }

        $lines = [];

        foreach (array_values($results) as $index => $result) {
            $lines[] = sprintf('[%d] %s', $index + 1, $result['title']);
            $lines[] = 'URL: '.$result['url'];
            $lines[] = 'Description: '.$result['snippet'];

            if ($index < count($results) - 1) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'limit' => $schema->integer()->default(5)->min(1)->max(10),
        ];
    }

    /**
     * Search DuckDuckGo's HTML endpoint.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>|null
     */
    protected function searchHtml(string $query, int $limit): ?array
    {
        $html = $this->fetch('https://html.duckduckgo.com/html/', $query);

        if ($html === null) {
            return null;
        }

        return $this->parseResults(
            $html,
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')]",
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' result__snippet ')]",
            $limit
        );
    }

    /**
     * Search DuckDuckGo's lite endpoint.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>|null
     */
    protected function searchLite(string $query, int $limit): ?array
    {
        $html = $this->fetch('https://lite.duckduckgo.com/lite/', $query);

        if ($html === null) {
            return null;
        }

        return $this->parseResults(
            $html,
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' result-link ')]",
            "//td[contains(concat(' ', normalize-space(@class), ' '), ' result-snippet ')]",
            $limit
        );
    }

    /**
     * Fetch the HTML for the given query, or return null on failure.
     */
    protected function fetch(string $endpoint, string $query): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($endpoint, ['q' => $query]);

            return $response->status() === 200 ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse DuckDuckGo result markup into a plain array.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function parseResults(string $html, string $titleSelector, string $snippetSelector, int $limit): array
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $titleNodes = $xpath->query($titleSelector);
        $snippetNodes = $xpath->query($snippetSelector);

        $results = [];

        foreach ($titleNodes as $index => $node) {
            $snippetNode = $snippetNodes->item($index);

            $results[] = [
                'title' => $this->cleanText($node->textContent),
                'url' => $this->resolveUrl($node->getAttribute('href')),
                'snippet' => $snippetNode ? $this->cleanText($snippetNode->textContent) : '',
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Resolve a DuckDuckGo href to the real destination URL.
     */
    protected function resolveUrl(string $href): string
    {
        if (str_contains($href, 'uddg=')) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $params);

            if (isset($params['uddg']) && $params['uddg'] !== '') {
                return (string) $params['uddg'];
            }
        }

        return str_starts_with($href, '//') ? 'https:'.$href : $href;
    }

    /**
     * Collapse whitespace (including non-breaking spaces) in scraped text.
     */
    protected function cleanText(string $text): string
    {
        return trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $text) ?? '');
    }
}
