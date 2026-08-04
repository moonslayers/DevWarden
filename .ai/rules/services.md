---
paths:
  - 'app/Services/**'
---

# Services

## AiConfigSyncer: DB→config sync requires manager-level forgetInstance
To feed DB provider credentials to laravel/ai at runtime: set config(['ai.providers.<name>' => ['driver'=>..., 'key'=>..., ('url' for openai-compatible when base_url set), 'models'=>['text'=>['default'=>...]] when model_text set]]), then app(\Laravel\Ai\AiManager::class)->forgetInstance('<name>') (manager-level, takes provider NAME). Only enabled providers (AiProvider::enabledOrdered()) are synced; set config(['ai.default']) to the first and config(['ai.conversations.generate_title' => false]). testConnection() syncs, then runs agent()->prompt(...) inside try/catch returning bool — never throws to the UI.

## AiConfigSyncer::sync() rebuilds config and prunes disabled providers
sync() does NOT incrementally write enabled providers. It rebuilds config('ai.providers') from AiProvider::enabledOrdered() only, forgetting every cached AiManager instance (old keys + new enabled names), and sets config('ai.default') to the first enabled or null when none. Providers disabled/removed get their config dropped so resolution can't reuse stale creds; null default makes resolution fail loudly. testConnection() only syncs the single provider (never the full sync) and returns false early for openai-compatible without model_text (SDK requires a default text model there).
