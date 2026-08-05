<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ValidatesPublicUrl;
use finfo;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class DownloadImageTool implements Tool
{
    use ValidatesPublicUrl;

    /** Browser-like User-Agent sent with downloads. */
    protected const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Maximum accepted image size in bytes. */
    protected const MAX_FILE_SIZE = 5_000_000;

    /** Timeout for the HTTP request, in seconds. */
    protected const TIMEOUT_SECONDS = 15;

    /** Maximum number of redirects to follow manually. */
    protected const MAX_REDIRECTS = 3;

    /** Bytes read per chunk while streaming a download. */
    protected const READ_CHUNK_BYTES = 8192;

    /** Map of allowed image MIME types to their file extensions. */
    protected const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Downloads an image from a direct image file URL, verifies it is really an image, stores it locally, and marks it ready to send to the user. Pass the DIRECT image file URL returned by the image search tool (not a page URL). On success you MUST end your final reply with exactly the marker [IMAGE:<path>] followed by an optional short caption so the bot can send the image.';
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

        $url = trim($url);

        if (! $this->isPublicUrl($url)) {
            return "Error: URL [$url] is not allowed. Only public http(s) URLs are supported; local, private and loopback hosts are blocked.";
        }

        $result = $this->download($url);

        if (isset($result['error'])) {
            return $result['error'];
        }

        $bytes = $result['bytes'];

        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            return 'Error: the image is too large to download.';
        }

        $extension = $this->detectImageExtension($bytes);

        if ($extension === null) {
            return "Error: the content at [$url] is not a supported image. Only JPEG, PNG, GIF and WebP images are allowed.";
        }

        $path = $this->store($extension, $bytes);

        if ($path === null) {
            return 'Error: could not store the downloaded image.';
        }

        return "Image downloaded and stored at {$path}. "
            ."To send this image to the user, end your final reply with exactly the marker [IMAGE:{$path}] followed by an optional short caption. "
            ."For example: [IMAGE:{$path}] Here is the image you asked for.";
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
     * Download the image bytes, returning the bytes or a readable error.
     *
     * Redirects are followed manually (never blindly), re-validating each
     * target with isPublicUrl() before a request is sent to it.
     *
     * @return array{bytes?: string, error?: string}
     */
    protected function download(string $url): array
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($currentUrl);

            if ($response === null) {
                break;
            }

            if ($this->isRedirect($response)) {
                $response->toPsrResponse()->getBody()->close();

                $target = $this->resolveRedirectTarget($currentUrl, $response->header('Location'));

                if ($hop >= self::MAX_REDIRECTS) {
                    return ['error' => "Error: could not download the image from [$url]. The URL redirects too many times."];
                }

                if ($target === null || ! $this->isPublicUrl($target)) {
                    return ['error' => "Error: could not download the image from [$url]. The URL redirects to a non-public location."];
                }

                $currentUrl = $target;

                continue;
            }

            if ($response->failed()) {
                $response->toPsrResponse()->getBody()->close();

                break;
            }

            return $this->readBounded($response, $url);
        }

        return ['error' => "Error: could not download the image from [$url]. Please make sure the URL is correct and publicly accessible."];
    }

    /**
     * Perform a single HTTP GET without following redirects, or null on failure.
     */
    protected function request(string $url): ?Response
    {
        try {
            return Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Determine whether the response is a redirect that carries a Location header.
     */
    protected function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true)
            && $response->header('Location') !== null;
    }

    /**
     * Resolve a redirect Location header against the current URL, or null if invalid.
     */
    protected function resolveRedirectTarget(string $currentUrl, string $location): ?string
    {
        try {
            return (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read the response body in bounded chunks, returning the bytes or a readable error.
     *
     * @return array{bytes?: string, error?: string}
     */
    protected function readBounded(Response $response, string $url): array
    {
        $contentLength = $response->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > self::MAX_FILE_SIZE) {
            return ['error' => 'Error: the image is too large to download.'];
        }

        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(self::READ_CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;

                if (strlen($bytes) > self::MAX_FILE_SIZE) {
                    $stream->close();

                    return ['error' => 'Error: the image is too large to download.'];
                }
            }
        } catch (Throwable) {
            return ['error' => "Error: could not download the image from [$url]. Please make sure the URL is correct and publicly accessible."];
        }

        return ['bytes' => $bytes];
    }

    /**
     * Detect the allowed image extension from the actual bytes, or null if not a supported image.
     */
    protected function detectImageExtension(string $bytes): ?string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return self::IMAGE_EXTENSIONS[$mime] ?? null;
    }

    /**
     * Store the image on the default local disk, returning its relative path or null on failure.
     */
    protected function store(string $extension, string $bytes): ?string
    {
        try {
            $path = 'telegram-media/'.Str::uuid()->toString().'.'.$extension;

            return Storage::disk('local')->put($path, $bytes) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }
}
