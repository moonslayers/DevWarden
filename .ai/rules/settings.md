---
paths:
  - 'app/Http/Controllers/Settings/TelegramController.php,app/Http/Requests/Settings/TelegramSettingsUpdateRequest.php,resources/js/pages/settings/Telegram.vue'
  - 'app/Http/Controllers/Settings/BotController.php,app/Http/Requests/Settings/BotSettingsUpdateRequest.php,resources/js/pages/settings/Bot.vue'
---

# Settings

## Telegram settings: never expose bot_token, normalize allowed_user_ids in FormRequest
TelegramController::edit sends has_bot_token (boolean) + allowed_user_ids + polling_enabled only — never the raw bot_token. TelegramSettingsUpdateRequest::prepareForValidation normalizes allowed_user_ids (comma-separated string or int array) to an int array before the `array`/`integer`/`min:1` rules; controller keeps the existing token when the submitted bot_token is blank. Polling toggle uses the shadcn Switch (reka-ui SwitchRoot) with a hidden polling_enabled input because <Form> serializes named inputs only.

## Bot settings: reka-ui Select sentinel for nullable owner, default max_history in FormRequest
BotController::edit sends system_prompt, max_history_messages, owner_user_id plus a users list ({id, name}) for the owner selector. BotSettingsUpdateRequest defaults max_history_messages to 50 via prepareForValidation when absent and validates nullable owner_user_id against users. The Vue page uses the reka-ui Select with a sentinel value 'none' for the empty owner option (SelectItem throws on value="") and a hidden owner_user_id input that submits '' (ConvertEmptyStringsToNull makes it null) when 'none' is selected.
