---
paths:
  - 'app/Services/Telegram/**,app/Console/Commands/**'
---

# Console Commands

## Telegram uses local long polling (no webhook); token from DB
The bot is polled with a scheduled command (telegram:poll, everyMinute + withoutOverlapping) run by `php artisan schedule:work` — there is no webhook setup anywhere. The token comes from TelegramSetting::singleton()->bot_token (DB, encrypted), never from .env. telegram-bot/api's BotApi performs raw cURL calls with no injectable transport, so TelegramClient wraps an injectable Guzzle client (MockHandler in tests) and builds the /bot{token}/{method} endpoint itself.
