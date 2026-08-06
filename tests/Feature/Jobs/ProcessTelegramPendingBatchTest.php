<?php

use App\Ai\Agents\BotAgent;
use App\Enums\AiProviderType;
use App\Jobs\CaptureBotMemory;
use App\Jobs\ProcessTelegramPendingBatch;
use App\Jobs\SendTelegramReply;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramChatBatch;
use App\Models\TelegramPendingMessage;
use App\Models\User;
use App\Services\Embedding\EmbeddingService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramMessageBuffer;
use App\Services\Telegram\ThinkingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['id' => 1, 'owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    $this->buffer = new TelegramMessageBuffer;

    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            return [[1.0, 0.0, 0.0, 0.0]];
        }
    });

    app()->instance(TelegramClient::class, mock(TelegramClient::class));
    app()->instance(ThinkingIndicator::class, mock(ThinkingIndicator::class));
});

test('drains two pending messages in one AI call: one placeholder, one combined reply, one memory capture', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);
    $this->buffer->storeMessage(123456789, 2, 'Segundo', 102);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta combinada']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    $combined = '1. Primero'.PHP_EOL.'2. Segundo';

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === $combined);

    Queue::assertPushed(SendTelegramReply::class, 1);
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Respuesta combinada' && $job->placeholderMessageId === 77,
    );

    Queue::assertPushed(CaptureBotMemory::class, 1);
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->sourceMessageId === '101' && $job->userText === $combined && $job->reply === 'Respuesta combinada',
    );

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->toBeNull();
});

test('absorbs stragglers in a second drain iteration with a fresh placeholder per turn', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->twice()->andReturn(77, 78);
    app()->instance(ThinkingIndicator::class, $indicator);

    $prompted = 0;
    BotAgent::fake(function () use (&$prompted): string {
        $prompted++;

        if ($prompted === 1) {
            TelegramPendingMessage::query()->create([
                'chat_id' => 123456789,
                'message_id' => 2,
                'text' => 'Straggler',
                'update_id' => 102,
            ]);
        }

        return "Respuesta {$prompted}";
    });

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    Queue::assertPushed(SendTelegramReply::class, 2);
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === 'Respuesta 1' && $job->placeholderMessageId === 77,
    );
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === 'Respuesta 2' && $job->placeholderMessageId === 78,
    );

    Queue::assertPushed(CaptureBotMemory::class, 2);

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('dispatches the friendly message with the placeholder id and skips memory capture when the AI generation fails', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(99);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']))
        ->not->toThrow(RuntimeException::class);

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->chatId === 123456789 && $job->text === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE && $job->placeholderMessageId === 99,
    );
    Queue::assertNotPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->reply === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE,
    );

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
});

test('does nothing and releases the processing claim when there are no pending messages', function () {
    Queue::fake();

    BotAgent::fake(['Respuesta']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    BotAgent::assertNeverPrompted();
    Queue::assertNothingPushed();

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->toBeNull();
});

test('skips processing and dispatches nothing when no owner user is configured', function () {
    Queue::fake();

    BotSetting::query()->update(['owner_user_id' => null]);

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);

    BotAgent::fake(['Respuesta']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    BotAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('skips processing and dispatches nothing when the owner user has been deleted', function () {
    Queue::fake();

    $this->owner->delete();

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);

    BotAgent::fake(['Respuesta']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    BotAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('stops draining after MAX_DRAIN_ITERATIONS and re-arms a fresh batch for the leftovers', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Primero', 101);

    $indicator = mock(ThinkingIndicator::class);
    $nextPlaceholder = 77;
    $indicator->shouldReceive('sendPlaceholder')
        ->times(ProcessTelegramPendingBatch::MAX_DRAIN_ITERATIONS)
        ->andReturnUsing(function () use (&$nextPlaceholder): int {
            return $nextPlaceholder++;
        });
    app()->instance(ThinkingIndicator::class, $indicator);

    $nextMessageId = 2;
    $nextUpdateId = 102;

    BotAgent::fake(function () use (&$nextMessageId, &$nextUpdateId): string {
        TelegramPendingMessage::query()->create([
            'chat_id' => 123456789,
            'message_id' => $nextMessageId++,
            'text' => 'Flood',
            'update_id' => $nextUpdateId++,
        ]);

        return 'Respuesta';
    });

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    Queue::assertPushed(SendTelegramReply::class, ProcessTelegramPendingBatch::MAX_DRAIN_ITERATIONS);
    Queue::assertPushed(CaptureBotMemory::class, ProcessTelegramPendingBatch::MAX_DRAIN_ITERATIONS);

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(1);

    Queue::assertPushed(ProcessTelegramPendingBatch::class, 1);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->not->toBeNull();
});

test('reprocesses an edited message that was already answered', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Original', 101);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->twice()->andReturn(77, 78);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta original', 'Respuesta corregida']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Respuesta original',
    );

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);

    $this->buffer->storeMessage(123456789, 1, 'Texto corregido', 202, true);

    $pending = TelegramPendingMessage::query()->where('chat_id', 123456789)->get();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->text)->toBe('Texto corregido');
    expect($pending->first()->is_edit)->toBeTrue();

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Respuesta corregida',
    );
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === '1. Texto corregido',
    );

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

