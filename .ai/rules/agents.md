---
paths:
  - 'app/Ai/Agents/**'
---

# Agents

## BotAgent: pass chain() strings as provider array; override maxConversationMessages
BotAgent responds per Telegram chat: first message forUser($owner) then persists currentConversation() into TelegramChatConversation; later messages continue($mapping->conversation_id, as: $owner). Prompt provider argument is AiConfigSyncer::chain() (plain config-name strings like 'openai') — Promptable::getProvidersAndModels resolves them via config('ai.providers.<name>'), no Lab enum mapping needed. Memory depth honors BotSetting::max_history_messages by overriding the trait's protected maxConversationMessages(). Do NOT re-sync config inside instructions() — call AiConfigSyncer::sync() at the top of respond().

## BotAgent: firstOrCreate mapping row, then fill conversation_id
respond() uses TelegramChatConversation::firstOrCreate(['chat_id'=>$chatId], ['user_id'=>$owner->id]) at the START (atomic row per chat, chat_id is unique) instead of find-then-create, so concurrent at-least-once jobs can't hit the unique chat_id constraint. If the mapping has no conversation_id (fresh row or null edge case), start forUser($owner) and update conversation_id from currentConversation() after the prompt; never create a second row.

## BotAgent: populate OpencodeWorkflowContext before prompt, clear in finally
When the active tools include the opencode workflow tools, BotAgent::respond() must set OpencodeWorkflowContext::set($chatId, $ownerId) before prompt() and clear it in a finally so queue workers don't leak one chat's context into another. Full contract lives in .ai/rules/tools-opencode.md.

## BotAgent memory injection: embed([$text]) array input, no exclude cutoff
BotAgent::buildPromptWithMemories() embeds with $embeddingService->embed([$text]) (array form) and takes $vectors[0] so phpstan narrows the list<float>|list<list<float>> union to a flat vector — test doubles of EmbeddingService must return a NESTED array [[1,0,0,0]] for array input. The memories block is prepended to $text as a <memories> section (Option A); retrieval is wrapped in try/catch and degrades to the raw text. MemoryRepository::search()'s excludeBeforeCreatedAt is intentionally NOT passed (it filters out old memories, which are exactly the useful ones — the semantic would be inverted); the whole flow still works without it.

## opencode.ai Console Go gateway rejects response_format json_schema
The opencode.ai "Console Go" gateway (https://opencode.ai/zen/go/v1, model deepseek-v4-flash) returns 400 "This response_format type is unavailable now" for response_format.type=json_schema — which is what laravel/ai's OpenAiCompatibleGateway hardcodes for HasStructuredOutput agents (vendor/laravel/ai/src/Gateway/OpenAiCompatible/Concerns/BuildsTextRequests.php). It DOES accept type=json_object (the same approach laravel/ai's own DeepSeek gateway uses). If structured extraction against this endpoint fails with that message, override response_format via HasProviderOptions to json_object and append the schema to the instructions, or switch to an endpoint that supports json_schema. Also note deepseek-v4-flash is a reasoning model: content stays empty until reasoning is done, so keep max_tokens high enough or content can be truncated (finish_reason=length).

## VisionWorkflowContext must be bound before buildPromptWithImage so describe logs the chat_id
BotAgent::respond() must call VisionWorkflowContext::set($imagePath, $chatId) BEFORE buildPromptWithImage() runs: that method invokes VisionAgent::describe(), which records SubAgentUsageLog with chat_id = VisionWorkflowContext::chatId(). Binding after describe left every kind=describe log with chat_id=null (asymmetry vs kind=ask). Keep the set inside the same try whose finally clears both VisionWorkflowContext and OpencodeWorkflowContext.

## BotAgent active-sessions block: cheap working signal via OpencodeSessionWatch
buildPromptWithActiveSessions() prepends an <active_opencode_sessions> block; each builder prepends, so the final prompt is <active_opencode_sessions> → <skills> → <memories> → user text (active_sessions → skills → memories). Listing open TUI sessions (parent_id null), excluding ids in opencode_session_dismissals, top ~10 by time_updated DESC. The "working" flag reads OpencodeSessionWatch.last_seen_status === 'working' (one indexed lookup in the app DB) instead of store->sessionState(), which opens a fresh read-only PDO per session — too expensive for every bot message. The block uses the same UNTRUSTED REFERENCE DATA anti-injection framing as <memories>.

## BotAgent question-pending signal reads sessionState live (up to 10 sessions)
The <active_opencode_sessions> block marks a session 'esperando tu respuesta (tiene preguntas)' when OpencodeSessionStore::sessionState($id)['last_turn_tool'] === 'question' — the same live signal the watcher uses for notifyQuestionTurn. The cheap working flag stays on OpencodeSessionWatch.last_seen_status; never swap it for sessionState. resolvePendingQuestionIds() wraps the up-to-10 per-session lookups against local SQLite opencode.db (an accepted per-message cost) in try/catch and degrades to no question marks on failure.
