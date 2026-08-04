---
paths:
  - 'app/Ai/Agents/**'
---

# Agents

## BotAgent: pass chain() strings as provider array; override maxConversationMessages
BotAgent responds per Telegram chat: first message forUser($owner) then persists currentConversation() into TelegramChatConversation; later messages continue($mapping->conversation_id, as: $owner). Prompt provider argument is AiConfigSyncer::chain() (plain config-name strings like 'openai') — Promptable::getProvidersAndModels resolves them via config('ai.providers.<name>'), no Lab enum mapping needed. Memory depth honors BotSetting::max_history_messages by overriding the trait's protected maxConversationMessages(). Do NOT re-sync config inside instructions() — call AiConfigSyncer::sync() at the top of respond().

## BotAgent: firstOrCreate mapping row, then fill conversation_id
respond() uses TelegramChatConversation::firstOrCreate(['chat_id'=>$chatId], ['user_id'=>$owner->id]) at the START (atomic row per chat, chat_id is unique) instead of find-then-create, so concurrent at-least-once jobs can't hit the unique chat_id constraint. If the mapping has no conversation_id (fresh row or null edge case), start forUser($owner) and update conversation_id from currentConversation() after the prompt; never create a second row.
