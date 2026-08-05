---
name: devwarden-subagents-vision
description: TRIGGER when working on DevWarden's "sub-agents + vision" module — bot_sub_agents table/model (BotSubAgentType enum, is_system default vision, FK ai_provider_id → ai_providers), sub_agent_usage_logs, VisionAgent/VisionWorkflowContext/AskVisionTool, the BotAgent buildPromptWithImage + respond(?string $imagePath) integration, the incoming-photo pipeline (photo_file_id, TelegramClient getFile/downloadFile, telegram-media/incoming), the /subagents main page (SubAgentController + SubAgentStoreRequest/SubAgentUpdateRequest + subagents:prune-usage + subagents/Index.vue), or the reka-ui Switch state traps. Load when adding/managing sub-agents, vision delegation, or the sub-agents settings/UI.
license: MIT
metadata:
  author: devwarden
---

# DevWarden Sub-agents + Vision

Sub-agents are specialized AI agents the Telegram bot can delegate to. The shipped feature is a **vision** sub-agent: when the user sends a photo, the pipeline hands it to the vision agent for a description (or follow-up answers via a tool), and a **general** type for future delegation. Configuration lives in `bot_sub_agents`, usage is tracked in `sub_agent_usage_logs`, and everything is managed from the `/subagents` main-nav page. This skill owns the sub-agent/vision specifics; for the poll → job → reply send flow see `devwarden-telegram-ai-pipeline`.

## Architecture map

### Data layer

- `app/Models/BotSubAgent.php` — table `bot_sub_agents`. Uses the `#[Fillable]` attribute + `casts()` method (app convention). Columns (migration `2026_08_05_133342_create_bot_sub_agents_table.php`): `name`, `slug` (unique), `type`, `description`/`system_prompt` (nullable text), `ai_provider_id` (nullable FK → ai_providers, `nullOnDelete`), `model` (nullable string), `is_active` (default false), `is_system` (default false), `sort_order` (default 0), index `['is_active', 'sort_order']`.
  - Scopes: `active()` (is_active), `ordered()` (sort_order ASC), `vision()` (type = `BotSubAgentType::Vision`).
  - `aiProvider()` belongsTo AiProvider; `usageLogs()` hasMany with the EXPLICIT FK `sub_agent_id` — must stay declared or withCount/withSum breaks (see `.ai/rules/models-models.md`).
  - `usesSystemProvider(): bool` = `ai_provider_id === null` (falls back to the main failover chain).
  - `activeVision(): ?self` = first **active** vision sub-agent ordered by `sort_order` where `ai_provider_id` is null OR the referenced provider has `is_enabled = true`. This is the single source of truth for "is vision on" used by the bot, the page stats and the tools list.
- `app/Enums/BotSubAgentType.php` — string-backed `Vision='vision' | General='general'`.
- `app/Models/SubAgentUsageLog.php` — table `sub_agent_usage_logs` (migration `2026_08_05_133343_create_sub_agent_usage_logs_table.php`): `sub_agent_id` FK cascade delete, `chat_id` nullable, `kind` string, `tokens` nullable unsignedInteger, `created_at` index. Scope `byKind($kind)`; `tokens`/`chat_id` cast to integer. **Note `$fillable` excludes timestamps — `create([... 'created_at' => ...])` silently drops it** (set via direct attribute before save if you need it).
- `database/seeders/BotSubAgentSeeder.php` — idempotent `updateOrCreate(['slug' => 'vision'], ['name' => 'Vision', 'type' => Vision, 'is_system' => true, 'sort_order' => 0])`. Only system identity fields are (re)asserted — **user-set `is_active`, `ai_provider_id`, `model`, `system_prompt` on an existing row are NEVER reset by re-seeding**. Called from `DatabaseSeeder`.

### Business rules (enforced in code + `tests/Feature/SubAgentPageTest.php`)

- The system vision default **cannot be deleted**: `SubAgentController::destroy()` returns an error toast and `back()` when `is_system` (frontend hides the Delete button on system rows anyway).
- **Enabling vision requires a provider (must be enabled) + model**: `SubAgentUpdateRequest` uses `Rule::exists('ai_providers', 'id')->where('is_enabled', true)` plus `Rule::requiredIf($activatingVision)` on `ai_provider_id` and `model`. `isActivatingVision()` = the row is a vision sub-agent AND the submitted `is_active` is truthy (after `prepareForValidation` boolean normalization).
- **System rows are partially locked on update**: `SubAgentController::update()` for `is_system` only writes `system_prompt`, `ai_provider_id`, `model`, `is_active`; `name`/`slug`/`type`/`sort_order` stay immutable (frontend also makes name/slug readonly). `is_active` keeps its current value when not submitted (`$data['is_active'] ?? $subAgent->is_active`).
- **New sub-agents are always type=general**: `SubAgentStoreRequest` accepts no type and `store()` force-sets `type => BotSubAgentType::General`.
- `SubAgentStoreRequest`/`SubAgentUpdateRequest` both `prepareForValidation()` → `is_active` boolean via `$this->boolean(...)` and `sort_order` defaults to 0.

### Vision engine

