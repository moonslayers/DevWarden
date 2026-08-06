---
paths:
  - 'app/Services/Telegram/**'
---

# Telegram

## TelegramClient: BotApi uses raw cURL — build own Guzzle layer
telegram-bot/api's BotApi performs HTTP via raw PHP cURL (curl_init/curl_exec) with no injectable transport, so it cannot be mocked. TelegramClient wraps an injectable Guzzle ClientInterface (tests use MockHandler + Middleware::history) and POSTs to https://api.telegram.org/bot{token}/{method}. It parses updates with TelegramBot\Api\Types\Update::fromResponse() but normalizes to arrays {update_id, chat_id?, text?}. Token read from TelegramSetting::singleton()->bot_token (nullable) in the constructor — throws TelegramNotConfiguredException when unset. sendMessage returns the result array; setMyCommands returns bool (Telegram result is `true`).

## sendPhoto via multipart; TelegramHtmlFormatter for text and photo captions
TelegramClient::sendPhoto(chatId, photoPath, ?caption, ?parseMode) uploads multipart/form-data (chat_id, photo stream, caption field only when non-empty); keep the JSON path for the other methods. The optional parseMode adds parse_mode to the multipart ONLY when a caption is present and parseMode is non-null. Text sends go through TelegramHtmlFormatter (CommonMark → Telegram-safe HTML, league/commonmark) with parse_mode='HTML'; photo captions are also formatted with TelegramHtmlFormatter and sent with parse_mode='HTML' — empty formatted captions send the photo without caption. Jobs resolve TelegramClient/formatter in handle() so they stay serializable.

## TelegramClient sendMessage uses parse_mode=HTML; TelegramHtmlFormatter maps markdown→Telegram-safe HTML
Telegram only renders format when parse_mode is set; the AI agent produces Markdown, so SendTelegramReply converts via TelegramHtmlFormatter (stateless, league/commonmark 2.9, html_input=STRIP, allow_unsafe_links=false) and calls sendMessage($chatId, $html, 'HTML'). sendMessage's optional ?string $parseMode adds parse_mode to the JSON only when non-null (default stays plain text). Telegram HTML supports ONLY <strong>/<em>/<s>/<u>/<code>/<pre>/<a href>: headings→strong, lists→• / n. prefixes, hr→blank line, tables→'A | B' lines, images→'alt (url)', unsafe links/images dropped. If format() returns empty/whitespace the job skips the send (avoids 400 + retry storm).

## normalizeUpdate exposes message_id and edit flag (edited_message supported)
TelegramClient::normalizeUpdate now parses edited_message via Update::getEditedMessage() and returns arrays {update_id, chat_id?, message_id?, text?, edit?}: regular messages carry message_id without edit; edited messages carry the same message_id, the new text and edit=true. The debounce buffer keys pending rows on (chat_id, message_id) so edits upsert in place; bot_memories.source_message_id is STILL the Telegram update_id, never message_id.

## normalizeUpdate: callback_query has no special shape anymore
The inline-button callback pipeline was removed (user decision). TelegramClient::normalizeUpdate has NO callback_query branch: an update without message/edited_message (e.g. a leftover callback_query) returns just {update_id}, which the poller discards like any non-text/non-photo update while still advancing the offset. Do not reintroduce answerCallbackQuery, reply_markup or a callback_data contract.
