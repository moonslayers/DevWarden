---
paths:
  - 'app/**,app/Jobs/**,app/Services/**,app/Ai/**'
---

# Ai

## AI config comes only from the database — never .env/config files
AiConfigSyncer::sync() is the single source that writes DB provider credentials into config('ai.providers.<name>') at runtime; it clears cached instances with AiManager::forgetInstance('<name>') so the next SDK call re-reads the new values. Jobs re-sync at the start of handle() so long-running queue workers pick up fresh credentials. Never read AI keys/models from .env or config files for app features — the web UI (settings/providers) is the only entry point. config('ai.providers') stays empty until sync() runs.
