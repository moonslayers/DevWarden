---
paths:
  - 'app/Services/Opencode/**,app/Console/Commands/**'
---

# Opencode Console Commands

## Transcript parsing lives in OpencodeSessionParser, shared across monitors
Parsing of opencode conversation transcripts (lastAssistantText, conversationBlocks, hasQuestions, truncate) is centralized in App\Services\Opencode\OpencodeSessionParser — a final, stateless class injected into OpencodeMonitor::handle(). Do not re-implement these helpers in a new command; reuse the parser so external-session monitors share one implementation.

## opencode:monitor invokes OpencodeSessionWatcher to also watch external sessions
opencode:monitor calls OpencodeSessionWatcher::check() on every tick (before the workflow loop and also when no workflows are running), so it monitors ALL opencode sessions, not just bot workflows. Discovery: OpencodeSessionStore::activeSessions() lists every session straight from the opencode SQLite (read-only); sessions with an opencode_workflows row are excluded; owner chat = resolveOwnerChatId() preferring a conversation inside TelegramSetting::allowed_user_ids over the latest one. Anti-spam: transitions tracked via opencode_session_watches.last_seen_status — a freshly discovered session is only registered (never notified on first sighting), and a failed Telegram notify leaves last_seen_status unchanged so the next tick retries. Question event = idle session whose last assistant message has a question (parser) or has a pending permission. Verified live: busy→idle transitions do fire.

## Boot summary excludes waiting sessions; question turn always fires (no dedup)
maybeSendBootSummary() lists ONLY working sessions in the "Sesiones activas desde el inicio del servidor:" summary; sessions with a live 'question' turn are excluded (bootSummarySessions skips last_turn_tool === 'question') and always get their own question-turn notification carrying the question text. There is NO boot-window dedup: do not suppress the question turn because the summary ran in the same tick, or the user loses the question content. Main discovery uses discoveryCutoff() (activeSessions($since - DISCOVERY_GRACE_MINUTES=5)) so a session that asked a question just before a watermark reset stays discoverable.
