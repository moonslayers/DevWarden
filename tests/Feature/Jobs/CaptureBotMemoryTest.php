<?php

use App\Ai\Agents\BotAgent;
use App\Ai\Agents\MemoryExtractionAgent;
use App\Enums\AiProviderType;
use App\Jobs\CaptureBotMemory;
use App\Jobs\ProcessTelegramPendingBatch;
use App\Jobs\SendTelegramReply;
use App\Models\AiProvider;
use App\Models\BotMemory;
use App\Models\BotSetting;
use App\Models\TelegramPendingMessage;
use App\Models\User;
use App\Services\Embedding\EmbeddingException;
use App\Services\Embedding\EmbeddingService;
use App\Services\Memory\MemoryRepository;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

use function Pest\Laravel\mock;

beforeEach(function () {
    $owner = User::factory()->create();

    BotSetting::factory()->create(['id' => 1, 'owner_user_id' => $owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    app()->instance(EmbeddingService::class, new StubEmbeddingService);

    app()->instance(TelegramClient::class, mock(TelegramClient::class));
});

test('captures extracted memories with summary, category and importance', function () {
    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'El usuario prefiere Laravel para sus proyectos.', 'category' => 'user_preference', 'importance' => 8],
            ['summary' => 'Decisión: usar SQLite en DevWarden.', 'category' => 'decision', 'importance' => 6],
        ],
    ]]);

    app()->call([new CaptureBotMemory(123456789, 'Prefiero Laravel', 'Perfecto, Laravel es genial.', '1001'), 'handle']);

    $memories = BotMemory::query()->where('chat_id', 123456789)->orderBy('id')->get();

    expect($memories)->toHaveCount(2)
        ->and($memories[0]->source_message_id)->toBe('1001')
        ->and($memories[0]->summary)->toBe('El usuario prefiere Laravel para sus proyectos.')
        ->and($memories[0]->category)->toBe('user_preference')
        ->and($memories[0]->importance)->toBe(8)
        ->and($memories[0]->embedding_dim)->toBe(16)
        ->and($memories[1]->source_message_id)->toBe('1001')
        ->and($memories[1]->summary)->toBe('Decisión: usar SQLite en DevWarden.')
        ->and($memories[1]->category)->toBe('decision')
        ->and($memories[1]->importance)->toBe(6);
});

test('deduplicates memories that are already stored for the chat', function () {
    MemoryExtractionAgent::fake(function () {
        return [
            'memories' => [
                ['summary' => 'Aprende Laravel cada día.', 'category' => 'fact', 'importance' => 7],
            ],
        ];
    });

    // Two distinct source messages (different update_ids) with the same content:
    // the cosine dedup, not the idempotency guard, must collapse the duplicate.
    app()->call([new CaptureBotMemory(555111, 'Mensaje', 'Respuesta', '1001'), 'handle']);
    app()->call([new CaptureBotMemory(555111, 'Mensaje', 'Respuesta', '1002'), 'handle']);

    expect(BotMemory::query()->where('chat_id', 555111)->count())->toBe(1);
});

test('dedup skips a new memory whose cosine similarity is at 0.93', function () {
    app(MemoryRepository::class)->create(888111, [
        'content' => 'Contenido previo.',
        'summary' => 'Memoria previa.',
        'category' => 'fact',
        'importance' => 5,
    ], [1.0, 0.0, 0.0, 0.0]);

    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'Casi idéntica.', 'category' => 'fact', 'importance' => 5],
        ],
    ]]);

    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            return [0.93, 0.36756, 0.0, 0.0];
        }
    });

    app()->call([new CaptureBotMemory(888111, 'Mensaje', 'Respuesta', '1001'), 'handle']);

    expect(BotMemory::query()->where('chat_id', 888111)->count())->toBe(1);
});

test('dedup inserts a new memory whose cosine similarity is at 0.91', function () {
    app(MemoryRepository::class)->create(888222, [
        'content' => 'Contenido previo.',
        'summary' => 'Memoria previa.',
        'category' => 'fact',
        'importance' => 5,
    ], [1.0, 0.0, 0.0, 0.0]);

    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'Parecida pero distinta.', 'category' => 'fact', 'importance' => 5],
        ],
    ]]);

    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            return [0.91, 0.41461, 0.0, 0.0];
        }
    });

    app()->call([new CaptureBotMemory(888222, 'Mensaje', 'Respuesta', '1001'), 'handle']);

    expect(BotMemory::query()->where('chat_id', 888222)->count())->toBe(2);
});

