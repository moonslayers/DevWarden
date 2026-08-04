<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send a single Telegram message to a chat.
 *
 * Split from ProcessTelegramUpdate so a send failure only retries the cheap
 * HTTP call instead of re-running the expensive AI generation. Dependencies are
 * resolved in handle() instead of the constructor so the job stays serializable
 * for the queue (TelegramClient holds a Guzzle client with closures).
 */
class SendTelegramReply implements ShouldQueue
{
    use Queueable;

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

    public function handle(TelegramClient $telegram): void
    {
        if ($this->text === null || $this->text === '') {
            return;
        }

        $telegram->sendMessage($this->chatId, $this->text);
    }
}
