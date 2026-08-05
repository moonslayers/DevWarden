<?php

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\ThinkingIndicator;

use function Pest\Laravel\mock;

/**
 * @return string[]
 */
function thinkingPhrases(): array
{
    return [
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
}

test('pickPhrase returns only phrases from the known list', function () {
    $indicator = new ThinkingIndicator;

    $picked = collect(range(1, 100))
        ->map(fn () => $indicator->pickPhrase())
        ->unique();

    expect($picked)->each->toBeIn(thinkingPhrases());
});

test('sendPlaceholder sends a plain text message and returns the message id', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->withArgs(function (int $chatId, string $text, ?string $parseMode = null) {
        expect($chatId)->toBe(123456789);
        expect($text)->toBeIn(thinkingPhrases());
        expect($parseMode)->toBeNull();

        return true;
    })->andReturn(['message_id' => 77]);

    $messageId = (new ThinkingIndicator)->sendPlaceholder($telegram, 123456789);

    expect($messageId)->toBe(77);
});

test('sendPlaceholder returns null without throwing when sending fails', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(TelegramApiException::class);

    $messageId = (new ThinkingIndicator)->sendPlaceholder($telegram, 123456789);

    expect($messageId)->toBeNull();
});

test('replace edits the message with the given text and parse mode', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('editMessageText')->once()->with(123456789, 42, '<b>ok</b>', 'HTML');

    (new ThinkingIndicator)->replace($telegram, 123456789, 42, '<b>ok</b>', 'HTML');
});

test('replace edits the message without a parse mode by default', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('editMessageText')->once()->with(123456789, 42, '<b>ok</b>', null);

    (new ThinkingIndicator)->replace($telegram, 123456789, 42, '<b>ok</b>');
});

test('replace treats a message is not modified error as silent success', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('editMessageText')->once()->andThrow(
        new TelegramApiException("Telegram API request 'editMessageText' failed: Bad Request: message is not modified"),
    );

    (new ThinkingIndicator)->replace($telegram, 123456789, 42, '<b>ok</b>', 'HTML');
});

test('replace propagates other telegram exceptions', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('editMessageText')->once()->andThrow(
        new TelegramApiException("Telegram API request 'editMessageText' failed: Bad Request: message text is empty"),
    );

    expect(fn () => (new ThinkingIndicator)->replace($telegram, 123456789, 42, '<b>ok</b>', 'HTML'))
        ->toThrow(TelegramApiException::class, 'message text is empty');
});

test('dismiss deletes the placeholder message without throwing when deletion fails', function () {
    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('deleteMessage')->once()->with(123456789, 42)->andThrow(TelegramApiException::class);

    (new ThinkingIndicator)->dismiss($telegram, 123456789, 42);
});
