---
paths:
  - 'app/Models/**,app/Services/**'
---

# Models Services

## failover_order is 0-based; adding providers = add rows, chain auto-built
ai_providers.failover_order is 0-based (0 = first in the failover chain). To add a provider later, just insert a new row via the web UI (settings/providers) — AiConfigSyncer::chain() rebuilds the provider array from AiProvider::enabledOrdered() automatically; there is no manual list to maintain. Providers with is_enabled=false are excluded from the chain.
