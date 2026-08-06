<?php

namespace App\Console\Commands;

use App\Jobs\HandleTelegramCallbackQuery;
use App\Models\TelegramSetting;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\Exceptions\TelegramNotConfiguredException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramMessageBuffer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('telegram:poll')]
#[Description('Poll Telegram for new updates and buffer authorized text messages')]
class PollTelegramUpdates extends Command
{
    /**
     * Long-poll timeout in seconds passed to getUpdates.
     */
    private const POLL_TIMEOUT = 50;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $settings = TelegramSetting::singleton();

        if (! $settings->polling_enabled) {
            $this->components->info('Telegram polling is disabled.');

            return self::SUCCESS;
        }

        $offset = $settings->last_update_id === null ? 0 : $settings->last_update_id + 1;

        // The client is resolved lazily here, not in the constructor: every
        // artisan invocation eagerly instantiates discovered commands, so a
        // constructor dependency would throw TelegramNotConfiguredException
        // (and break every artisan command) whenever the bot token is missing.
        try {
            $updates = app(TelegramClient::class)->getUpdates(offset: $offset, timeout: self::POLL_TIMEOUT);
        } catch (TelegramNotConfiguredException|TelegramApiException $e) {
            Log::warning('Telegram polling skipped.', ['error' => $e->getMessage()]);

            $this->components->warn('Telegram polling skipped: '.$e->getMessage());

            return self::SUCCESS;
        }

        $allowedChats = array_map('intval', $settings->allowed_user_ids ?? []);
        $lastUpdateId = (int) $settings->last_update_id;
        $buffer = app(TelegramMessageBuffer::class);
        $affectedChats = [];
        $buffered = 0;
        $callbacks = 0;

        foreach ($updates as $update) {
            $lastUpdateId = max($lastUpdateId, (int) $update['update_id']);

            $chatId = $update['chat_id'] ?? null;

            // Inline-button callbacks are not messages: they are routed to their
            // own job (which answers the callback with the outcome) and never
            // reach the debounce buffer. Callbacks from unauthorized chats are
            // discarded, but the offset is still advanced so they never
            // re-deliver.
            if (isset($update['callback_data'])) {
                if ($chatId !== null && in_array((int) $chatId, $allowedChats, true)) {
                    HandleTelegramCallbackQuery::dispatch(
                        (string) $update['callback_query_id'],
                        (int) $chatId,
                        (string) $update['callback_data'],
                        $update['callback_message_id'] ?? null,
                    );

                    $callbacks++;
                }

                continue;
            }

            $messageId = $update['message_id'] ?? null;
            $text = $update['text'] ?? null;
            $photo = $update['photo'] ?? null;
            $isEdit = (bool) ($update['edit'] ?? false);

            // Unauthorized chats, non-text/non-photo updates and updates without
            // a message id are discarded silently, but their offset is still
            // advanced so they never re-deliver.
            if ($chatId === null || $messageId === null || ($text === null && $photo === null) || ! in_array((int) $chatId, $allowedChats, true)) {
                continue;
            }

            $buffer->storeMessage((int) $chatId, $messageId, $text ?? '', (int) $update['update_id'], $isEdit, $photo);

            $affectedChats[(int) $chatId] = true;
            $buffered++;
        }

        // Debounced batch jobs are armed before the offset is persisted: a crash
        // in between re-delivers the buffered batch on the next poll
        // (at-least-once) instead of losing updates, and the buffer upsert is
        // idempotent.
        foreach (array_keys($affectedChats) as $chatId) {
            $buffer->scheduleIfNeeded($chatId);
        }

        if ($lastUpdateId > (int) $settings->last_update_id) {
            $settings->forceFill(['last_update_id' => $lastUpdateId])->save();
        }

        $this->components->info(sprintf('Polled %d update(s); buffered %d message(s) into %d chat(s); dispatched %d callback query job(s).', count($updates), $buffered, count($affectedChats), $callbacks));

        return self::SUCCESS;
    }
}
