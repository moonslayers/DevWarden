---
name: devwarden-telegram-ai-pipeline
description: TRIGGER when working on DevWarden's Telegram bot integration, AI provider configuration/failover, the bot message pipeline (poll → job → reply), or the settings pages (Telegram / AI Providers / Bot). Covers the config-from-database rule, the AiConfigSyncer + AiManager forgetInstance pattern, the telegram-bot/api package gotchas, local long-polling architecture, and per-chat conversation mapping.
license: MIT
metadata:
  author: devwarden
---

# DevWarden Telegram + AI Pipeline

DevWarden is a local-first personal development assistant (Laravel 13, Inertia 3, Vue 3, SQLite). Its Telegram bot receives messages via local long-polling and answers them through an AI agent whose providers and credentials come entirely from the database. This skill captures the verified architecture so changes to the bot, providers, or settings stay consistent.

## Core rule: config via DB

ALL configuration comes from the DATABASE through the web UI — never `.env`/config files. This includes the Telegram bot token (`telegram_settings.bot_token`), AI provider keys and models (`ai_providers`), the bot system prompt and owner (`bot_settings`), and the failover order (`ai_providers.failover_order`). `AiConfigSyncer::sync()` injects the `ai_providers` rows into `config('ai.providers.*')` at runtime and clears cached provider instances so the SDK re-reads the new values.

## Architecture map

- `app/Services/AiConfigSyncer.php` — DB → runtime config (`sync()`), failover chain (`chain()`), credential check (`testConnection()`).
- `app/Services/Telegram/TelegramClient.php` — thin wrapper around `telegram-bot/api` with an injectable Guzzle client.
- `app/Services/Telegram/Exceptions/{TelegramApiException,TelegramNotConfiguredException}.php`.
- `app/Ai/Agents/BotAgent.php` — the AI agent (Promptable + RemembersConversations) that replies per chat.
- `app/Jobs/ProcessTelegramUpdate.php` — generates the reply; hands off to the send job.
- `app/Jobs/SendTelegramReply.php` — sends the message; retries never re-run AI generation.
- `app/Console/Commands/PollTelegramUpdates.php` (`telegram:poll`) — long-poll + allowlist + offset persistence.
- `bootstrap/app.php` — `withSchedule(...)` runs `telegram:poll` everyMinute with `withoutOverlapping(10)`.
- Models: `app/Models/{TelegramSetting,AiProvider,BotSetting,TelegramChatConversation}.php`; enum `app/Enums/AiProviderType.php`.
- Settings controllers: `app/Http/Controllers/Settings/{Telegram,AiProvider,Bot}Controller.php`; Vue pages `resources/js/pages/settings/{Telegram,Providers,Bot}.vue`; routes appended additively in `routes/settings.php` (required from `routes/web.php`).
- Ops: `bin/dev-full.sh` (serve + schedule:work + queue:work), run as `composer run dev:full`.

## AI provider injection pattern (critical, non-obvious)

laravel/ai 0.10.2 caches resolved provider instances in the `AiManager` singleton, and providers capture config at construction. In a long-running queue worker you MUST, before each AI call:

1. Set the config values: `config(['ai.providers.<name>' => [...]])`.
2. Forget the cached manager instance by provider NAME (manager-level, e.g. `'openai'`), NOT container-level:
   `app(\Laravel\Ai\AiManager::class)->forgetInstance($providerName)`.
3. Then call the SDK.

`AiConfigSyncer::sync()` does this per job: it rebuilds `config('ai.providers')` from `AiProvider::enabledOrdered()` and forgets instances for both stale keys and enabled names. It also sets `ai.conversations.generate_title` to false (avoids an extra AI call) and resets `ai.default` to null when no provider is enabled so resolution fails loudly. `testConnection()` syncs only the provider under test (including disabled ones) and never throws.

## Telegram client gotcha

The package is `telegram-bot/api` v2.5 (pure PHP, compatible with Laravel 13). `telegram-bot-sdk/laravel` requires illuminate 10-12 and does NOT work on Laravel 13. The package uses raw cURL internally, so `TelegramClient` wraps it with an injectable Guzzle client for testability. The token is read from `TelegramSetting::singleton()->bot_token`; a missing token throws `TelegramNotConfiguredException` (the poll command catches it and returns SUCCESS so artisan stays usable).

## Long-polling pipeline

`telegram:poll` (scheduled every minute) calls `getUpdates(offset: last_update_id + 1, timeout: 50)`, filters to `telegram_settings.allowed_user_ids` (non-text and unauthorized updates are dropped but their offset still advances), dispatches `ProcessTelegramUpdate` per authorized text update, then persists the max update_id. Jobs are dispatched BEFORE the offset is persisted — at-least-once: a crash between them re-delivers the batch on the next poll instead of losing updates. `ProcessTelegramUpdate` (tries 3, backoff [5, 15, 60]) syncs AI config, resolves the bot owner, calls `BotAgent::respond()`, and dispatches `SendTelegramReply`. `SendTelegramReply` (tries 5, backoff [10, 30, 60, 120, 300], maxExceptions 5) only sends — a send failure retries the cheap HTTP call, never re-running AI generation. AI-generation failures dispatch a friendly error message (`FRIENDLY_ERROR_MESSAGE`) instead of retrying.

## Conversation memory

`BotAgent` implements `Agent` + `Conversational` using `Promptable` + `RemembersConversations`. One conversation per Telegram chat is mapped via `telegram_chat_conversations` (chat_id unique, conversation_id UUID, user_id). Use atomic `firstOrCreate(['chat_id'], ['user_id'])` (not find-then-create), then `continue($conversationId, as: $owner)` to resume or `forUser($owner)` for a new conversation — if the row was just created, fill `conversation_id` with `currentConversation()` after generation. Memory depth honors `bot_settings.max_history_messages` via the `maxConversationMessages()` override.

## Failover

The chain is the enabled `AiProvider` rows ordered by `failover_order` (0-based) → an array of config-key strings (openai, anthropic, deepseek, openai-compatible) passed as `provider: [...]` to `prompt()`. Verified: the SDK accepts config-key strings and preserves order. On provider failure the SDK moves to the next entry in order.

## Settings pages pattern

Singleton row pattern: `TelegramSetting::singleton()` / `BotSetting::singleton()` = `firstOrCreate(['id' => 1])->refresh()`. Secrets (`bot_token`, `api_key`) use the `encrypted` cast and are NEVER sent to the frontend — only `has_bot_token`/`has_api_key` booleans. FormRequests follow the existing ProfileUpdateRequest convention.

## Testing gotchas

- Global `RefreshDatabase` is active in `tests/Pest.php` (was commented out — starter kit bug, fixed). Test files can still declare it per-file harmlessly.
- Failover tests use `Http::fake()` — the SDK's HTTP gateways use the `Http` facade, and `BotAgent::fake()` cannot test cross-provider failover because the fake gateway is applied per agent regardless of provider.
- Job-dispatch assertions use `Queue::fake()`.

## When to use me

Load this skill when touching the Telegram bot behavior, adding/removing AI providers, failover logic, bot settings pages, conversation memory, or anything in the poll → job → reply pipeline above.
