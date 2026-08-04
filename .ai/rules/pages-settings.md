---
paths:
  - 'app/Http/Controllers/Settings/AiProviderController.php,app/Http/Requests/Settings/AiProviderStoreRequest.php,app/Http/Requests/Settings/AiProviderUpdateRequest.php,resources/js/pages/settings/Providers.vue'
---

# Pages Settings

## AI Providers settings: never leak api_key, blank key keeps existing, default failover_order
AiProviderController::index sends has_api_key (boolean) per provider, never the raw api_key. UpdateRequest uses the bound route model ($this->route('provider')) to make base_url required for openai-compatible; update keeps the existing api_key when a blank one is submitted (same Telegram pattern). StoreRequest defaults failover_order to max existing + 1 and is_enabled to true via prepareForValidation. base_url input is shown only for openai-compatible rows. Test-connection button posts to providers.test and relies on the Inertia flash toast (syncer never throws to the UI).
