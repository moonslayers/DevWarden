<?php

namespace Tests\Unit\Ai\Tools\Opencode\Support;

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;

/**
 * In-memory stand-in for TelegramClient that never touches the Telegram API.
 *
 * The parent constructor is skipped on purpose because it throws when no bot
 * token is configured; tests only need the sendMessage behavior.
 */
class FakeTelegramClient extends TelegramClient
{
    /** @var array<int, array{chat_id: int|string, text: string, parse_mode: ?string}> */
    public array $sent = [];

    public ?TelegramApiException $error = null;

    public function __construct()
    {
        //
    }

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        $message = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode];

        $this->sent[] = $message;

        return [];
    }
}
