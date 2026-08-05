---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## PollTelegramUpdates: resolve TelegramClient lazily; at-least-once offsets
Every artisan invocation eagerly instantiates discovered commands via Artisan::starting bootstrappers, so PollTelegramUpdates resolves TelegramClient in handle() with app() inside try/catch — a constructor dependency would throw TelegramNotConfiguredException on EVERY artisan command when the token is missing. getUpdates offset = last_update_id + 1 (0 first run); jobs are dispatched BEFORE the single offset write so a crash re-delivers (at-least-once); unauthorized/non-text updates still advance the offset so they never re-deliver. Schedule in bootstrap/app.php: everyMinute + withoutOverlapping(10).

## PollTelegramUpdates upserts to the debounce buffer and schedules one batch job per chat
telegram:poll no longer dispatches one job per update. For each authorized text update (message or edited_message) it calls TelegramMessageBuffer::storeMessage(chatId, messageId, text, updateId, isEdit) (upsert keyed on chat_id+message_id; edits update in place), then after the loop calls scheduleIfNeeded($chatId) per affected chat, which dispatches ProcessTelegramPendingBatch with a 5s delay and dedups via telegram_chat_batches (scheduled_at future / processing_at fresh, stale reclaim 15min). At-least-once preserved: buffer writes + scheduling happen BEFORE the offset is persisted.
