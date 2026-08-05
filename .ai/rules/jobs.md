---
paths:
  - 'app/Jobs/**'
---

# Jobs

## ProcessTelegramUpdate: resolve services in handle(), not the constructor
TelegramClient holds a Guzzle client whose handler stack contains closures, so constructor-injecting it into a ShouldQueue job breaks serialization on dispatch ('Serialization of Closure is not allowed' — verified on GuzzleHttp\Client). Resolve AiConfigSyncer/TelegramClient via handle() type-hints and the container. Job retries (tries=3, backoff=[5,15,60]) are for the Telegram send path only; AI generation failures send FRIENDLY_ERROR_MESSAGE and return without failing. Missing/deleted owner logs a warning and stops.

## Telegram send is its own job (SendTelegramReply); resolve services in handle()
ProcessTelegramUpdate only GENERATES the AI reply (BotAgent::respond) and dispatches SendTelegramReply::dispatch(chatId, text). The send job does the HTTP call with TelegramClient resolved via handle() type-hint (never constructor-inject Guzzle-backed classes — closures break queue serialization). SendTelegramReply retries aggressively (tries=5, backoff=[10,30,60,120,300], maxExceptions=5) because the send path is cheap; AI generation failures in ProcessTelegramUpdate dispatch the friendly message and return without failing, so ProcessTelegramUpdate retries only cover dispatch failure. Both jobs stay serializable for the database queue.

## SendTelegramReply sends photos via the [IMAGE:...] marker
When the reply text contains [IMAGE:<relative-path>], SendTelegramReply calls TelegramClient::sendPhoto and deletes the file only after a successful send (retries keep it). The path MUST be validated to stay under telegram-media/ (reject .., absolute, backslash) — otherwise prompt injection (the bot fetches web pages) can exfiltrate/delete arbitrary server files. Caption = text with all markers stripped, truncated to Telegram's 1024-char limit. Fallback to formatted sendMessage when the path is invalid or the file is missing; skip entirely when the fallback text is empty. Text messages go through TelegramHtmlFormatter with parse_mode HTML.

## SendTelegramReply formats the AI reply via TelegramHtmlFormatter and guards empty HTML output
SendTelegramReply::handle(TelegramClient $telegram, TelegramHtmlFormatter $formatter) formats $this->text to Telegram-safe HTML and sends with parse_mode='HTML'. It must early-return when the FORMATTED html is empty/whitespace-only (e.g. a reply that is only '---' or raw <script> gets stripped to '') — otherwise sendMessage('', 'HTML') triggers Telegram 400 and a 5-attempt retry storm. The raw-text null/empty guard happens before formatting. Pre-existing [IMAGE:<relative-path>] markers route to sendPhoto (raw caption, no HTML guard); a missing image file falls back to formatting the caption text.
