---
paths:
  - 'app/Models/TelegramPendingMessage.php,app/Models/TelegramChatBatch.php,app/Services/Telegram/**'
---

# Services Telegram

## Telegram debounce buffer: (chat_id, message_id) unique upsert; batch row per chat
telegram_pending_messages is keyed by a unique composite (chat_id, message_id) so a Telegram edited_message upserts the same row in place (updateAtOrCreate on that pair); its update_id column is just the latest Telegram update id, NOT the idempotency key — memory capture uses update_id (bot_memories.source_message_id), pending buffer coalesces on message_id. telegram_chat_batches.chat_id is the primary key (not auto-incrementing; model sets $primaryKey='chat_id', $incrementing=false) holding scheduled_at/processing_at nullable timestamps for the per-chat debounce window.
