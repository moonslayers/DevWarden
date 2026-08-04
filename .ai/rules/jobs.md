---
paths:
  - 'app/Jobs/**'
---

# Jobs

## ProcessTelegramUpdate: resolve services in handle(), not the constructor
TelegramClient holds a Guzzle client whose handler stack contains closures, so constructor-injecting it into a ShouldQueue job breaks serialization on dispatch ('Serialization of Closure is not allowed' — verified on GuzzleHttp\Client). Resolve AiConfigSyncer/TelegramClient via handle() type-hints and the container. Job retries (tries=3, backoff=[5,15,60]) are for the Telegram send path only; AI generation failures send FRIENDLY_ERROR_MESSAGE and return without failing. Missing/deleted owner logs a warning and stops.

## Telegram send is its own job (SendTelegramReply); resolve services in handle()
ProcessTelegramUpdate only GENERATES the AI reply (BotAgent::respond) and dispatches SendTelegramReply::dispatch(chatId, text). The send job does the HTTP call with TelegramClient resolved via handle() type-hint (never constructor-inject Guzzle-backed classes — closures break queue serialization). SendTelegramReply retries aggressively (tries=5, backoff=[10,30,60,120,300], maxExceptions=5) because the send path is cheap; AI generation failures in ProcessTelegramUpdate dispatch the friendly message and return without failing, so ProcessTelegramUpdate retries only cover dispatch failure. Both jobs stay serializable for the database queue.
