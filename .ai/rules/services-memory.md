---
paths:
  - 'app/Jobs/CaptureBotMemory.php,app/Services/Memory/**'
---

# Services Memory

## Memory capture idempotency: source_message_id = Telegram update_id, non-unique index
CaptureBotMemory idempotency is enforced by the job's early-return check on chat_id + source_message_id (MemoryRepository::existsForSource), NOT by the DB index — the bot_memories.source_message_id index is intentionally NON-unique because one source message can yield up to 3 memories. The source identifier is the Telegram update_id, plumbed as the first pending message's update_id by ProcessTelegramPendingBatch (CaptureBotMemory::dispatch(chatId, combined, reply, (string) $pending->first()->update_id)) and forwarded as a string; do NOT switch it to message_id (now exposed by TelegramClient::normalizeUpdate as the Telegram message_id, not the DB source id) and do NOT make the index unique.
