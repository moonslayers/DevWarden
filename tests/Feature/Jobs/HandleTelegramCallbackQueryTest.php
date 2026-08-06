<?php

use App\Jobs\HandleTelegramCallbackQuery;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionStore;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function Pest\Laravel\mock;

/**
 * Bind mocks for the whole callback pipeline and run the job's handle().
 *
 * @param  array{callback_query_id: string, chat_id: int, callback_data: string, callback_message_id?: int|null}  $payload
 */
function runCallbackJob(array $payload, object $store, object $manager, object $telegram): void
{
    app()->instance(OpencodeSessionStore::class, $store);
    app()->instance(OpencodeSessionManager::class, $manager);
    app()->instance(TelegramClient::class, $telegram);

    $job = new HandleTelegramCallbackQuery(
        $payload['callback_query_id'],
        $payload['chat_id'],
        $payload['callback_data'],
        $payload['callback_message_id'] ?? null,
    );

    app()->call([$job, 'handle']);
}

test('forwards the server-side option label to the session and answers the callback with success', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([
        ['question' => '¿Continuar?', 'options' => [
            ['label' => 'Sí', 'description' => null],
            ['label' => 'No', 'description' => null],
        ]],
    ]);
    $store->shouldReceive('sessionState')->once()->with('ses_abc')->andReturn(['directory' => '/projects/devwarden']);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('isAllowedProject')->once()->with('/projects/devwarden')->andReturn(true);
    $manager->shouldReceive('reply')->once()->with('ses_abc', '/projects/devwarden', 'Sí');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'Respuesta enviada a la sesión', false);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
        'callback_message_id' => 42,
    ], $store, $manager, $telegram);
});

test('answers with an error alert when the callback data does not match the oq contract', function () {
    $store = mock(OpencodeSessionStore::class);
    $manager = mock(OpencodeSessionManager::class);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'Opción no disponible', true);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'other:ses_abc:0:1',
    ], $store, $manager, $telegram);
});

test('answers with an error alert when the question or option indices do not exist', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([
        ['question' => '¿Continuar?', 'options' => [['label' => 'Sí', 'description' => null]]],
    ]);

    $manager = mock(OpencodeSessionManager::class);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'La pregunta ya no está disponible', true);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:2:5',
    ], $store, $manager, $telegram);
});

test('answers with an error alert when the session has no resolvable directory', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([
        ['question' => '¿Continuar?', 'options' => [['label' => 'Sí', 'description' => null]]],
    ]);
    $store->shouldReceive('sessionState')->once()->with('ses_abc')->andReturn(['directory' => null]);

    $manager = mock(OpencodeSessionManager::class);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'No se pudo resolver la sesión', true);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
    ], $store, $manager, $telegram);
});

test('answers with an error alert when the project directory is not on the whitelist', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([
        ['question' => '¿Continuar?', 'options' => [['label' => 'Sí', 'description' => null]]],
    ]);
    $store->shouldReceive('sessionState')->once()->with('ses_abc')->andReturn(['directory' => '/etc/passwd']);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('isAllowedProject')->once()->with('/etc/passwd')->andReturn(false);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'Proyecto no permitido', true);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
    ], $store, $manager, $telegram);
});

test('logs and answers with an error alert when the opencode reply fails', function () {
    Log::spy();

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([
        ['question' => '¿Continuar?', 'options' => [['label' => 'Sí', 'description' => null]]],
    ]);
    $store->shouldReceive('sessionState')->once()->with('ses_abc')->andReturn(['directory' => '/projects/devwarden']);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('isAllowedProject')->once()->with('/projects/devwarden')->andReturn(true);
    $manager->shouldReceive('reply')->once()->with('ses_abc', '/projects/devwarden', 'Sí')
        ->andThrow(new OpencodeException('opencode-mcp MCP transport error calling [opencode_reply].'));

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'Error al responder a la sesión', true);

    runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
    ], $store, $manager, $telegram);

    Log::shouldHaveReceived('warning')->once();
});

test('catches unexpected failures, logs them and answers with an error alert without rethrowing', function () {
    Log::spy();

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')
        ->andThrow(new RuntimeException('opencode.db unreadable.'));

    $manager = mock(OpencodeSessionManager::class);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'Error al procesar la opción', true);

    expect(fn () => runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
    ], $store, $manager, $telegram))->not->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')->once();
});

test('never lets a failed callback answer fail the job', function () {
    Log::spy();

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('questionOptions')->once()->with('ses_abc')->andReturn([]);

    $manager = mock(OpencodeSessionManager::class);

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1', 'La pregunta ya no está disponible', true)
        ->andThrow(new RuntimeException('Bad Request: query is too old'));

    expect(fn () => runCallbackJob([
        'callback_query_id' => 'cb-1',
        'chat_id' => 123456789,
        'callback_data' => 'oq:ses_abc:0:0',
    ], $store, $manager, $telegram))->not->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')->once();
});
