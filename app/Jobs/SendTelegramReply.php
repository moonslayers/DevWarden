<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramHtmlFormatter;
use App\Services\Telegram\ThinkingIndicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Send a single Telegram message to a chat.
 *
 * Split from the AI-generation job so a send failure only retries the cheap
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
 *
 * When a placeholderMessageId is provided (the "thinking" placeholder sent
 * before the AI call), the placeholder is replaced in place with the final
 * reply instead of sending a new message; photo replies dismiss it before the
 * photo is sent, and empty replies dismiss it so no orphaned placeholder is
 * left in the chat. With no placeholder id the job behaves exactly as before.
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

    /**
     * @param  int  $chatId  The Telegram chat id to send the reply to.
     * @param  string|null  $text  The raw AI reply text, possibly containing an image marker.
     * @param  int|null  $placeholderMessageId  Message id of a "thinking" placeholder to replace
     *                                          with the final reply, or to dismiss when the reply
     *                                          is empty or a photo; null sends a fresh message.
     */
    public function __construct(
        public int $chatId,
        public ?string $text,
        public ?int $placeholderMessageId = null,
    ) {
        //
    }

    public function handle(TelegramClient $telegram, TelegramHtmlFormatter $formatter, ThinkingIndicator $indicator): void
    {
        if ($this->text === null || $this->text === '') {
            $this->dismissPlaceholder($telegram, $indicator);

            return;
        }

        $relativePath = $this->imageMarkerPath($this->text);

        if ($relativePath === null) {
            $this->sendTextMessage($telegram, $formatter, $indicator, $this->text);

            return;
        }

        $caption = $this->stripImageMarker($this->text);

        if (! $this->isSafeImagePath($relativePath)) {
            $this->sendTextMessage($telegram, $formatter, $indicator, $caption);

            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            $this->sendTextMessage($telegram, $formatter, $indicator, $caption);

            return;
        }

        $this->dismissPlaceholder($telegram, $indicator);

        $formattedCaption = $formatter->format($caption);

        if (trim($formattedCaption) === '') {
            $telegram->sendPhoto($this->chatId, $disk->path($relativePath));
        } else {
            $telegram->sendPhoto($this->chatId, $disk->path($relativePath), $this->truncateHtml($formattedCaption), 'HTML');
        }

        $disk->delete($relativePath);
    }

    /**
     * Send the formatted text as a message, replacing an existing placeholder
     * with the final reply when one was sent. When the rendered HTML is empty
     * the send is skipped so Telegram never receives an empty message, and the
     * placeholder is dismissed instead of being left orphaned in the chat.
     */
    private function sendTextMessage(TelegramClient $telegram, TelegramHtmlFormatter $formatter, ThinkingIndicator $indicator, string $text): void
    {
        $html = $formatter->format($text);

        if ($html === null || trim($html) === '') {
            $this->dismissPlaceholder($telegram, $indicator);

            return;
        }

        if ($this->placeholderMessageId !== null) {
            $indicator->replace($telegram, $this->chatId, $this->placeholderMessageId, $html, 'HTML');

            return;
        }

        $telegram->sendMessage($this->chatId, $html, 'HTML');
    }

    /**
     * Delete the thinking placeholder when one was sent, so it never lingers in
     * the chat. Never throws (the indicator swallows Telegram failures).
     */
    private function dismissPlaceholder(TelegramClient $telegram, ThinkingIndicator $indicator): void
    {
        if ($this->placeholderMessageId !== null) {
            $indicator->dismiss($telegram, $this->chatId, $this->placeholderMessageId);
        }
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
     * Truncate a formatted HTML caption to Telegram's 1024-character limit
     * while keeping every tag balanced. A tag hanging at a cut boundary is
     * dropped and any tags left open by the final cut are closed, so the Bot
     * API never receives unbalanced HTML (which would trigger HTTP 400).
     */
    private function truncateHtml(string $html): string
    {
        $max = self::PHOTO_CAPTION_MAX_LENGTH;

        if (mb_strlen($html) <= $max) {
            return $html;
        }

        $cut = mb_substr($html, 0, $max);
        $closing = '';

        // Converge on a cut that fits its own closing tags. Each pass trims
        // the cut to reserve room for the ellipsis and the closing tags, drops
        // any tag hanging at the cut boundary (a second re-cut can chop into a
        // complete tag and leave a dangling '<' that would trip the Bot API),
        // then recomputes the closing tags from the trimmed cut. The cut only
        // shrinks, so the loop terminates; on exit the final length is at most
        // max because the closing tags are derived from that same final cut.
        while (true) {
            $available = max($max - mb_strlen($closing) - 1, 0);

            $next = mb_substr($cut, 0, $available);

            if (($lastOpen = mb_strrpos($next, '<')) !== false && mb_strpos($next, '>', $lastOpen) === false) {
                $next = mb_substr($next, 0, $lastOpen);
            }

            $nextClosing = implode('', array_map(
                static fn (string $tag): string => "</{$tag}>",
                array_reverse($this->unclosedTags($next)),
            ));

            if ($next === $cut && $nextClosing === $closing) {
                return $next.'…'.$nextClosing;
            }

            $cut = $next;
            $closing = $nextClosing;
        }
    }

    /**
     * @return string[] The tag names left open by the given HTML fragment.
     */
    private function unclosedTags(string $html): array
    {
        $stack = [];

        preg_match_all('/<\/?(strong|em|s|u|code|pre|a)\b[^>]*>/', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $tag = $match[1];

            if (str_starts_with($match[0], '</')) {
                // Closing tag: unwind the stack to its matching opener.
                $index = array_search($tag, array_reverse($stack), true);

                if ($index !== false) {
                    $stack = array_slice($stack, 0, count($stack) - 1 - $index);
                }

                continue;
            }

            $stack[] = $tag;
        }

        return $stack;
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
