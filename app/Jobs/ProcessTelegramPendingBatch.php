<?php

namespace App\Jobs;

use App\Ai\Agents\BotAgent;
use App\Models\BotSetting;
use App\Models\TelegramPendingMessage;
use App\Models\User;
use App\Services\AiConfigSyncer;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramMessageBuffer;
use App\Services\Telegram\ThinkingIndicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Drain every buffered pending message of a chat, one AI turn per batch.
 *
 * Rapid text messages are coalesced by TelegramMessageBuffer into a single batch
 * and answered with one AI call, while every incoming photo becomes its own AI
 * turn (the image is downloaded, passed to the model as an attachment and
 * deleted afterwards). A "thinking" placeholder is sent before each turn and the
 * reply replaces it. Messages that arrive while the AI is running are picked up
 * by the next drain iteration and answered as a fresh batch.
 *
 * Dependencies are resolved in handle() instead of the constructor so the job
 * stays serializable for the queue: BotAgent/AiConfigSyncer/TelegramClient hold
 * Guzzle clients whose handler stacks contain closures, which would break
 * serialization on dispatch.
 *
 * The job never fails the queue chain: photo downloads and AI generation
 * failures dispatch the friendly message for the affected turn and the drain
 * continues. Self-healing is provided by the finally block (endProcessing re-arms
 * for stragglers) plus the stale processing reclaim in scheduleIfNeeded(), which
 * is why tries is 1 — a retry would re-run AI that was already consumed.
 *
 * The drain is capped at MAX_DRAIN_ITERATIONS iterations per job so a chat that
 * floods the buffer mid-generation cannot starve the queue worker; anything left
 * behind is re-armed by endProcessing() and drained by a fresh job.
 */
class ProcessTelegramPendingBatch implements ShouldQueue
{
    use Queueable;

    /**
     * Shown to the user when the AI generation fails (e.g. all providers down).
     */
    public const FRIENDLY_ERROR_MESSAGE = 'Lo siento, no pude procesar tu mensaje. Inténtalo de nuevo más tarde.';

    /**
     * Maximum drain iterations per job. A chat flooding the buffer mid-generation
     * must not starve the queue worker, so the loop stops here and endProcessing()
     * re-arms a fresh batch for whatever is left.
     */
    public const MAX_DRAIN_ITERATIONS = 5;

    /**
     * Attempts per job. AI failures are caught and return the friendly message,
     * so a retry would only re-run AI that was already consumed; recovery is
     * handled by endProcessing() and the stale-processing reclaim instead.
     */
    public int $tries = 1;

    /**
     * @param  int  $chatId  The Telegram chat whose pending messages to drain.
     */
    public function __construct(
        public int $chatId,
    ) {
        //
    }

    /**
     * Drain the buffered messages, processing one AI turn per pending batch,
     * re-checking the buffer between iterations so messages that arrived
     * mid-generation are absorbed in the same drain.
     */
    public function handle(
        AiConfigSyncer $syncer,
        ThinkingIndicator $indicator,
        TelegramClient $telegram,
        TelegramMessageBuffer $buffer,
    ): void {
        $owner = $this->resolveOwner();

        if ($owner === null) {
            Log::warning('Telegram batch skipped: no owner user is configured.', [
                'chat_id' => $this->chatId,
            ]);

            $buffer->deletePending($buffer->pendingFor($this->chatId));

            return;
        }

        $syncer->sync();

        $buffer->beginProcessing($this->chatId);

        $iteration = 0;

        try {
            while ($iteration < self::MAX_DRAIN_ITERATIONS) {
                $pending = $buffer->pendingFor($this->chatId);

                if ($pending->isEmpty()) {
                    break;
                }

                $iteration++;

                foreach ($this->buildTurns($pending) as $turn) {
                    $this->processTurn($turn, $owner, $indicator, $telegram);
                }

                $buffer->deletePending($pending);
            }
        } finally {
            $buffer->endProcessing($this->chatId);
        }
    }

