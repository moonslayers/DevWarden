<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class DuckDuckGoImageSearchTool implements Tool
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIMEOUT_SECONDS = 15;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search DuckDuckGo for images matching a query. Returns a plain-text ranked list, each entry with a title, the DIRECT image file URL (the actual image, not a thumbnail or a page URL), the source page URL, and a short description/size. These are direct image URLs meant to be downloaded and sent as an image — pick one and pass it to the download tool with the chosen URL.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Image search failed: no query provided.';
        }

        $limit = max(1, min(10, $request->integer('limit', 5)));

        $vqd = $this->obtainVqd($query);

        if ($vqd === null) {
            return 'Image search failed: DuckDuckGo is currently unavailable. Please try again later.';
        }

        $results = $this->searchImages($query, $limit, $vqd);

        if ($results === null) {
            return 'Image search failed: DuckDuckGo is currently unavailable. Please try again later.';
        }

        if (empty($results)) {
            return "No images found for \"{$query}\".";
        }

        $lines = [];

        foreach (array_values($results) as $index => $result) {
            $lines[] = sprintf('[%d] %s', $index + 1, $result['title']);
            $lines[] = 'Image URL: '.$result['image'];
            $lines[] = 'Source: '.$result['source'];

            if ($result['description'] !== '') {
                $lines[] = 'Description: '.$result['description'];
            }

            if ($result['width'] !== null && $result['height'] !== null) {
                $lines[] = sprintf('Size: %dx%d', $result['width'], $result['height']);
            }

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
     * Obtain a vqd token from the image search page, or null on failure.
     */
    protected function obtainVqd(string $query): ?string
    {
        $html = $this->fetchSearchPage($query);

        if ($html === null) {
            return null;
        }

        return $this->extractVqd($html);
    }

    /**
     * Fetch the DuckDuckGo image search page HTML for the given query.
     */
    protected function fetchSearchPage(string $query): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get('https://duckduckgo.com/', [
                    'q' => $query,
                    'iax' => 'images',
                    'ia' => 'images',
                ]);

            return $response->status() === 200 ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract the vqd token embedded in the DuckDuckGo image search page.
     */
    protected function extractVqd(string $html): ?string
    {
        $patterns = [
            '/vqd\s*=\s*["\']([^"\']+)["\']/i',
            '/vqd\s*=\s*(\d+-\d+)/i',
            '/"vqd"\s*:\s*"([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Search DuckDuckGo's image JSON endpoint, or return null on failure.
     *
     * @return array<int, array{title: string, image: string, source: string, description: string, width: int|null, height: int|null}>|null
     */
    protected function searchImages(string $query, int $limit, string $vqd): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get('https://duckduckgo.com/i.js', [
                    'q' => $query,
                    'vqd' => $vqd,
                    'o' => 'json',
                ]);

            if ($response->status() !== 200) {
                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['results']) || ! is_array($data['results'])) {
                return [];
            }

            $images = [];

            foreach ($data['results'] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $image = trim((string) ($result['image'] ?? ''));

                if ($image === '') {
                    continue;
                }

                $images[] = [
                    'title' => trim((string) ($result['title'] ?? 'Untitled')),
                    'image' => $image,
                    'source' => trim((string) ($result['url'] ?? '')),
                    'description' => trim((string) ($result['content'] ?? '')),
                    'width' => isset($result['width']) ? (int) $result['width'] : null,
                    'height' => isset($result['height']) ? (int) $result['height'] : null,
                ];

                if (count($images) >= $limit) {
                    break;
                }
            }

            return $images;
        } catch (Throwable) {
            return null;
        }
    }
}
