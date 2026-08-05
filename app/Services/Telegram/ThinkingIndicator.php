<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends and manages a "bot is thinking" placeholder message in a chat.
 *
 * The placeholder is best-effort: a failure to send, replace or delete it must
 * never break the reply pipeline. All Telegram calls receive the client as an
 * argument so the class stays stateless and trivially testable.
 */
class ThinkingIndicator
{
    /**
     * @var string[]
     */
    private const PHRASES = [
        'convirtiendo café en código...',
        'cargando el teclado cuántico...',
        'contando hasta infinito...',
        'convenciendo al modelo de que vea la imagen...',
        'puliendo la bola de cristal...',
        'consultando a la bola mágica...',
        'recorriendo los pasillos de la Matrix...',
        'fingiendo que sé lo que estoy haciendo...',
        'priorizando tu mensaje sobre 47 pestañas abiertas...',
        'escribiendo código impecable... (mentira)',
        'pensando en respuestas profundas...',
        'meditando sobre el sentido de la vida y tu mensaje...',
        'debugging en silencio, que es como mejor se debuggea...',
        'cargando la paciencia...',
        'estirando la pausa dramática...',
        'desempolvando la documentación...',
    ];

    /**
     * Pick a random placeholder phrase.
     */
    public function pickPhrase(): string
    {
        return self::PHRASES[array_rand(self::PHRASES)];
    }

    /**
     * Send the placeholder as a plain text message.
     *
     * @return int|null The sent message id, or null when sending failed.
     */
    public function sendPlaceholder(TelegramClient $telegram, int|string $chatId): ?int
    {
        try {
            $result = $telegram->sendMessage($chatId, $this->pickPhrase());
        } catch (Throwable $e) {
            Log::warning('Failed to send thinking placeholder.', ['exception' => $e]);

            return null;
        }

        return isset($result['message_id']) ? (int) $result['message_id'] : null;
    }

    /**
     * Replace a placeholder message with the final reply.
     *
     * Telegram reports "message is not modified" when the new text is
     * byte-for-byte identical to the current one; that is a silent success.
     *
     *
     * @throws TelegramApiException
     */
    public function replace(TelegramClient $telegram, int|string $chatId, int $messageId, string $html, ?string $parseMode = null): void
    {
        try {
            $telegram->editMessageText($chatId, $messageId, $html, $parseMode);
        } catch (TelegramApiException $e) {
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }

            Log::debug('Thinking placeholder already matches the final reply.', ['chat_id' => $chatId, 'message_id' => $messageId]);
        }
    }

    /**
     * Delete the placeholder message.
     */
    public function dismiss(TelegramClient $telegram, int|string $chatId, int $messageId): void
    {
        try {
            $telegram->deleteMessage($chatId, $messageId);
        } catch (Throwable $e) {
            Log::warning('Failed to delete thinking placeholder.', ['exception' => $e]);
        }
    }
}
