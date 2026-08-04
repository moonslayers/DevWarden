<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramSetting;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\Exceptions\TelegramNotConfiguredException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('telegram:poll')]
#[Description('Poll Telegram for new updates and enqueue authorized text messages')]
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
        $dispatched = 0;

        foreach ($updates as $update) {
            $lastUpdateId = max($lastUpdateId, (int) $update['update_id']);

            $chatId = $update['chat_id'] ?? null;
            $text = $update['text'] ?? null;

            // Unauthorized chats and non-text updates are discarded silently,
            // but their offset is still advanced so they never re-deliver.
            if ($chatId === null || $text === null || ! in_array((int) $chatId, $allowedChats, true)) {
                continue;
            }

            ProcessTelegramUpdate::dispatch((int) $chatId, $text);

            $dispatched++;
        }

        // Jobs are dispatched before the offset is persisted: a crash in
        // between re-delivers the batch on the next poll (at-least-once)
        // instead of losing updates.
        if ($lastUpdateId > (int) $settings->last_update_id) {
            $settings->forceFill(['last_update_id' => $lastUpdateId])->save();
        }

        $this->components->info(sprintf('Polled %d update(s); dispatched %d job(s).', count($updates), $dispatched));

        return self::SUCCESS;
    }
}
