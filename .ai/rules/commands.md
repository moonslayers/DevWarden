---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## PollTelegramUpdates: resolve TelegramClient lazily; at-least-once offsets
Every artisan invocation eagerly instantiates discovered commands via Artisan::starting bootstrappers, so PollTelegramUpdates resolves TelegramClient in handle() with app() inside try/catch — a constructor dependency would throw TelegramNotConfiguredException on EVERY artisan command when the token is missing. getUpdates offset = last_update_id + 1 (0 first run); jobs are dispatched BEFORE the single offset write so a crash re-delivers (at-least-once); unauthorized/non-text updates still advance the offset so they never re-deliver. Schedule in bootstrap/app.php: everyMinute + withoutOverlapping(10).
