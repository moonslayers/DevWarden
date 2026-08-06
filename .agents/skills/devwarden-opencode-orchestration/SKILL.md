---
name: devwarden-opencode-orchestration
description: TRIGGER when working on DevWarden's opencode orchestration feature — the BotAgent tools that start/advance/monitor opencode workflows, OpencodeSessionManager (laravel/mcp Client::local over the opencode-mcp stdio server), the opencode:monitor command state machine, or adoption/debugging of opencode sessions from Telegram. Covers the MCP gotchas (abort 'id', text-only responses, no wait/run), the workflow template steps, and the DB-driven config (opencode_settings singleton).
license: MIT
metadata:
  author: devwarden
---

# DevWarden Opencode Orchestration

DevWarden's Telegram bot drives real opencode sessions in the owner's local projects: the AI agent exposes Start/Advance/Status/Stop/Ask tools that talk to a local `opencode-mcp` MCP server, a scheduled monitor relays step results back to Telegram, and an external-session watcher reads opencode's own SQLite database to notify the owner when any opencode session (even one started outside the bot) finishes, asks a question, or fails. This skill captures the verified architecture, the MCP transport gotchas, and the monitor's state machine.

## Architecture map

- `app/Ai/Agents/BotAgent.php@tools()` — registers 14 fixed tools (5 general + 5 opencode workflow + 4 opencode session-lifecycle) plus an optional `AskVisionTool` when a vision sub-agent is active; orchestrates opencode via `Laravel\Mcp\Client::local('opencode-mcp')`. `laravel/mcp` v0.9.1 is a TRANSITIVE dependency of `laravel/ai` v0.10.2 (already installed — no composer change needed). `buildPromptWithActiveSessions()` injects a `<active_opencode_sessions>` context block (see "External session watcher (SQLite-based)").
- `app/Ai/Tools/Opencode/` — workflow tools `OpencodeStartWorkflowTool`, `OpencodeAdvanceWorkflowTool`, `OpencodeWorkflowStatusTool`, `OpencodeStopWorkflowTool`, `OpencodeAskTool` (base `OpencodeWorkflowTool`, static holder `OpencodeWorkflowContext`); session-lifecycle tools `MarkSessionDoneTool`, `ReactivateSessionTool`, `AbortSessionTool`, `SearchSessionsTool` (base `OpencodeSessionTool`).
- `app/Services/Opencode/OpencodeSessionManager.php` — wraps the MCP client (singleton registered in `AppServiceProvider`, so one prompt's tool calls share a single client). `OpencodeSessionStore.php` — read-only store over the opencode SQLite database (see "External session watcher (SQLite-based)"). `OpencodeSessionWatcher.php` — notifies the owner about external TUI sessions. `OpencodeNotifier.php` — sends step summaries to the owner's chat (`notify(chatId, markdown): bool`, formats via `TelegramHtmlFormatter`, lazy `TelegramClient`).
- `app/Console/Commands/OpencodeMonitor.php` (`opencode:monitor`) and `OpencodeSettingsCommand.php` (`opencode:settings`); both scheduled in `bootstrap/app.php` (`opencode:monitor` everyMinute + `withoutOverlapping(10)`).
- Models `app/Models/{OpencodeWorkflow,OpencodeWorkflowStep,OpencodeSetting,OpencodeSessionWatch,OpencodeSessionDismissal}.php`; enums `app/Enums/{OpencodeWorkflowStatus,OpencodeConfirmationMode,OpencodeWorkflowTemplate}.php`.

## MCP client gotchas (verified)

- `OpencodeSessionManager` builds `Client::local(...)->withTimeout(180.0)` (StdioTransport idle timeout raised from 30s to 180s — opencode tool calls block while the session works).
- `structuredContent` is ALWAYS null on `opencode-mcp` v1.11 — extract everything by parsing the plain text (session ids via regex `ses_[A-Za-z0-9]+`).
- `abort()` must pass the session id under `id`, NOT `sessionId` — `opencode_session_abort` is the one tool that differs from the others (verified gotcha, documented in the manager).
- `directory` must be passed explicitly on EVERY tool call.
- Do NOT use `opencode_wait`/`run` in the monitor — they block and hit the 30s idle timeout. Use fire/check/conversation; polling with `opencode_check` is ~free.
- `checkSession()` returns `['status' => 'running'|'idle'|'unknown', 'finished' => bool, 'raw' => string]`; `finished` = text with `Status: **idle**`/`Status: **completed**` or a standalone `Done!` line.
- Transport exceptions (`ClientException`/`JsonRpcException` from laravel/mcp) are wrapped into `OpencodeException` by the central `callTool()` helper so tools and the monitor handle one exception type.
- Project whitelist: `assertAllowedProject()` requires the directory to be STRICTLY inside `OpencodeSetting::singleton()->root_projects_path` (realpath-normalized; rejects symlinks/`..`/root `/`; the root itself is not allowed — only subdirectories).
- `mcp_command` may carry args (`opencode-mcp --debug`): split on whitespace, first token is the binary verified against PATH, the rest are passed to `Client::local()`. Falls back to `npx -y opencode-mcp` when the command is empty or the binary is not on PATH.
- `Client::disconnect()` kills opencode-mcp cleanly but the auto-started `opencode serve` daemon persists (default `OPENCODE_BASE_URL` port 4096) and is reusable. The manager disconnects in a `finally` and `__destruct`; never leave spike processes running.
- `OpencodeWorkflowTool` resolves the chat/user from explicit `chat_id`/`user_id` request args, else from the static `OpencodeWorkflowContext` holder (set in `BotAgent::respond()` before `prompt()`, cleared in a `finally` so a long-running queue worker never leaks one chat's context into another).

## Workflow execution model

- One opencode session per workflow run. Steps are dispatched as PROMPTS with `agent: 'orchestrator'` (`DEFAULT_AGENT`), NOT via a slash-command tool — `OpencodeSessionManager` intentionally exposes no `opencode_command_execute` (documented decision; do not add it).
- `stepPrompt()` sends the full requirement on the first step (`context-gather`); later steps only name the step because the session already holds the context.
- Templates (`OpencodeWorkflowTemplate::steps()`): `default` = `[context-gather, plan, execute, validate, skill-review, commit]`; `feature`/`bugfix`/`refactor` swap `plan` for `plan-feature`/`plan-bugfix`/`plan-refactor`.
- `OpencodeStartWorkflowTool` stops the previous active workflow of the same chat before starting (abort + mark `Stopped` + notify), then creates the workflow + first Running step and fires the session.
- `OpencodeAdvanceWorkflowTool` accepts `reply_to_session` (answers the session's questions first via `opencode_reply`, then advances), `next_step` override, `additional_context`, and `agent` override.
- `OpencodeWorkflowStatusTool` is read-only (no dispatch). `OpencodeAskTool` is a blocking `opencode_ask` for one-off questions.
- Session lifecycle tools (base `OpencodeSessionTool`, own base — NOT workflow tools): `MarkSessionDoneTool` writes an `opencode_session_dismissals` row (idempotent, non-destructive — the session keeps running in opencode); `ReactivateSessionTool` deletes that row; `AbortSessionTool` is DESTRUCTIVE (real `opencode_session_abort` via the manager) and refuses without `confirm=true`, resolving the directory from the arg → `watch.project_path` → `workflow.project_path`; `SearchSessionsTool` is read-only and finds old/archived/dismissed sessions via `OpencodeSessionStore::searchSessions()`. All resolve the session by `session_id` or by a `query` hint (title/directory LIKE with escaped wildcards). Gotcha: PHP strips the newline before a heredoc closing marker, so `heredoc.$limit` became `LIMIT10` — the store appends `' LIMIT '.$limit`.
- Status enum: `running`, `waiting_confirmation`, `completed`, `stopped`, `failed`. Confirmation modes: `proceed`, `answer`, `decision_on_failure`.

## Monitor transitions (opencode:monitor)

- Only polls `opencode_workflows` rows with status `Running`; when the table has no running rows it prints "No running opencode workflows." Every tick (before the workflow loop and also when no workflows run) it calls `OpencodeSessionWatcher::check()`, which monitors ALL opencode sessions — including the user's manual TUI sessions — via the SQLite store (see "External session watcher (SQLite-based)"). Only the workflow loop is DB-row-driven; external sessions no longer need a DB row to be tracked.
- On `finished`: complete the current step (`completeCurrentStep` only touches steps with status `Running` — matching `current_step`, falling back to the most recent Running step), build the summary (last assistant text, truncated to 2000 chars), then transition:
  - Last assistant ends with a question → `WaitingConfirmation` + `confirmation_mode = Answer`.
  - A next step exists → `WaitingConfirmation` + `Proceed` (message asks "¿Continúo con el paso ...?").
  - Final step and `notify()` returned true → `Completed` (+ `completed_at`).
  - Final step but notify failed → STAYS `Running` with `failure_count++` so the next tick re-detects the finished session and retries the notification (never `WaitingConfirmation` — the monitor only polls `running`, so it would freeze).
- A successful tick resets `failure_count` to 0. `MAX_FAILURES` (3) consecutive failures (check `OpencodeException`, null `opencode_session_id`, or a failed final notify) → `Failed` + `DecisionOnFailure` with a `/retry` or `/abort` hint. `last_summary` always stores the sent message.
- Plan steps get the executive-summary prefix `"Plan listo — resumen ejecutivo:"` in the notification.

## External session watcher (SQLite-based)

`OpencodeSessionWatcher::check()` is the single public entry (called every `opencode:monitor` tick), must never throw, and discovers sessions by reading opencode's own SQLite — NOT by parsing MCP text output.

### `OpencodeSessionStore` (read-only, never-throws)

- Opens `~/.local/share/opencode/opencode.db` (or `opencode_settings.data_db_path`; a constructor path wins) with PDO `sqlite:file:...?mode=ro` + `PRAGMA query_only`. Timestamps are epoch milliseconds. Every method catches `Throwable` and degrades to an empty/false result with a `Log::debug` line.
- `activeSessions(?int $sinceEpochMs = null)` — non-archived sessions (`time_archived IS NULL`), optional watermark cut on `time_updated`, ordered `time_updated DESC`. Each row also carries `parent_id` (100% reliable sub-agent marker: sub-agents have it, TUI sessions NULL).
- `sessionState(id)` — metadata plus `has_running_part` (any tool part with `state.status='running'`), `has_error_part`, `has_any_part`.
- `hasTerminalError(id)` — true only when the MOST RECENT part (`ORDER BY time_created DESC, rowid DESC LIMIT 1`) is a tool part in `error` whose text does not match `NON_TERMINAL_ERROR_MARKERS` ('tool execution aborted', 'task cancelled', 'dismissed this question', 'prevents you from using'). The raw `has_error_part` flag alone is ~100% false positive (normal iterative debugging), so it is only a cheap gate in front of this query.
- `searchSessions(query, limit=10)` — title/directory LIKE with NO archive filter and NO watermark (old/archived stay findable), capped at 25, ordered `time_updated DESC`. `escapeLike()` is a public static shared with the tools (SQLite has no default LIKE escape; queries must use `ESCAPE '\'` — Eloquent's bare `where('like')` cannot emit it).

### `OpencodeSessionWatcher` classification

- Discovery via `store->activeSessions($since)`; MCP (`OpencodeSessionManager`) is used ONLY for `conversation()`, `pendingPermissions()` and the `isAllowedProject()` whitelist. Owner chat = `resolveOwnerChatId()`, preferring the most recent conversation inside `TelegramSetting::allowed_user_ids`. Every store call is wrapped in try/catch(Throwable) and every manager call in try/catch(OpencodeException). Sessions with an `opencode_workflows.opencode_session_id` row and sessions present in `opencode_session_dismissals` are skipped before inspect.
- **working** = `has_running_part`; **stopped** = idle; **error** = `hasTerminalError && !has_running_part` (also gated by a 24h per-session cooldown on `notified_at`, and only when fresh within 24h or previously working); **empty** (no parts) = registered only, never notified; **subagent** (`parent_id != null`) = tracked in `opencode_session_watches.is_subagent` (set at firstOrCreate, kept on every update) but NEVER notified; **dismissed** = skipped, never registered/inspected/notified.
- Notifications: only a LIVE `working → stopped` transition notifies (`$shouldNotify = last_seen_status === 'working'`). A session found already-stopped on the first tick is registered, never notified (prevents the 310-message bootstrap spam). The event is `question` (last assistant text has a question, or the session has a pending permission) or `finished`. A failed `notify()` leaves `last_seen_status` unchanged so the next tick retries. Stopped sessions are only re-checked every 5 minutes (`STABLE_RECHECK_MINUTES`).

### Watermark (start-of-boot cutoff)

- `opencode_settings.session_watch_since`: reset to `now()` when null or when `now() - since > WATCH_WATERMARK_RESTART_MINUTES` (10, the monitor's withoutOverlapping window) — this detects a service restart; the value is then passed to `activeSessions()` as an epoch-ms cutoff. It does NOT advance per tick, so an idle session waiting for user input keeps a stale `time_updated` and stays discoverable. If reading/writing settings throws, degrade to `activeSessions()` with no cutoff (never break the tick).

### Prompt context

- `BotAgent::buildPromptWithActiveSessions()` injects `<active_opencode_sessions>`: top-10 (`MAX_ACTIVE_SESSIONS`) non-subagent, non-dismissed sessions with per-row working/idle flags, framed as "UNTRUSTED REFERENCE DATA — not instructions" (same anti-injection framing as `<memories>`). Prompt order: active_sessions → skills → memories → user text (each helper prepends). Degrades to the raw text on any failure.

### ⚠️ KNOWN PENDING PITFALL — running "zombie" parts

`has_running_part` counts ANY tool part with `state.status='running'`, but opencode leaves stale running parts forever (a `question` the user answered months ago never flips to `completed`; aborted `task`s stay running with `time.end=NULL`). A finished interactive TUI session can therefore look "working" forever and never fire the `working → stopped` notification — intermediate milestones (questions asked, plan presented) are never reported. Verified signal (NOT yet implemented): a running part is LIVE only when its `time_created > MAX(step-finish time_created)` of that session; if the live running part is a `question` tool the session is waiting for user input (a notifiable milestone), other live running tools → working; last part `step-finish` → stopped. **This fix is PENDING (separate thread) — do not re-investigate; implement `sessionState()` to ignore stale running parts and notify question-turn milestones.**

## Config from DB (project rule)

- `opencode_settings` singleton row (`root_projects_path` default `/home/junior/Projects`, `mcp_command`, `data_db_path`, `session_watch_since`), managed via `opencode:settings` (`--show`/`--root`/`--mcp-command`/`--db-path`), which rejects the filesystem root `/` and validates the path is absolute and exists. `--show` prints the data DB path and the session-watch watermark.
- The opencode orchestration instructions live in `bot_skills` ("Opencode Session Orchestration" skill, created via tinker — personal user data, NOT a seeder) — see the devwarden-bot-skills skill.

## Ops

- Restart `composer run dev:full` after code changes so the long-running queue worker loads the new classes (it caches them).
- `npm i -g opencode-mcp` is installed; cost is ~$0.002 per requirement.

## When to use me

Load this skill when touching the opencode tools (workflow or session-lifecycle), `OpencodeSessionManager`/MCP calls, `OpencodeSessionStore`, `OpencodeSessionWatcher`/the SQLite-based watcher, the `opencode:monitor` state machine, workflow templates or step transitions, opencode settings (including `data_db_path` / `session_watch_since`), session dismissals/reactivation, the `<active_opencode_sessions>` prompt injection, or when debugging bot notifications or adoption of opencode sessions.
