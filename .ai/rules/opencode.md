---
paths:
  - 'app/Services/Opencode/**'
  - 'app/Console/Commands/**'
---

# Opencode

## opencode-mcp tools: text-only responses and the abort 'id' gotcha
opencode-mcp (v1.11.0) tools always return structuredContent=null — extract everything by parsing the text (session ids via regex ses_[A-Za-z0-9]+). OpencodeSessionManager centralizes the whitelist (project dirs must be strictly inside OpencodeSetting::singleton()->root_projects_path) and always passes the absolute `directory` explicitly. Gotcha: opencode_session_abort expects the session under `id`, not `sessionId` (all other session tools use `sessionId`). Do NOT use opencode_wait/run in the monitor — use opencode_fire/check/conversation; checkSession treats `Status: **idle**`/`**completed**`/`Done!` as finished. Client::disconnect() kills opencode-mcp cleanly but the auto-started `opencode serve` daemon persists and is reusable.

## OpencodeSessionManager must not be modified to expose opencode_command_execute
opencode-mcp v1.11.0 DOES expose a slash-command tool (opencode_command_execute, needs sessionId + command) and every workflow step (context-gather, plan, plan-feature/bugfix/refactor, execute, validate, skill-review, commit) is a registered slash command, but OpencodeSessionManager exposes no command-execution method and must not be modified. Steps are dispatched by the tools via fire/reply prompts instead.

## OpencodeMonitor: no Completed on failed send; failures are retried or failed after MAX_FAILURES
opencode:monitor (Running workflows only) checks each session via checkSession/conversation (never wait/run). When finished it completes the current step, sends the owner a Telegram message via OpencodeNotifier::notify(int $chatId, string $markdown): bool (formatting via TelegramHtmlFormatter, lazy TelegramClient), and transitions: questions → WaitingConfirmation/Answer; next step → WaitingConfirmation/Proceed; last step → Completed only if notify() returned true. On a failed final send the workflow STAYS Running with failure_count incremented so the next tick re-detects the finished session and retries — never WaitingConfirmation (the monitor only polls status=running, so that would freeze it). completeCurrentStep only completes the Running step matching current_step (falls back to the most recent Running step) and never touches Completed/Failed steps. A successful tick (notify returned true) resets failure_count to 0. MAX_FAILURES (3) consecutive failures → Failed + DecisionOnFailure with /retry or /abort, where a "failure" is a check OpencodeException, a null opencode_session_id, or a failed final notify. last_summary always stores the sent message. OpencodeSessionManager is a singleton (AppServiceProvider) — disconnect() in a finally so the shared client is closed per scheduled tick.

## External-session discovery: whitelist validated on sessionInfo response
listSessions() and pendingPermissions() are global discovery calls (no directory, no whitelist gate). sessionInfo() must pass id (not sessionId) to opencode_session_get and validate the whitelist AFTER parsing, on the response's Directory line, throwing OpencodeProjectNotAllowed — the directory is unknowable before the call, so never pre-validate there. All three parse plain-text output (structuredContent is null) and must stay tolerant of unverified shapes.

## OpencodeSessionWatcher: single check() entry, no-throw, retry-on-failed-notify
OpencodeSessionWatcher::check() is the single public entry for external-session discovery; it is invoked periodically (from the monitor, later) and must never throw — wrap listSessions/pendingPermissions/conversation in try/catch(OpencodeException) and sessionInfo in try/catch(OpencodeProjectNotAllowed), logging and skipping. Owner chat = resolveOwnerChatId() (see "OpencodeSessionWatcher owner chat prefers allowed_user_ids"); no owner/chat → Log::debug + return. Sessions with an OpencodeWorkflow.opencode_session_id are excluded; pendingPermissions sessionIds add to question detection. wasRecentlyCreated rows are only registered, never notified. On notify() returning false the watch's last_seen_status is NOT updated so the next tick retries.

## OpencodeSessionWatcher owner chat prefers allowed_user_ids
OpencodeSessionWatcher::resolveOwnerChatId() resolves the owner's Telegram chat by preferring the most recent TelegramChatConversation whose chat_id is inside TelegramSetting::singleton()->allowed_user_ids (int-cast array). When allowed_user_ids is empty/null or no owner conversation matches it, it falls back to the latest owner conversation (latest('id')). This prevents a stale/dead conversation (e.g. chat 123456789 from tests, 'chat not found' on send) from being chosen over the real allowed chat. Both queries order by latest('id').

## OpencodeSessionWatcher never-throws contract covers OpencodeException
OpencodeSessionWatcher::check() must never let an exception escape, or a single failing session would abort the whole opencode:monitor tick (and the workflow loop). Every OpencodeSessionManager call (listSessions, sessionInfo, conversation, pendingPermissions) is wrapped in try/catch(OpencodeException) — sessionInfo catches the parent (transport failures are wrapped into OpencodeException by callTool), not just OpencodeProjectNotAllowed. Persisted status is canonical: 'completed' is normalized to 'idle'.
