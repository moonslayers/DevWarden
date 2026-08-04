---
paths:
  - 'app/Services/Telegram/**'
---

# Telegram

## TelegramClient: BotApi uses raw cURL — build own Guzzle layer
telegram-bot/api's BotApi performs HTTP via raw PHP cURL (curl_init/curl_exec) with no injectable transport, so it cannot be mocked. TelegramClient wraps an injectable Guzzle ClientInterface (tests use MockHandler + Middleware::history) and POSTs to https://api.telegram.org/bot{token}/{method}. It parses updates with TelegramBot\Api\Types\Update::fromResponse() but normalizes to arrays {update_id, chat_id?, text?}. Token read from TelegramSetting::singleton()->bot_token (nullable) in the constructor — throws TelegramNotConfiguredException when unset. sendMessage returns the result array; setMyCommands returns bool (Telegram result is `true`).
