<?php

namespace App\Jobs;

use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionStore;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answer a Telegram inline-button callback for an opencode session question.
 *
 * The watcher attaches one inline keyboard per question notification; every
 * button's callback_data follows the `oq:{session_id}:{question_index}:
 * {option_index}` contract (base-0 indices), and this job is the ONLY handler
 * for that format. The option label is always resolved server-side from
 * OpencodeSessionStore::questionOptions() (never from the untrusted callback
 * payload), validated against the project whitelist, and forwarded to the
 * session via OpencodeSessionManager::reply().
 *
 * Dependencies are resolved in handle() instead of the constructor so the job
 * stays serializable for the queue (TelegramClient holds a Guzzle client whose
 * handler stack contains closures). The job never fails the queue chain: every
 * failure path answers the callback with an error alert and the whole body is
 * guarded so an unexpected Throwable logs and answers instead of crashing.
 *
 * The single answerCallbackQuery is sent at the END with the final outcome
 * (success without alert, failure with an alert); there is deliberately no
 * early acknowledgement from the poller, so error alerts stay accurate. tries
 * is 1: a retry would re-run the opencode reply, which is not idempotent.
 */
class HandleTelegramCallbackQuery implements ShouldQueue
{
    use Queueable;

    /**
     * Matches ONLY the watcher's callback_data contract
     * `oq:{session_id}:{question_index}:{option_index}` with base-0 indices.
     */
    private const CALLBACK_PATTERN = '/^oq:(ses_[A-Za-z0-9]+):(\d+):(\d+)$/';

    /**
     * Attempts per job. The opencode reply is fire-and-forget and not
     * idempotent, so a retry would risk answering a session twice.
     */
    public int $tries = 1;

    /**
     * @param  string  $callbackQueryId  Telegram callback_query id (used for answerCallbackQuery).
     * @param  int  $chatId  The chat the button was pressed in (already whitelisted by the poller).
     * @param  string  $callbackData  The button payload, e.g. `oq:ses_abc:0:1`.
     * @param  int|null  $callbackMessageId  Message id of the bot message holding the buttons, when present.
     */
    public function __construct(
        public string $callbackQueryId,
        public int $chatId,
        public string $callbackData,
        public ?int $callbackMessageId = null,
    ) {
        //
    }

    public function handle(
        OpencodeSessionStore $store,
        OpencodeSessionManager $manager,
        TelegramClient $telegram,
    ): void {
        try {
            $this->process($store, $manager, $telegram);
        } catch (Throwable $e) {
            Log::warning('HandleTelegramCallbackQuery: unexpected failure.', [
                'callback_query_id' => $this->callbackQueryId,
                'chat_id' => $this->chatId,
                'callback_data' => $this->callbackData,
                'error' => $e->getMessage(),
            ]);

            $this->answer($telegram, 'Error al procesar la opción', showAlert: true);
        }
    }

    /**
     * Run the answer pipeline: parse the payload, resolve the server-side option
     * label, validate the project whitelist and forward it to the session.
     */
    private function process(
        OpencodeSessionStore $store,
        OpencodeSessionManager $manager,
        TelegramClient $telegram,
    ): void {
        $parsed = $this->parseCallbackData($this->callbackData);

        if ($parsed === null) {
            $this->answer($telegram, 'Opción no disponible', showAlert: true);

            return;
        }

        [$sessionId, $questionIndex, $optionIndex] = $parsed;

        $questions = $store->questionOptions($sessionId);
        $option = $questions[$questionIndex]['options'][$optionIndex] ?? null;

        if ($option === null) {
            $this->answer($telegram, 'La pregunta ya no está disponible', showAlert: true);

            return;
        }

        $directory = $store->sessionState($sessionId)['directory'] ?? null;

        if ($directory === null) {
            $this->answer($telegram, 'No se pudo resolver la sesión', showAlert: true);

            return;
        }

        if (! $manager->isAllowedProject($directory)) {
            $this->answer($telegram, 'Proyecto no permitido', showAlert: true);

            return;
        }

        try {
            $manager->reply($sessionId, $directory, $option['label']);
        } catch (OpencodeException $e) {
            Log::warning('HandleTelegramCallbackQuery: failed to reply to the opencode session.', [
                'session_id' => $sessionId,
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);

            $this->answer($telegram, 'Error al responder a la sesión', showAlert: true);

            return;
        }

        $this->answer($telegram, 'Respuesta enviada a la sesión');
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null [session_id, question_index, option_index]
     */
    private function parseCallbackData(string $data): ?array
    {
        if (preg_match(self::CALLBACK_PATTERN, $data, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int) $matches[2], (int) $matches[3]];
    }

    /**
     * Answer the callback query without letting a Telegram failure bubble out:
     * the query may already be stale (answered by an earlier job) or the API may
     * be temporarily unreachable, neither of which should fail the queue.
     */
    private function answer(TelegramClient $telegram, ?string $text, bool $showAlert = false): void
    {
        try {
            $telegram->answerCallbackQuery($this->callbackQueryId, $text, $showAlert);
        } catch (Throwable $e) {
            Log::warning('HandleTelegramCallbackQuery: failed to answer the callback query.', [
                'callback_query_id' => $this->callbackQueryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