test('does not re-run extraction when the same source message is redelivered', function () {
    $extractions = 0;

    MemoryExtractionAgent::fake(function () use (&$extractions): array {
        $extractions++;

        return [
            'memories' => [
                ['summary' => 'Prefiere café con leche por la mañana.', 'category' => 'user_preference', 'importance' => 6],
            ],
        ];
    });

    $job = new CaptureBotMemory(444111, 'Me gusta el café', 'Perfecto, lo recordaré.', '101');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect($extractions)->toBe(1);

    $memories = BotMemory::query()->where('chat_id', 444111)->get();

    expect($memories)->toHaveCount(1)
        ->and($memories[0]->source_message_id)->toBe('101');
});

test('a different source message proceeds with a new capture', function () {
    MemoryExtractionAgent::fake([
        ['memories' => [['summary' => 'Prefiere Emacs.', 'category' => 'user_preference', 'importance' => 7]]],
        ['memories' => [['summary' => 'Usa Neovim.', 'category' => 'user_preference', 'importance' => 7]]],
    ]);

    app()->call([new CaptureBotMemory(444222, 'Uso Emacs', 'Anotado.', '201'), 'handle']);
    app()->call([new CaptureBotMemory(444222, 'Uso Neovim', 'Anotado.', '202'), 'handle']);

    $memories = BotMemory::query()->where('chat_id', 444222)->orderBy('id')->get();

    expect($memories)->toHaveCount(2)
        ->and($memories[0]->source_message_id)->toBe('201')
        ->and($memories[1]->source_message_id)->toBe('202');
});

test('prunes the chat memories down to the retention cap of fifty', function () {
    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'Prefiere TypeScript para el frontend.', 'category' => 'user_preference', 'importance' => 5],
            ['summary' => 'Usa PHP 8.5 en el servidor.', 'category' => 'technical_context', 'importance' => 6],
        ],
    ]]);

    for ($i = 0; $i < 51; $i++) {
        BotMemory::factory()->create([
            'chat_id' => 777777,
            'created_at' => now()->subMinutes(120 - $i),
            'updated_at' => now()->subMinutes(120 - $i),
        ]);
    }

    app()->call([new CaptureBotMemory(777777, 'Prefiero TypeScript', 'Perfecto.', '1001'), 'handle']);

    expect(BotMemory::query()->where('chat_id', 777777)->count())->toBe(50)
        ->and(BotMemory::query()->where('chat_id', 777777)->where('summary', 'Prefiere TypeScript para el frontend.')->exists())->toBeTrue()
        ->and(BotMemory::query()->where('chat_id', 777777)->where('summary', 'Usa PHP 8.5 en el servidor.')->exists())->toBeTrue();
});

test('skips the capture without failing the job when the extraction agent fails', function () {
    MemoryExtractionAgent::fake(fn (): never => throw new RuntimeException('AI provider is down.'));

    $log = Log::spy();

    app()->call([new CaptureBotMemory(222222, 'Hola', 'Adiós', '1001'), 'handle']);

    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Bot memory capture failed'));

    expect(BotMemory::query()->count())->toBe(0);
});

test('skips the capture without failing the job when embedding fails', function () {
    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            throw new EmbeddingException('The FFI extension is required to run local embeddings.');
        }
    });

    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'Memoria que no se puede embeber.', 'category' => 'fact', 'importance' => 5],
        ],
    ]]);

    expect(fn () => app()->call([new CaptureBotMemory(333333, 'Hola', 'Adiós', '1001'), 'handle']))
        ->not->toThrow(EmbeddingException::class);

    expect(BotMemory::query()->count())->toBe(0);
});

test('ProcessTelegramPendingBatch dispatches the memory capture after the send reply', function () {
    Queue::fake();

    TelegramPendingMessage::factory()->create([
        'chat_id' => 999999,
        'message_id' => 1,
        'text' => 'Hola bot',
        'update_id' => 101,
    ]);

    BotAgent::fake(['Respuesta del bot.']);

    app()->call([new ProcessTelegramPendingBatch(999999), 'handle']);

    Queue::assertPushed(SendTelegramReply::class, fn ($job): bool => $job->chatId === 999999 && $job->text === 'Respuesta del bot.');

    Queue::assertPushed(CaptureBotMemory::class, fn ($job): bool => $job->chatId === 999999 && $job->userText === '1. Hola bot' && $job->reply === 'Respuesta del bot.' && $job->sourceMessageId === '101');
});

/**
 * Deterministic local double for the embedding contract: maps every summary to a
 * 4-dimensional one-hot vector, so distinct summaries are orthogonal (no false
 * dedup) while repeating a summary yields an identical vector (dedup works).
 */
final class StubEmbeddingService implements EmbeddingService
{
    public function embed(string|array $texts): array
    {
        $embed = function (string $text): array {
            $bytes = md5($text, true);
            $vector = [];

            for ($i = 0; $i < 16; $i++) {
                $vector[] = ord($bytes[$i]) / 255.0;
            }

            return $vector;
        };

        return is_array($texts) ? array_map($embed, $texts) : $embed($texts);
    }
}
