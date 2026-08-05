<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramPendingBatch;
use App\Models\TelegramChatBatch;
use App\Models\TelegramPendingMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Persists the per-chat pending-message buffer and the debounce scheduling state.
 *
 * The service is stateless: every method takes primitive arguments and resolves
 * the Eloquent models it needs, so it can be constructed anywhere and trivially
 * tested. It is the single authority that dispatches ProcessTelegramPendingBatch;
 * scheduling decisions are derived from the per-chat row in telegram_chat_batches
 * instead of a distributed lock.
 *
 * Flow: storeMessage() coalesces rapid messages into telegram_pending_messages;
 * scheduleIfNeeded() arms a debounced batch job; the job calls beginProcessing()
 * to claim the drain, processes every pending message in one AI call, deletes the
 * consumed rows and finally calls endProcessing() to release the claim and
 * re-schedule any messages that arrived while the AI was running.
 */
class TelegramMessageBuffer
{
    /**
     * Debounce window in seconds: pending messages are held this long before the
     * batch job runs, so rapid messages coalesce into a single AI call.
     */
    public const DEBOUNCE_SECONDS = 5;

    /**
     * Minutes after which a processing row is considered stale. A worker that
     * crashed mid-drain leaves processing_at set forever, so any row older than
     * this is reclaimed and rescheduled by the next scheduleIfNeeded() call.
     */
    public const STALE_THRESHOLD = 15;

    /**
     * Insert the message, or upsert it in place when the (chat_id, message_id)
     * pair already exists (a Telegram edited_message) so the buffer never holds
     * two rows for the same source message. $photoFileId carries the incoming
     * photo's file_id (the largest PhotoSize) so a drain can download it.
     */
    public function storeMessage(int $chatId, int $messageId, string $text, int $updateId, bool $isEdit = false, ?string $photoFileId = null): void
    {
        TelegramPendingMessage::query()->updateOrCreate(
            ['chat_id' => $chatId, 'message_id' => $messageId],
            ['text' => $text, 'update_id' => $updateId, 'is_edit' => $isEdit, 'photo_file_id' => $photoFileId],
        );
    }

    /**
     * Arm a debounce job for the chat unless one is already scheduled or running.
     *
     * A freshly processing batch is left alone (its drain re-checks pending
     * messages and absorbs new arrivals); a stale one is reclaimed. A future
     * scheduled_at already has a delayed job on its way, so no new dispatch.
     */
    public function scheduleIfNeeded(int $chatId): void
    {
        $batch = TelegramChatBatch::query()->firstOrCreate(['chat_id' => $chatId]);

        if ($batch->processing_at !== null && ! $this->isStale($batch->processing_at)) {
            return;
        }

        if ($batch->processing_at !== null) {
            $batch->processing_at = null;
        }

        if ($batch->scheduled_at !== null && $batch->scheduled_at->isFuture()) {
            return;
        }

        $delay = now()->addSeconds(self::DEBOUNCE_SECONDS);

        $batch->scheduled_at = $delay;
        $batch->save();

        ProcessTelegramPendingBatch::dispatch($chatId)->delay($delay);
    }

    /**
     * Claim the chat for a drain: mark it as processing and clear any pending
     * schedule so no other job is dispatched for it.
     */
    public function beginProcessing(int $chatId): void
    {
        $batch = TelegramChatBatch::query()->firstOrCreate(['chat_id' => $chatId]);

        $batch->processing_at = now();
        $batch->scheduled_at = null;
        $batch->save();
    }

    /**
     * The buffered messages for the chat, in insertion order.
     *
     * @return Collection<int, TelegramPendingMessage>
     */
    public function pendingFor(int $chatId): Collection
    {
        return TelegramPendingMessage::query()
            ->where('chat_id', $chatId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Delete the consumed pending rows after they were processed.
     *
     * @param  Collection<int, TelegramPendingMessage>  $pending
     */
    public function deletePending(Collection $pending): void
    {
        if ($pending->isEmpty()) {
            return;
        }

        TelegramPendingMessage::query()
            ->whereKey($pending->pluck('id'))
            ->delete();
    }

    /**
     * Release the chat claim after a drain and re-arm immediately when messages
     * arrived during the AI call, so the stragglers are absorbed by a fresh
     * drain without the debounce delay.
     */
    public function endProcessing(int $chatId): void
    {
        $batch = TelegramChatBatch::query()->firstOrCreate(['chat_id' => $chatId]);

        $batch->processing_at = null;
        $batch->save();

        if ($this->pendingFor($chatId)->isEmpty()) {
            return;
        }

        $batch->scheduled_at = now();
        $batch->save();

        ProcessTelegramPendingBatch::dispatch($chatId);
    }

    /**
     * A processing timestamp is stale when it predates the reclaim threshold.
     */
    private function isStale(CarbonInterface $processingAt): bool
    {
        return $processingAt->isBefore(now()->subMinutes(self::STALE_THRESHOLD));
    }
}
