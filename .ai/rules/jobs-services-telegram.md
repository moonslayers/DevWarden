---
paths:
  - 'app/Jobs/**,app/Services/Telegram/**'
---

# Jobs Services Telegram

## Storage::makeDirectory takes only $path (no force: named arg)
Laravel 13 FilesystemAdapter::makeDirectory($path) accepts a single positional arg (the underlying Flysystem createDirectory is already recursive). Calling it with named params like force: true throws "Unknown named parameter $force". Use `$disk->makeDirectory('telegram-media/incoming');`.

## HandleTelegramCallbackQuery was removed (inline-button pipeline deleted)
The inline-question keyboard pipeline is GONE by user decision: the HandleTelegramCallbackQuery job, the oq:{session_id}:{question_index}:{option_index} callback_data contract, answerCallbackQuery and the poller's callback routing were all removed. Do not reintroduce them. Question notifications are plain text and the bot answers pending opencode questions via OpencodeAskTool; the single responder is the AI agent, not a callback job.