- `app/Ai/Agents/VisionAgent.php` — `implements Agent`, uses `Promptable`. Stateless on purpose (no conversation memory, no tools). `describe(string $imagePath, string $userContext)` and `ask(string $question, string $imagePath)` share the same skeleton: `syncer->sync()` at the top (config-from-DB rule), `requireActiveVision()` (throws `RuntimeException` when none — callers guard), resolve `[$provider, $model]`, `prompt(..., attachments: [Image::fromPath($imagePath)], provider, model)`, then best-effort `recordUsage(...)`, returning `$response->text`.
  - `providerAndModelFor(BotSubAgent)`: pinned provider (`ai_provider_id` set, provider row non-null) → `[$provider->provider->value, $subAgent->model ?? $provider->model_text]`; otherwise System → `[$syncer->chain(), $subAgent->model]` (the failover array + the sub-agent's own model).
  - `instructions()` = `activeVision()?->system_prompt ?: DEFAULT_INSTRUCTIONS`.
  - `recordUsage()` NEVER throws: wrapped in `catch(Throwable)` → `Log::warning`. `chat_id` comes from `VisionWorkflowContext::chatId()` (see trap 4). `tokens` = `usage->promptTokens + usage->completionTokens`.
- `app/Ai/Context/VisionWorkflowContext.php` — static per-turn holder (style of `OpencodeWorkflowContext`): `set(?string $imagePath, ?int $chatId = null)`, `imagePath()`, `chatId()`, `hasImage()`, `clear()`. Binds the incoming image + chat for the duration of one `respond()` and is cleared in a `finally` so a long-running queue worker never leaks one chat's image into another.
- `app/Ai/Tools/AskVisionTool.php` — lets the main agent ask targeted follow-ups about the image bound to the current turn. Reads `VisionWorkflowContext::imagePath()`; when null returns `'No hay imagen en este turno.'`; empty question → `'Error: missing required "question" argument.'`; otherwise delegates `vision->ask($question, $imagePath)`. Schema: `question` (required string). Registered in `BotAgent::tools()` **only when `BotSubAgent::activeVision() !== null`** — no active vision, no tool.

### BotAgent integration

`BotAgent::respond(int $chatId, string $text, User $owner, ?string $imagePath = null)`:

1. `syncer->sync()` at the top.
2. Conversation mapping via `TelegramChatConversation::firstOrCreate(['chat_id' => $chatId], ...)`, then `buildPromptWithMemories` → `buildPromptWithSkills`.
3. `VisionWorkflowContext::set($imagePath, $chatId)` MUST run BEFORE `buildPromptWithImage()` (see trap 4), inside the same `try` whose `finally` clears both `VisionWorkflowContext` and `OpencodeWorkflowContext`.
4. `buildPromptWithImage($prompt, $rawUserText, $imagePath)`:
   - no image → `[$prompt, []]`;
   - image but **no active vision** → attach the raw image: `[$prompt, [Image::fromPath($imagePath)]]` (the main agent's own model reads it);
   - image + active vision → `VisionAgent::describe($imagePath, $rawUserText)` and prepend `<image_description>\n{...}\n</image_description>` with NO attachments; a describe failure is caught → `Log::warning` → **degrades to attaching the raw image** (never fails the whole reply).

### Incoming photo pipeline (debounce/batch)

- `PollTelegramUpdates` (`telegram:poll`) now buffers BOTH text and photo messages. `TelegramClient::normalizeUpdate()` returns `{update_id, chat_id?, message_id?, text?, photo?, edit?}` — for a photo it sets `text` from the caption (when present) and `photo` = the **largest-area** `PhotoSize.file_id`. The poll passes it on as `storeMessage($chatId, $messageId, $text, $updateId, $isEdit, $photoFileId)`.
- `TelegramMessageBuffer::storeMessage()` upserts on the unique `(chat_id, message_id)` pair and persists `photo_file_id` (migration `2026_08_05_142030_add_photo_file_id_to_telegram_pending_messages_table.php`; `TelegramPendingMessage::$fillable` includes it). Debounce 5s / stale reclaim 15 min unchanged.
- `ProcessTelegramPendingBatch` (tries=1, drain capped at `MAX_DRAIN_ITERATIONS = 5`): `buildTurns($pending)` = **ONE AI turn per photo message (ordered by row id), consecutive text-only messages coalesced into a single numbered turn**. Each turn in `processTurn()`:
  - sends its own thinking placeholder;
  - if it has a `photoFileId`: `resolvePhotoPath()` → `TelegramClient::getFile($fileId)` for `file_path`, computes the relative path `telegram-media/incoming/{chatId}-{messageId}.{ext}` **BEFORE any download** (a partial download is still cleaned up by the caller's finally), extension from an allowlist `jpg|jpeg|png|webp|gif` else fallback `jpg`; `downloadIncomingPhoto()` → `Storage::disk('local')->makeDirectory('telegram-media/incoming')` (single positional arg — see `.ai/rules/jobs-services-telegram.md`) + `TelegramClient::downloadFile($filePath, $disk->path($relativePath))`;
  - calls `BotAgent::respond($chatId, $turn['text'], $owner, $imagePath)`;
  - dispatches `SendTelegramReply` + one `CaptureBotMemory` per turn (source = that turn's `update_id`; **skipped when the reply is `FRIENDLY_ERROR_MESSAGE`**);
  - `finally` deletes the downloaded file on success AND failure.
  - The whole per-turn body is wrapped in `catch(Throwable)` → friendly error message, so one bad photo never aborts the drain.

### Main page `/subagents` (NOT settings)

- Routes in `routes/web.php` inside the `['auth', 'verified']` group: `GET|POST subagents` → `subagents.index|store`, `PATCH subagents/{subAgent}` → `subagents.update`, `DELETE subagents/{subAgent}` → `subagents.destroy`. Sidebar item in `resources/js/components/AppSidebar.vue`.
- `app/Http/Controllers/SubAgentController.php`:
  - `index` props: `subAgents` (ordered, each with `uses_system_provider` + `invocations`/`tokens` aggregates via a groupBy on `sub_agent_usage_logs`), `providers` (enabled providers + any referenced-but-disabled provider stays selectable; `is_main` = the first `AiProvider::enabledOrdered()`), `types` (from the enum), `stats` (`total`, `active`, `visionActive` = `activeVision() !== null`, `generalCount`, `totalInvocations`, `totalTokens`, `invocationsByKind` via `SubAgentUsageLog::byKind()`, `invocationsLast14d`/`tokensLast14d` via `TimeSeriesService::bucketDaily()` over 14 days).
  - `store` forces type General; `update` locks identity on system rows; `destroy` blocks system rows (error toast).
- FormRequests: `app/Http/Requests/SubAgentStoreRequest.php`, `app/Http/Requests/SubAgentUpdateRequest.php`.
- `app/Console/Commands/PruneSubAgentUsageLogs.php` — `subagents:prune-usage --days=90` deletes usage logs older than the cutoff; scheduled `->daily()` in `bootstrap/app.php`.
- `resources/js/pages/subagents/Index.vue` — 4 KPI cards + 2 StatChart charts (bar = invocations/day, line = tokens/day, both 14 days, empty states), a New sub-agent form, a type filter, and COMPACT per-sub-agent cards with an Edit toggle. The card body only renders while `editingState[id]` is true; the Inertia `Form` gets `:on-success="() => closeEdit(subAgent.id)"` so the card collapses to compact view right after a successful save. Provider Select offers "System (provider principal)" (`NO_PROVIDER = 'none'`) plus each provider labelled with ` · Principal` when `is_main`; hidden inputs (`ai_provider_id`, `is_active`) feed the Form because Inertia's `<Form>` serializes named inputs only.

## Critical traps

1. **reka-ui Switch is modelValue-driven — bind with `v-model`, NEVER `:checked`/`@update:checked`/`v-model:checked`.** The project's `ui/switch/Switch.vue` is a pass-through over reka-ui `SwitchRoot`, which emits ONLY `update:modelValue` (no `checked` prop). `:checked` + `@update:checked` silently breaks state — the event never fires, the toggle LOOKS like it moves but the hidden input always submits `is_active='0'`. This exact bug shipped in `subagents/Index.vue`. Always pair `v-model` with a hidden `'1'`/`'0'` input (see `.ai/rules/js.md`).
2. **State Records must be real Records, not reactive arrays.** `providerState`/`activeState` are built with `Object.fromEntries(props.subAgents.map(...))` so `providerState[id]`/`activeState[id]` is stable and reactive — `reactive(array.map(...))` creates sparse/array semantics that break `v-model="activeState[subAgent.id]"`.
3. **`ai_providers.provider` is UNIQUE.** A vision sub-agent on the SAME endpoint but a DIFFERENT model cannot reuse the existing provider row — use an `openai-compatible` provider row (extra `base_url`) or select the System provider and rely on the sub-agent's own `model` field.
4. **usage `chat_id` is only populated when `VisionWorkflowContext` is bound before `describe()`.** `VisionAgent::recordUsage()` reads `VisionWorkflowContext::chatId()`; binding after `buildPromptWithImage()` left every `kind=describe` log with `chat_id = null` (asymmetry vs `kind=ask`). Keep `VisionWorkflowContext::set($imagePath, $chatId)` before `buildPromptWithImage()` (see `.ai/rules/agents.md`).

## When to use me

Load this skill when touching sub-agents: `bot_sub_agents`/`sub_agent_usage_logs` models or migrations, `BotSubAgent::activeVision()`, `VisionAgent`/`VisionWorkflowContext`/`AskVisionTool`, `BotAgent::respond()`/`buildPromptWithImage()`, the incoming photo pipeline (`photo_file_id`, `getFile`/`downloadFile`, `telegram-media/incoming`), `SubAgentController`, the sub-agents FormRequests, `subagents:prune-usage`, or `resources/js/pages/subagents/Index.vue`. For the poll → job → reply send flow, config-from-DB sync, the thinking placeholder or Telegram HTML formatting, load `devwarden-telegram-ai-pipeline`; for the vector memory layer load `devwarden-bot-memory`.
