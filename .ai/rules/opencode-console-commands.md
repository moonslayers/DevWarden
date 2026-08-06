---
paths:
  - 'app/Services/Opencode/**,app/Console/Commands/**'
---

# Opencode Console Commands

## Transcript parsing lives in OpencodeSessionParser, shared across monitors
Parsing of opencode conversation transcripts (lastAssistantText, conversationBlocks, hasQuestions, truncate) is centralized in App\Services\Opencode\OpencodeSessionParser — a final, stateless class injected into OpencodeMonitor::handle(). Do not re-implement these helpers in a new command; reuse the parser so external-session monitors share one implementation.

## opencode:monitor invokes OpencodeSessionWatcher to also watch external sessions
opencode:monitor calls OpencodeSessionWatcher::check() on every tick (before the workflow loop and also when no workflows are running), so it monitors ALL opencode sessions, not just bot workflows. Discovery: OpencodeSessionStore::activeSessions() lists every session straight from the opencode SQLite (read-only); sessions with an opencode_workflows row are excluded; owner chat = resolveOwnerChatId() preferring a conversation inside TelegramSetting::allowed_user_ids over the latest one. Anti-spam: transitions tracked via opencode_session_watches.last_seen_status — a freshly discovered session is only registered (never notified on first sighting), and a failed Telegram notify leaves last_seen_status unchanged so the next tick retries. Question event = idle session whose last assistant message has a question (parser) or has a pending permission. Verified live: busy→idle transitions do fire.
