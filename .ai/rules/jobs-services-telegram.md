---
paths:
  - 'app/Jobs/**,app/Services/Telegram/**'
---

# Jobs Services Telegram

## Storage::makeDirectory takes only $path (no force: named arg)
Laravel 13 FilesystemAdapter::makeDirectory($path) accepts a single positional arg (the underlying Flysystem createDirectory is already recursive). Calling it with named params like force: true throws "Unknown named parameter $force". Use `$disk->makeDirectory('telegram-media/incoming');`.

## HandleTelegramCallbackQuery: single end-answer, tries=1, never fails the queue
The poller routes authorized callback updates to HandleTelegramCallbackQuery (dispatch BEFORE the offset persist, at-least-once) and there is NO early answerCallbackQuery from the poller: the job sends the single answer at the END with the final outcome (success without alert, errors with show_alert=true), so alerts stay accurate. The option label is resolved server-side from OpencodeSessionStore::questionOptions() (never from the untrusted callback_data) after validating the project whitelist. tries=1 (the opencode reply is not idempotent); the whole body is guarded so a Throwable logs and answers instead of failing the queue, and the answer() helper swallows Telegram failures (stale query).
