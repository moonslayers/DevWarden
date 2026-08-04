<?php

namespace App\Jobs;

use App\Ai\Agents\BotAgent;
use App\Models\BotSetting;
use App\Models\User;
use App\Services\AiConfigSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate the bot reply for a single Telegram text update and hand the message
 * off to SendTelegramReply, so a send failure only retries the send.
 *
 * Dependencies are resolved in handle() instead of the constructor so the job
 * stays serializable for the queue: BotAgent/AiConfigSyncer resolve services
 * that hold Guzzle clients whose handler stacks contain closures, which would
 * break serialization on dispatch.
 */
class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Shown to the user when the AI generation fails (e.g. all providers down).
     */
    public const FRIENDLY_ERROR_MESSAGE = 'Lo siento, no pude procesar tu mensaje. Inténtalo de nuevo más tarde.';

    /**
     * Attempts per job. AI generation failures are caught and return a friendly
     * message, so retries only cover a transient failure dispatching the send job.
     */
    public int $tries = 3;

    /**
     * Exponential backoff between retries, in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 60];

    public function __construct(
        public int $chatId,
        public string $text,
    ) {
        //
    }

    /**
     * Resolve the bot owner, re-sync the AI config, generate a reply and hand it
     * off to the send job.
     */
    public function handle(AiConfigSyncer $syncer): void
    {
        $owner = $this->resolveOwner();

        if ($owner === null) {
            Log::warning('Telegram update skipped: no owner user is configured.', [
                'chat_id' => $this->chatId,
            ]);

            return;
        }

        $syncer->sync();

        try {
            $reply = app(BotAgent::class)->respond($this->chatId, $this->text, $owner);
        } catch (Throwable $e) {
            Log::warning('Telegram update failed: AI generation error.', [
                'chat_id' => $this->chatId,
                'exception' => $e->getMessage(),
            ]);

            SendTelegramReply::dispatch($this->chatId, self::FRIENDLY_ERROR_MESSAGE);

            return;
        }

        SendTelegramReply::dispatch($this->chatId, $reply);
    }

    /**
     * The user the bot answers for, or null when not configured or deleted.
     */
    private function resolveOwner(): ?User
    {
        $ownerId = BotSetting::singleton()->owner_user_id;

        if ($ownerId === null) {
            return null;
        }

        return User::query()->find($ownerId);
    }
}
