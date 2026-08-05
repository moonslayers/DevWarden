---
name: devwarden-dashboard
description: TRIGGER when working on DevWarden's dashboard — the Inertia page at GET /dashboard, its health/activity/usage props contract (tested with AssertableInertia), chart.js KPI charts (StatChart + useChartPalette), the SQLite json_extract aggregations over laravel/ai usage data, the daily time-series bucketing shared via App\Services\TimeSeriesService, or DashboardController::bucketDaily(). Load when touching KPIs, bot stats queries, or the dashboard Vue frontend.
license: MIT
metadata:
  author: devwarden
---

# DevWarden Dashboard

The dashboard is the app's single stats surface: AI provider health, Telegram wiring, conversation/message activity, and token usage, all driven by data the bot records automatically per AI reply. This skill captures the verified architecture, the normative props contract, and the non-obvious data/model facts.

## Architecture

- `app/Http/Controllers/DashboardController.php@index` → `Inertia::render('Dashboard', ['health' => ..., 'activity' => ..., 'usage' => ...])`.
- Route in `routes/web.php`: `Route::get('dashboard', ...)->name('dashboard')` inside the `['auth', 'verified']` group.
- Frontend:
  - `resources/js/pages/Dashboard.vue` — 3 sections (Health / Activity / Usage), all copy in English, per-chart empty states.
  - `resources/js/components/StatChart.vue` — generic wrapper over vue-chartjs for `line`/`bar`/`doughnut`.
  - `resources/js/composables/useChartPalette.ts` — palette from shadcn CSS vars + `StatDataset` types.
- Content padding lives in the layout, not the pages: `resources/js/layouts/app/AppSidebarLayout.vue` wraps its `<slot />` in a `px-4 py-6` container (breadcrumb header stays edge-to-edge outside it); `resources/js/layouts/settings/Layout.vue` provides the same for settings pages. Pages rendered under `AppLayout` must NOT add their own page padding (avoids double spacing).

## Props contract (normative, tested)

The exact structure is enforced by `tests/Feature/DashboardTest.php` via `AssertableInertia` — treat it as the contract. Do not change shape without updating that test.

- `health`: `{ providers: [{id, provider, is_enabled, model_text, has_credentials, failover_order}], telegram: {bot_configured, polling_enabled, allowed_users_count}, owner: {name} | null }`
- `activity`: `{ total_conversations, total_messages, linked_chats, user_messages, assistant_messages, messages_by_day: {labels: string[], user: number[], assistant: number[]} }`
- `usage`: `{ total_tokens: {prompt, completion, reasoning}, tokens_by_day: {labels: string[], prompt: number[], completion: number[]}, by_provider: [{provider, prompt_tokens, completion_tokens, messages}], by_model: [{model, ...}] }`

No secrets ever cross this boundary: only the `has_credentials` boolean (from `filled($provider->api_key)`) — never `api_key`/`bot_token`. Tests assert `missing('health.providers.0.api_key')` and that the raw response does not contain the secret strings.

## Data sources (important, non-obvious)

Conversations/messages have NO app model — use the laravel/ai package models `Laravel\Ai\Models\Conversation` and `ConversationMessage`. Assistant messages persist `usage` (prompt/completion/reasoning tokens) and `meta` (provider/model) as JSON columns with `array` casts; user messages keep both empty. AI usage data is recorded automatically with each bot reply — no dashboard-side bookkeeping.

## SQL aggregation (proven pattern)

Totals and per-provider/model breakdowns are computed entirely in SQL with SQLite `json_extract` + SUM/COUNT over the TEXT columns, never materializing rows to PHP:

- Totals: `COALESCE(SUM(JSON_EXTRACT(usage, '$.prompt_tokens')), 0)` for prompt/completion/reasoning on `role = 'assistant'`.
- Breakdowns: `COALESCE(JSON_EXTRACT(meta, '$.provider'), 'unknown')` grouped, ordered by `(prompt_tokens + completion_tokens) DESC`. Missing meta coalesces to `'unknown'`.
- Daily series (`tokens_by_day` / `messages_by_day`) window to 14 days: `created_at >= Carbon::today()->subDays(13)`.

## Daily time-series bucketing (TimeSeriesService)

The daily-series logic lives in **`app/Services/TimeSeriesService.php`** (`bucketDaily()`), shared by the dashboard AND `SubAgentController` (the sub-agents page's `invocationsLast14d`/`tokensLast14d` use it). `DashboardController::bucketDaily()` is now a thin static wrapper that just forwards to `app(TimeSeriesService::class)->bucketDaily(...)` — keep it as the entry point (dashboard code calls it via `static::bucketDaily(...)`), but do NOT reimplement the algorithm there.

Signature: `bucketDaily(Collection $items, string $dateField, callable $extractor, int $days = 14, array $seriesKeys = [])`. Returns `['labels' => ...]` plus one int series per key. Items outside the window are dropped; series not present in `seriesKeys` are created implicitly from extractor keys. Unit-tested in `tests/Unit/DashboardBucketingTest.php` (the service itself has no dedicated test — the bucketing behavior is covered there and via `SubAgentPageTest`).

Daily-bucket aggregation is timezone-sensitive: `bucketDaily()` parses `created_at` with `Carbon::parse()` in the app timezone (`config('app.timezone')`, set via `APP_TIMEZONE` in `.env`, currently `America/Los_Angeles`). Changing `APP_TIMEZONE` shifts daily bucket labels and mixes historical UTC rows with new local-time rows — a deliberate dev-time tradeoff.

## Chart.js setup

- chart.js 4.5.1 + vue-chartjs 5.3.4 (Vue 3).
- `StatChart` registers only: ArcElement, BarElement, CategoryScale, LinearScale, LineElement, PointElement, Tooltip, Legend.
- Props: `type` ('line' | 'bar' | 'doughnut'), `labels: string[]`, `datasets: StatDataset[]` (`{label, data, backgroundColor?, borderColor?}`), `height` (default 300).
- Doughnut charts must NOT include a Cartesian `scales` block (only line/bar add it).

## Trap: the `hsl()` wrapper (HIGH bug, fixed)

The CSS vars in `resources/css/app.css` (`--chart-1` … `--chart-5`, plus dark-mode variants) hold COMPLETE colors — `hsl(12 76% 61%)` — not components. `cssVar()` in `useChartPalette.ts` returns the value as-is; NEVER wrap it in `hsl(...)` (that yields invalid `hsl(hsl(...))` which breaks chart.js). The SSR fallback must also be a full color: `hsl(0 0% 45%)`. `useChartPalette` reads the vars through `useAppearance`'s `resolvedAppearance` so light/dark switching recolors charts.

## When to use me

Load this skill when touching the dashboard page, KPI cards, chart.js charts, bot stats/token queries, the health/activity/usage props contract, `bucketDaily()`, or the shared `TimeSeriesService` bucketing. Also load before changing anything in `DashboardController` or the dashboard Vue files to stay consistent with the tested contract.