    /**
     * Run one AI turn: send a "thinking" placeholder, optionally download the
     * incoming photo and hand it to the model, dispatch the reply and capture
     * memory. A failure in this turn must not abort the rest of the drain, and
     * the downloaded photo is always cleaned up (success and failure).
     *
     * @param  array{text: string, photoFileId: ?string, updateId: int, messageId?: int}  $turn
     */
    private function processTurn(array $turn, User $owner, ThinkingIndicator $indicator, TelegramClient $telegram): void
    {
        $placeholderMessageId = $indicator->sendPlaceholder($telegram, $this->chatId);

        $imagePath = null;
        $relativePath = null;
        $reply = self::FRIENDLY_ERROR_MESSAGE;

        try {
            if ($turn['photoFileId'] !== null) {
                [$filePath, $relativePath] = $this->resolvePhotoPath($turn, $telegram);
                $imagePath = $this->downloadIncomingPhoto($telegram, $filePath, $relativePath);
            }

            $reply = app(BotAgent::class)->respond($this->chatId, $turn['text'], $owner, $imagePath);
        } catch (Throwable $e) {
            Log::warning('Telegram batch failed: photo download or AI generation error.', [
                'chat_id' => $this->chatId,
                'exception' => $e->getMessage(),
            ]);

            $reply = self::FRIENDLY_ERROR_MESSAGE;
        } finally {
            if ($relativePath !== null) {
                Storage::disk('local')->delete($relativePath);
            }
        }

        SendTelegramReply::dispatch($this->chatId, $reply, $placeholderMessageId);

        if ($reply !== self::FRIENDLY_ERROR_MESSAGE) {
            CaptureBotMemory::dispatch($this->chatId, $turn['text'], $reply, (string) $turn['updateId']);
        }
    }

    /**
     * Split the pending messages into ordered AI turns.
     *
     * Consecutive text-only messages coalesce into a single numbered turn; every
     * photo message becomes its own turn (its caption/text, its file_id and the
     * message_id used for the download filename). Turn order follows the pending
     * row ids.
     *
     * @param  Collection<int, TelegramPendingMessage>  $pending
     * @return array<int, array{text: string, photoFileId: ?string, updateId: int, messageId?: int}>
     */
    private function buildTurns(Collection $pending): array
    {
        $turns = [];
        $textBuffer = [];

        foreach ($pending->values() as $message) {
            if ($message->photo_file_id === null) {
                $textBuffer[] = $message;

                continue;
            }

            if ($textBuffer !== []) {
                $turns[] = $this->buildTextTurn($textBuffer);
                $textBuffer = [];
            }

            $turns[] = [
                'text' => $message->text,
                'photoFileId' => $message->photo_file_id,
                'updateId' => (int) $message->update_id,
                'messageId' => (int) $message->message_id,
            ];
        }

        if ($textBuffer !== []) {
            $turns[] = $this->buildTextTurn($textBuffer);
        }

        return $turns;
    }

    /**
     * @param  array<int, TelegramPendingMessage>  $messages
     * @return array{text: string, photoFileId: null, updateId: int}
     */
    private function buildTextTurn(array $messages): array
    {
        return [
            'text' => $this->combineTexts(collect($messages)),
            'photoFileId' => null,
            'updateId' => (int) $messages[0]->update_id,
        ];
    }

    /**
     * Resolve the Telegram file reference and the relative disk path for the
     * turn's photo.
     *
     * The path is computed before any bytes are written, so a partial download
     * is still cleaned up by the caller's finally block.
     *
     * @param  array{text: string, photoFileId: ?string, updateId: int, messageId?: int}  $turn
     * @return array{0: string, 1: string} [Telegram file_path, relative disk path]
     *
     * @throws RuntimeException When Telegram reports no downloadable file.
     */
    private function resolvePhotoPath(array $turn, TelegramClient $telegram): array
    {
        $file = $telegram->getFile($turn['photoFileId']);
        $filePath = (string) ($file['file_path'] ?? '');

        if ($filePath === '') {
            throw new RuntimeException('Telegram getFile returned no file_path.');
        }

        $relativePath = sprintf(
            'telegram-media/incoming/%d-%d.%s',
            $this->chatId,
            $turn['messageId'],
            $this->photoExtension($filePath),
        );

        return [$filePath, $relativePath];
    }

    /**
     * The sanitized file extension for a downloaded photo: only known image
     * extensions are kept, anything else falls back to jpg.
     */
    private function photoExtension(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: '');

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
    }

    /**
     * Download the turn's photo into the given relative path on the local disk
     * and return its absolute local path.
     */
    private function downloadIncomingPhoto(TelegramClient $telegram, string $filePath, string $relativePath): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('telegram-media/incoming');

        $telegram->downloadFile($filePath, $disk->path($relativePath));

        return $disk->path($relativePath);
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

    /**
     * Join the buffered texts into a numbered list, one line per message in
     * insertion order, e.g. "1. First\n2. Second".
     *
     * @param  Collection<int, TelegramPendingMessage>  $pending
     */
    private function combineTexts(Collection $pending): string
    {
        return $pending->values()
            ->map(
                fn (TelegramPendingMessage $message, int $index): string => ($index + 1).'. '.$message->text,
            )
            ->implode(PHP_EOL);
    }
}
