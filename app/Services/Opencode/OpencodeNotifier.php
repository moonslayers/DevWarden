<?php

namespace App\Services\Opencode;

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\Exceptions\TelegramNotConfiguredException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramHtmlFormatter;
use Illuminate\Support\Facades\Log;

/**
 * Sends workflow notifications to the owner's Telegram chat.
 *
 * The client is resolved lazily (never constructor-injected) so a missing bot
 * token cannot break service resolution — the same lazy pattern the console
 * commands use. Returns whether the message was actually handed to Telegram so
 * callers can decide whether to advance workflow state.
 */
class OpencodeNotifier
{
    public function __construct(
        private readonly TelegramHtmlFormatter $formatter,
    ) {}

    public function notify(int $chatId, string $markdown, ?array $inlineKeyboard = null): bool
    {
        $html = $this->formatter->format($markdown);

        if (trim($html) === '') {
            return false;
        }

        try {
            $telegram = app(TelegramClient::class);
        } catch (TelegramNotConfiguredException $e) {
            Log::warning('Opencode notification skipped: bot token not configured.', ['error' => $e->getMessage()]);

            return false;
        }

        try {
            $telegram->sendMessage($chatId, $html, 'HTML', $inlineKeyboard);
        } catch (TelegramApiException $e) {
            Log::warning('Opencode notification failed to send.', ['error' => $e->getMessage()]);

            return false;
        }

        return true;
    }
}