/**
 * Bind a fake TelegramClient whose getFile resolves the given file_id → remote
 * file_path map and whose downloadFile writes bytes to the destination.
 *
 * @param  array<string, string>  $files  file_id => Telegram file_path
 */
function fakeTelegramWithPhotoDownload(array $files): object
{
    $telegram = mock(TelegramClient::class);

    foreach ($files as $fileId => $filePath) {
        $telegram->shouldReceive('getFile')->with($fileId)->andReturn(['file_id' => $fileId, 'file_path' => $filePath]);
    }

    $telegram->shouldReceive('downloadFile')->andReturnUsing(
        fn (string $filePath, string $destination): null => file_put_contents($destination, 'fake-image-bytes') === false
            ? throw new RuntimeException('Unable to write downloaded photo.')
            : null,
    );

    app()->instance(TelegramClient::class, $telegram);

    return $telegram;
}

test('coalesces consecutive text messages into exactly one AI turn', function () {
    Queue::fake();

    $this->buffer->storeMessage(123456789, 1, 'Uno', 101);
    $this->buffer->storeMessage(123456789, 2, 'Dos', 102);
    $this->buffer->storeMessage(123456789, 3, 'Tres', 103);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta única']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->prompt === '1. Uno'.PHP_EOL.'2. Dos'.PHP_EOL.'3. Tres');

    Queue::assertPushed(SendTelegramReply::class, 1);
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === '1. Uno'.PHP_EOL.'2. Dos'.PHP_EOL.'3. Tres' && $job->sourceMessageId === '101',
    );

    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('processes a single photo message as its own AI turn with the downloaded image', function () {
    Queue::fake();
    Storage::fake('local');

    fakeTelegramWithPhotoDownload(['file-id-1' => 'photos/photo1.jpg']);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta de foto']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    $downloadedPath = Storage::disk('local')->path('telegram-media/incoming/123456789-1.jpg');

    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->prompt === 'Mira la foto'
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $downloadedPath,
    );

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->chatId === 123456789 && $job->text === 'Respuesta de foto' && $job->placeholderMessageId === 77,
    );
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === 'Mira la foto' && $job->reply === 'Respuesta de foto' && $job->sourceMessageId === '101',
    );

    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-1.jpg'))->toBeFalse();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('processes a photo message and a following text message as two AI turns', function () {
    Queue::fake();
    Storage::fake('local');

    fakeTelegramWithPhotoDownload(['file-id-1' => 'photos/photo1.png']);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');
    $this->buffer->storeMessage(123456789, 2, 'Y esto otro', 102);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->twice()->andReturn(77, 78);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta de foto', 'Respuesta de texto']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    $downloadedPath = Storage::disk('local')->path('telegram-media/incoming/123456789-1.png');

    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->prompt === 'Mira la foto'
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $downloadedPath,
    );
    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->prompt === '1. Y esto otro' && $prompt->attachments->isEmpty(),
    );

    Queue::assertPushed(SendTelegramReply::class, 2);
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === 'Respuesta de foto' && $job->placeholderMessageId === 77,
    );
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === 'Respuesta de texto' && $job->placeholderMessageId === 78,
    );

    Queue::assertPushed(CaptureBotMemory::class, 2);
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === 'Mira la foto' && $job->sourceMessageId === '101',
    );
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === '1. Y esto otro' && $job->sourceMessageId === '102',
    );

    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-1.png'))->toBeFalse();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('processes multiple photo messages as one AI turn each', function () {
    Queue::fake();
    Storage::fake('local');

    fakeTelegramWithPhotoDownload([
        'file-id-1' => 'photos/one.jpg',
        'file-id-2' => 'photos/two.jpg',
    ]);

    $this->buffer->storeMessage(123456789, 1, 'Foto uno', 101, photoFileId: 'file-id-1');
    $this->buffer->storeMessage(123456789, 2, 'Foto dos', 102, photoFileId: 'file-id-2');

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->twice()->andReturn(77, 78);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta uno', 'Respuesta dos']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    $firstPath = Storage::disk('local')->path('telegram-media/incoming/123456789-1.jpg');
    $secondPath = Storage::disk('local')->path('telegram-media/incoming/123456789-2.jpg');

    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->prompt === 'Foto uno'
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $firstPath,
    );
    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->prompt === 'Foto dos'
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $secondPath,
    );

    Queue::assertPushed(SendTelegramReply::class, 2);
    Queue::assertPushed(CaptureBotMemory::class, 2);

    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-1.jpg'))->toBeFalse();
    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-2.jpg'))->toBeFalse();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('a failed photo download sends the friendly error for that turn and still processes the other turns', function () {
    Queue::fake();
    Storage::fake('local');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('getFile')->with('file-id-1')->andThrow(new RuntimeException('Telegram photo download failed.'));
    app()->instance(TelegramClient::class, $telegram);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');
    $this->buffer->storeMessage(123456789, 2, 'Y esto otro', 102);

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->twice()->andReturn(77, 78);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta de texto']);

    expect(fn () => app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']))
        ->not->toThrow(RuntimeException::class);

    Queue::assertPushed(SendTelegramReply::class, 2);
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE && $job->placeholderMessageId === 77,
    );
    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === 'Respuesta de texto' && $job->placeholderMessageId === 78,
    );

    Queue::assertPushed(CaptureBotMemory::class, 1);
    Queue::assertPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->userText === '1. Y esto otro',
    );

    expect(Storage::disk('local')->allFiles('telegram-media/incoming'))->toBe([]);
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('an AI failure on a photo turn sends the friendly error and still cleans up the downloaded file', function () {
    Queue::fake();
    Storage::fake('local');

    fakeTelegramWithPhotoDownload(['file-id-1' => 'photos/photo1.jpg']);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']))
        ->not->toThrow(RuntimeException::class);

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE && $job->placeholderMessageId === 77,
    );
    Queue::assertNotPushed(
        CaptureBotMemory::class,
        fn ($job): bool => $job->reply === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE,
    );

    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-1.jpg'))->toBeFalse();
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('a partial photo download failure leaves no orphan file behind', function () {
    Queue::fake();
    Storage::fake('local');

    $telegram = mock(TelegramClient::class);
    $telegram->shouldReceive('getFile')->with('file-id-1')->andReturn([
        'file_id' => 'file-id-1',
        'file_path' => 'photos/photo1.jpg',
    ]);
    $telegram->shouldReceive('downloadFile')->andReturnUsing(function (string $filePath, string $destination): never {
        file_put_contents($destination, 'partial-bytes');

        throw new RuntimeException('Connection lost mid-download.');
    });
    app()->instance(TelegramClient::class, $telegram);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta de foto']);

    expect(fn () => app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']))
        ->not->toThrow(RuntimeException::class);

    Queue::assertPushed(
        SendTelegramReply::class,
        fn ($job): bool => $job->text === ProcessTelegramPendingBatch::FRIENDLY_ERROR_MESSAGE && $job->placeholderMessageId === 77,
    );

    expect(Storage::disk('local')->allFiles('telegram-media/incoming'))->toBe([]);
    expect(TelegramPendingMessage::query()->where('chat_id', 123456789)->count())->toBe(0);
});

test('an unexpected photo file extension falls back to jpg', function () {
    Queue::fake();
    Storage::fake('local');

    fakeTelegramWithPhotoDownload(['file-id-1' => 'photos/photo.tiff']);

    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-1');

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')->once()->andReturn(77);
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(['Respuesta de foto']);

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    $downloadedPath = Storage::disk('local')->path('telegram-media/incoming/123456789-1.jpg');

    BotAgent::assertPrompted(
        fn ($prompt): bool => $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $downloadedPath,
    );

    expect(Storage::disk('local')->exists('telegram-media/incoming/123456789-1.jpg'))->toBeFalse();
});
