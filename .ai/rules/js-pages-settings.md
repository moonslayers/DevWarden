---
paths:
  - 'app/Http/Requests/Settings/*,resources/js/pages/settings/**'
---

# Js Pages Settings

## Bot skills: trigger_keywords sent comma-separated, parsed to array in FormRequest
SkillStoreRequest/SkillUpdateRequest receive trigger_keywords as a comma-separated string from the Skills.vue text input; prepareForValidation() normalizes it to a trimmed string array (or null when empty) before the `array`/`*` string rules, mirroring Telegram allowed_user_ids. The frontend joins existing keywords with ', ' via :default-value. Active is a Switch + hidden '1'/'0' input, boolean()d in the request; sort_order defaults to 0. slug uses Rule::unique ignore($this->route('skill')) on update.
