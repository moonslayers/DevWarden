---
name: devwarden-opencode-orchestration
description: TRIGGER when working on DevWarden's opencode orchestration feature — the BotAgent tools that start/advance/monitor opencode workflows, OpencodeSessionManager (laravel/mcp Client::local over the opencode-mcp stdio server), the opencode:monitor command state machine, or adoption/debugging of opencode sessions from Telegram. Covers the MCP gotchas (abort 'id', text-only responses, no wait/run), the workflow template steps, and the DB-driven config (opencode_settings singleton).
license: MIT
metadata:
  author: devwarden
---

# DevWarden Opencode Orchestration

DevWarden's Telegram bot drives real opencode sessions in the owner's local projects: the AI agent exposes Start/Advance/Status/Stop/Ask tools that talk to a local `opencode-mcp` MCP server, and a scheduled monitor relays step results back to Telegram. This skill captures the verified architecture, the MCP transport gotchas, and the monitor's state machine.

## Architecture map

- `app/Ai/Agents/BotAgent.php@tools()` — registers 5 opencode tools alongside the 5 original ones; orchestrates opencode via `Laravel\Mcp\Client::local('opencode-mcp')`. `laravel/mcp` v0.9.1 is a TRANSITIVE dependency of `laravel/ai` v0.10.2 (already installed — no composer change needed).
- `app/Ai/Tools/Opencode/` — `OpencodeStartWorkflowTool`, `OpencodeAdvanceWorkflowTool`, `OpencodeWorkflowStatusTool`, `OpencodeStopWorkflowTool`, `OpencodeAskTool`, base `OpencodeWorkflowTool`, static holder `OpencodeWorkflowContext`.
- `app/Services/Opencode/OpencodeSessionManager.php` — wraps the MCP client (singleton registered in `AppServiceProvider`, so one prompt's tool calls share a single client). `OpencodeNotifier.php` — sends step summaries to the owner's chat (`notify(chatId, markdown): bool`, formats via `TelegramHtmlFormatter`, lazy `TelegramClient`).
- `app/Console/Commands/OpencodeMonitor.php` (`opencode:monitor`) and `OpencodeSettingsCommand.php` (`opencode:settings`); both scheduled in `bootstrap/app.php` (`opencode:monitor` everyMinute + `withoutOverlapping(10)`).
- Models `app/Models/{OpencodeWorkflow,OpencodeWorkflowStep,OpencodeSetting}.php`; enums `app/Enums/{OpencodeWorkflowStatus,OpencodeConfirmationMode,OpencodeWorkflowTemplate}.php`.

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
- Status enum: `running`, `waiting_confirmation`, `completed`, `stopped`, `failed`. Confirmation modes: `proceed`, `answer`, `decision_on_failure`.

## Monitor transitions (opencode:monitor)

- Only polls `opencode_workflows` rows with status `Running`; when the table has no running rows it prints "No running opencode workflows." Manual TUI sessions of the user are NOT notified by design — only workflows that have a DB row are tracked.
- On `finished`: complete the current step (`completeCurrentStep` only touches steps with status `Running` — matching `current_step`, falling back to the most recent Running step), build the summary (last assistant text, truncated to 2000 chars), then transition:
  - Last assistant ends with a question → `WaitingConfirmation` + `confirmation_mode = Answer`.
  - A next step exists → `WaitingConfirmation` + `Proceed` (message asks "¿Continúo con el paso ...?").
  - Final step and `notify()` returned true → `Completed` (+ `completed_at`).
  - Final step but notify failed → STAYS `Running` with `failure_count++` so the next tick re-detects the finished session and retries the notification (never `WaitingConfirmation` — the monitor only polls `running`, so it would freeze).
- A successful tick resets `failure_count` to 0. `MAX_FAILURES` (3) consecutive failures (check `OpencodeException`, null `opencode_session_id`, or a failed final notify) → `Failed` + `DecisionOnFailure` with a `/retry` or `/abort` hint. `last_summary` always stores the sent message.
- Plan steps get the executive-summary prefix `"Plan listo — resumen ejecutivo:"` in the notification.

## Config from DB (project rule)

- `opencode_settings` singleton row (`root_projects_path` default `/home/junior/Projects`, `mcp_command`), managed via `opencode:settings` (`--show`/`--root`/`--mcp-command`), which rejects the filesystem root `/` and validates the path is absolute and exists.
- The opencode orchestration instructions live in `bot_skills` ("Opencode Session Orchestration" skill, created via tinker — personal user data, NOT a seeder) — see the devwarden-bot-skills skill.

## Ops

- Restart `composer run dev:full` after code changes so the long-running queue worker loads the new classes (it caches them).
- `npm i -g opencode-mcp` is installed; cost is ~$0.002 per requirement.

## When to use me

Load this skill when touching the opencode tools, `OpencodeSessionManager`/MCP calls, the `opencode:monitor` state machine, workflow templates or step transitions, opencode settings, or when debugging bot notifications or adoption of opencode sessions related to workflows.
