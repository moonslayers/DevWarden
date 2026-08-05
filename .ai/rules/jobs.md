---
paths:
  - 'app/Jobs/**'
  - app/Jobs/CaptureBotMemory.php
  - app/Jobs/ProcessTelegramPendingBatch.php
---

# Jobs

## Jobs resolve services in handle(), not the constructor
TelegramClient holds a Guzzle client whose handler stack contains closures, so constructor-injecting it into a ShouldQueue job breaks serialization on dispatch ('Serialization of Closure is not allowed' — verified on GuzzleHttp\Client). Resolve AiConfigSyncer/TelegramClient via handle() type-hints and the container. AI generation failures in ProcessTelegramPendingBatch dispatch FRIENDLY_ERROR_MESSAGE and continue the drain; missing/deleted owner logs a warning and stops.

## Telegram send is its own job (SendTelegramReply); resolve services in handle()
ProcessTelegramPendingBatch only GENERATES the AI reply (BotAgent::respond) and dispatches SendTelegramReply::dispatch(chatId, text). The send job does the HTTP call with TelegramClient resolved via handle() type-hint (never constructor-inject Guzzle-backed classes — closures break queue serialization). SendTelegramReply retries aggressively (tries=5, backoff=[10,30,60,120,300], maxExceptions=5) because the send path is cheap; AI generation failures dispatch the friendly message and return without failing. Both jobs stay serializable for the database queue.

## SendTelegramReply sends photos via the [IMAGE:...] marker
When the reply text contains [IMAGE:<relative-path>], SendTelegramReply calls TelegramClient::sendPhoto and deletes the file only after a successful send (retries keep it). The path MUST be validated to stay under telegram-media/ (reject .., absolute, backslash) — otherwise prompt injection (the bot fetches web pages) can exfiltrate/delete arbitrary server files. Caption = text with all markers stripped, formatted via TelegramHtmlFormatter and truncated to Telegram's 1024-char limit with balanced tags (truncateHtml helper) before sendPhoto(..., 'HTML'); empty formatted captions send the photo without caption. Fallback to formatted sendMessage when the path is invalid or the file is missing; skip entirely when the fallback text is empty. Text messages go through TelegramHtmlFormatter with parse_mode HTML.

## SendTelegramReply formats the AI reply via TelegramHtmlFormatter and guards empty HTML output
SendTelegramReply::handle(TelegramClient $telegram, TelegramHtmlFormatter $formatter) formats $this->text to Telegram-safe HTML and sends with parse_mode='HTML'. It must early-return when the FORMATTED html is empty/whitespace-only (e.g. a reply that is only '---' or raw <script> gets stripped to '') — otherwise sendMessage('', 'HTML') triggers Telegram 400 and a 5-attempt retry storm. The raw-text null/empty guard happens before formatting. Pre-existing [IMAGE:<relative-path>] markers route to sendPhoto with the caption formatted via TelegramHtmlFormatter and parse_mode='HTML'; a missing image file falls back to formatting the caption text.

## SendTelegramReply photo captions are formatted HTML, not raw
Photo captions in SendTelegramReply now go through TelegramHtmlFormatter and are sent via sendPhoto(..., 'HTML'). Truncation must keep ≤1024 chars with balanced tags (truncateHtml helper). Empty formatted captions send the photo with no caption.

## bot_memories.content is NOT NULL; job stores the transcript there
CaptureBotMemory::create() must pass 'content' (the user+assistant transcript) because bot_memories.content is NOT NULL — passing only summary/category/importance throws a NOT NULL constraint violation. MemoryRepository::create does not fill content for you. Also: the whole capture section is wrapped in catch(Throwable) and logged via Log::warning (best-effort; never fails the queue chain). EmbeddingService::embed() returns a union (flat|batch); narrow single-text output via a foreach is_array guard before passing to MemoryRepository::findSimilar/create.

## ProcessTelegramPendingBatch: tries=1, drain self-heals via finally + stale reclaim
Do NOT bump tries or add backoff to ProcessTelegramPendingBatch: AI failures dispatch FRIENDLY_ERROR_MESSAGE and continue, and a retry of the whole drain would re-run AI already consumed. Recovery comes from the finally block (endProcessing re-arms for stragglers) and scheduleIfNeeded()'s stale processing reclaim (STALE_THRESHOLD=15min). The thinking placeholder is sent PER AI TURN: each photo message is its own turn, so each turn sends (and dismisses/replaces) its own ThinkingIndicator placeholder, which is then replaced by that turn's reply. The old ProcessTelegramUpdate job is retired, replaced by the buffer/batch pipeline TelegramMessageBuffer → ProcessTelegramPendingBatch. The service type-hint in handle() is a real TelegramMessageBuffer — the drain loop calls pendingFor()/deletePending() on the real DB.

## ProcessTelegramPendingBatch: capped drain (5) + owner-null clears pending
ProcessTelegramPendingBatch drain loop is capped at MAX_DRAIN_ITERATIONS=5 per job: continuous flooding cannot starve the single queue worker, leftover pending rows are re-armed by endProcessing() (which dispatches another batch without delay). When resolveOwner() returns null the job DELETES all pending rows for the chat before returning (instead of leaving them), otherwise scheduleIfNeeded would re-dispatch a job every poll forever (warn-spam + growing buffer). Memory capture is skipped when the reply equals FRIENDLY_ERROR_MESSAGE (matches the retired job's behavior).
