<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramHtmlFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Send a single Telegram message to a chat.
 *
 * Split from ProcessTelegramUpdate so a send failure only retries the cheap
 * HTTP call instead of re-running the expensive AI generation. Dependencies are
 * resolved in handle() instead of the constructor so the job stays serializable
 * for the queue (TelegramClient holds a Guzzle client with closures).
 *
 * When the reply text contains an [IMAGE:<relative-path>] marker (a path on the
 * local disk, e.g. telegram-media/abc123.jpg), the job sends the image as a
 * photo with the remaining text as its caption instead of a text message.
 *
 * If the formatted HTML renders to nothing (e.g. only a horizontal rule or raw
 * HTML stripped by the formatter), the job returns early and skips the send so
 * the Telegram API never receives an empty message.
 *
 * Image markers are validated before any filesystem access: the path must stay
 * confined under telegram-media/ on the local disk and must not contain
 * traversal segments, otherwise the marker is dropped and the text is sent as
 * a message instead. Photo captions are truncated to Telegram's 1024-character
 * limit.
 */
class SendTelegramReply implements ShouldQueue
{
    use Queueable;

    /**
     * Matches an image marker anywhere in the reply, capturing the relative
     * path on the local disk (word chars, dashes, dots, underscores, slashes).
     */
    private const IMAGE_MARKER_PATTERN = '/\[IMAGE:([A-Za-z0-9._\/-]+)\]/';

    /**
     * Relative directory on the local disk where downloaded images live.
     */
    private const IMAGE_STORAGE_DIRECTORY = 'telegram-media';

    /**
     * Telegram's maximum caption length for a photo message, in characters.
     */
    private const PHOTO_CAPTION_MAX_LENGTH = 1024;

    /**
     * Attempts per job; the send path is cheap so it retries aggressively.
     */
    public int $tries = 5;

    /**
     * Exponential backoff between retries, in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * Give up after this many uncaught exceptions (Telegram API is down, etc.).
     */
    public int $maxExceptions = 5;

    public function __construct(
        public int $chatId,
        public ?string $text,
    ) {
        //
    }

    public function handle(TelegramClient $telegram, TelegramHtmlFormatter $formatter): void
    {
        if ($this->text === null || $this->text === '') {
            return;
        }

        $relativePath = $this->imageMarkerPath($this->text);

        if ($relativePath === null) {
            $this->sendTextMessage($telegram, $formatter, $this->text);

            return;
        }

        $caption = $this->stripImageMarker($this->text);

        if (! $this->isSafeImagePath($relativePath)) {
            $this->sendTextMessage($telegram, $formatter, $caption);

            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            $this->sendTextMessage($telegram, $formatter, $caption);

            return;
        }

        $telegram->sendPhoto($this->chatId, $disk->path($relativePath), $this->truncateCaption($caption));

        $disk->delete($relativePath);
    }

    /**
     * Send the formatted text as a message, skipping the send entirely when the
     * rendered HTML is empty so Telegram never receives an empty message.
     */
    private function sendTextMessage(TelegramClient $telegram, TelegramHtmlFormatter $formatter, string $text): void
    {
        $html = $formatter->format($text);

        if ($html === null || trim($html) === '') {
            return;
        }

        $telegram->sendMessage($this->chatId, $html, 'HTML');
    }

    /**
     * The marker path is safe only when it stays confined under telegram-media/
     * on the local disk: no leading separator, no backslashes, and no parent
     * directory segments that Flysystem would otherwise resolve upwards.
     */
    private function isSafeImagePath(string $path): bool
    {
        if (! str_starts_with($path, self::IMAGE_STORAGE_DIRECTORY.'/')) {
            return false;
        }

        if (str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip every image marker, leaving only the caption text.
     */
    private function stripImageMarker(string $text): string
    {
        return trim((string) preg_replace(self::IMAGE_MARKER_PATTERN, '', $text));
    }

    /**
     * Truncate the photo caption to Telegram's 1024-character limit, appending
     * an ellipsis when content was cut off.
     */
    private function truncateCaption(string $caption): string
    {
        if (mb_strlen($caption) <= self::PHOTO_CAPTION_MAX_LENGTH) {
            return $caption;
        }

        return mb_substr($caption, 0, self::PHOTO_CAPTION_MAX_LENGTH - 1).'…';
    }

    /**
     * Extract the relative path from the first [IMAGE:...] marker, if any.
     */
    private function imageMarkerPath(string $text): ?string
    {
        if (preg_match(self::IMAGE_MARKER_PATTERN, $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
