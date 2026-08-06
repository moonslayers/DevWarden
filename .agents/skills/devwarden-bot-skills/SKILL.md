---
name: devwarden-bot-skills
description: TRIGGER when working on DevWarden's conditional bot skills system — the bot_skills table/model, BotSkill::matches(), the BotAgent::buildPromptWithSkills() injection into the prompt, the persona-vs-skills split in bot_settings.system_prompt, the skill_usage_logs usage tracking, or the top-level Skills page (/skills, SkillController + FormRequests + Skills.vue). Load when adding/managing bot skills, editing BotAgent prompt construction, or touching the skills page and its usage charts.
license: MIT
metadata:
  author: devwarden
---

# DevWarden Bot Skills

The bot has no native skills support, so DevWarden ships its own: instruction blocks stored in the database (`bot_skills`) that the agent injects into the prompt only when they apply. This skill captures the model, the injection rules, the persona-vs-skills split, the usage tracking, and the top-level Skills page.

## Core rule: no native skills in laravel/ai

laravel/ai v0.10.2 has NO native skills feature (verified: zero matches in `vendor/`). The system here is fully custom: DB table + Eloquent model + prompt injection in `BotAgent` + usage logging + a top-level page.

## Table and model

- `bot_skills`: `name`, `slug` (unique), `description` (nullable), `content` (longText — the instruction block injected verbatim), `trigger_keywords` (json, nullable), `active` (bool, default true), `sort_order` (unsignedInteger, default 0), index `['active', 'sort_order']`.
- `App\Models\BotSkill` — `matches(string $text): bool`: returns true only when the skill is `active`, has at least one non-empty trigger keyword, and a keyword is a case-insensitive substring of the text (`Str::contains($text, $keyword, true)`). Skills without keywords never match on text alone. Scopes `active()` and `ordered()` (orderBy `sort_order`); casts `trigger_keywords` array, `active` boolean, `sort_order` integer.

## Prompt injection (buildPromptWithSkills)

`BotAgent::buildPromptWithSkills($chatId, $text)` prepends a `<skill name="...">` block per matching skill:

```text
<skill name="Opencode Session Orchestration">
{content}
</skill>
```

- A skill applies when `$skill->matches($text)` OR the chat has an active opencode workflow (an `opencode_workflows` row with status `running` or `waiting_confirmation`). When no skill applies the text is returned unchanged.
- Order of the final prompt: **skills → memories → user text**. `respond()` runs `buildPromptWithMemories($chatId, $text)` FIRST and `buildPromptWithSkills($chatId, $prompt)` on its result, so the skills block wraps the memories block.

## System prompt split (persona vs features)

- `bot_settings.system_prompt` holds ONLY the user's persona ("Tu nombre es Myu, eres assistente de Moonslayers..."). There is no feature-instruction logic in it.
- Feature instructions (opencode orchestration, etc.) go into `bot_skills` as conditional skills, never into the system prompt.
- The "Opencode Session Orchestration" skill was created via tinker (personal user data) — it is NOT a seeder.

## Skills page (main menu)

- Routes: `skills.index/store/update/destroy` in `routes/web.php` (`['auth', 'verified']` group) → `App\Http\Controllers\SkillController` (index renders the top-level component `'Skills'` with the `skills` collection + a `stats` prop; store/update/destroy validate + `Inertia::flash('toast', [...])` + `back()`).
- `SkillStoreRequest`/`SkillUpdateRequest`: `trigger_keywords` nullable array (max 50, each ≤ 100 chars) — `normalizeTriggerKeywords()` in `prepareForValidation()` converts a comma-separated string OR array into a trimmed array, null when empty; `active` via `boolean()`; `sort_order` default 0. Update ignores the current row for the slug uniqueness (`Rule::unique(...)->ignore($this->route('skill'))`).
- `resources/js/pages/Skills.vue`: inline CRUD with Wayfinder declarative forms (`SkillController.store.form()` / `update.form(id)` / `destroy.form(id)`, imports `@/actions/App/Http/Controllers/SkillController` + `@/routes/skills`), `Switch` for active (bound with `v-model`, wired to a hidden `name="active"` input with `'1'`/`'0'`), `Dialog` delete confirmation, `keywordsToText()` renders the keywords back to a comma-separated input. Nav item lives in `mainNavItems` of `resources/js/components/AppSidebar.vue` (after 'Sub-agents', icon Sparkles); the settings layout no longer lists Skills.

## Usage tracking & charts

- `skill_usage_logs`: `skill_id` (FK → `bot_skills`, cascade on delete), `chat_id` (nullable), `created_at` only (no `updated_at` — model sets `UPDATED_AT = null`), index `['skill_id', 'created_at']`. Model `App\Models\SkillUsageLog` (`belongsTo BotSkill`).
- `BotAgent::buildPromptWithSkills()` records best-effort usage: one row per matched skill (same `created_at` timestamp) inside a try/catch that logs a warning and never throws. Logging happens after the blocks are built, so the returned prompt is byte-identical whether or not the insert succeeds.
- The top-level page renders two charts from the `stats` prop: a line "Skill matches per day" (14 days) and a bar "Most used skills", each with a "No skill usage yet." empty state.
- `stats` prop contract (from `SkillController::stats()`): `{ total_matches: int, active_count: int, inactive_count: int, matches_by_day: {labels: string[], count: int[]}, top_skills: [{id: int, name: string, match_count: int}] }` — `matches_by_day` bucketed daily via `TimeSeriesService::bucketDaily()`, `top_skills` = top 5 by match count.

## Gotchas

- `instructions()` is evaluated per generation (natural hook) — the `sync()`-inside-instructions trap is forbidden by the `.ai/rules/agents.md` rule; call `AiConfigSyncer::sync()` at the top of `respond()` instead.
- AssertableInertia tests of the skills page need `npm run build` first (Vite manifest) or the test fails on the missing manifest.
- Vue 3 UI trap (fixed): a plain `<textarea :default-value="...">` does NOT populate its value on render — bind `:value` to a local ref (or use v-model + `watch`). This is why the update-form content textarea binds `:value="skill.content"` and the owner select in `settings/Bot.vue` uses the `ownerUserValue` ref+watch pattern.
- reka-ui `Switch` is modelValue-driven — bind with `v-model`, never `:checked` + `@update:checked` (the event never fires, so state silently breaks). Always pair it with a hidden `name="active"` input carrying `'1'`/`'0'`, because Inertia's `<Form>` serializes only named inputs (see `.ai/rules/js.md`).

## When to use me

Load this skill when adding, editing, or deleting bot skills, changing the prompt construction in `BotAgent` (injection order, matching rules, block format), deciding whether instructions belong in the system prompt or as a skill, or working on the top-level Skills page (`/skills`, `Skills.vue`), its FormRequests/controller, or the usage tracking (`skill_usage_logs`, `SkillUsageLog`).
