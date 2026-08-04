---
paths:
  - 'app/Models/**,database/migrations/**'
---

# Migrations

## Data layer conventions: failover_order, singleton accessors, secrets
ai_providers.failover_order is 0-based (0 = first in the failover chain; lower wins). Provider enum lives at App\Enums\AiProviderType (string-backed: openai, anthropic, deepseek, openai-compatible). Single-row settings tables (telegram_settings, bot_settings) expose `Model::singleton()` = firstOrCreate(['id'=>1])->refresh() (refresh loads DB defaults like polling_enabled/max_history_messages). All secrets (bot_token, api_key) use the `encrypted` cast. Ordering helper: AiProvider::enabledOrdered().
