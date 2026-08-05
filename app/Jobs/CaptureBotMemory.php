<?php

namespace App\Jobs;

use App\Ai\Agents\MemoryExtractionAgent;
use App\Services\AiConfigSyncer;
use App\Services\Embedding\EmbeddingException;
use App\Services\Embedding\EmbeddingService;
use App\Services\Memory\MemoryRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort capture of durable memories from a Telegram exchange.
 *
 * Runs after the reply is handed to SendTelegramReply. The AI extraction and the
 * local embedding model are both fallible, so every failure inside that section is
 * caught and logged as a warning and the job returns successfully — memory capture
 * must never fail the queue chain or affect the bot reply.
 */
class CaptureBotMemory implements ShouldQueue
{
    use Queueable;

    /**
     * Retention cap per chat, mirroring opencode-mem's maxMemories: 50.
     */
    private const MAX_MEMORIES = 50;

    /**
     * Attempts per job. Extraction/embedding failures are swallowed by handle(), so
     * retries only cover a transient failure of the capture pipeline itself.
     */
    public int $tries = 3;

    /**
     * Exponential backoff between retries, in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 60];

    /**
     * @param  string  $sourceMessageId  The Telegram update_id of the source exchange,
     *                                   used as the idempotency key so at-least-once
     *                                   redelivery never re-runs extraction or
     *                                   duplicates memories.
     */
    public function __construct(
        public int $chatId,
        public string $userText,
        public string $reply,
        public string $sourceMessageId,
    ) {
        //
    }

    /**
     * Extract, embed and store any durable memories for the exchange.
     *
     * AI/embedding dependencies are resolved in handle() type-hints so the job stays
     * serializable for the queue. When the source message was already captured, the
     * capture is skipped entirely so redelivery never re-runs the AI extraction.
     */
    public function handle(AiConfigSyncer $syncer, EmbeddingService $embeddings, MemoryRepository $memories): void
    {
        $syncer->sync();

        try {
            if ($memories->existsForSource($this->chatId, $this->sourceMessageId)) {
                Log::info('Bot memory capture skipped: source message already captured.', [
                    'chat_id' => $this->chatId,
                    'source_message_id' => $this->sourceMessageId,
                ]);

                return;
            }

            $this->capture($syncer, $embeddings, $memories);
        } catch (Throwable $e) {
            Log::warning('Bot memory capture failed and was skipped (best-effort).', [
                'chat_id' => $this->chatId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The capture pipeline: extract, dedup, persist and prune.
     *
     * Stored summaries embed with the "search_document:" task prefix so retrieval
     * queries (prefixed "search_query:") line up under the nomic model card.
     */
    private function capture(AiConfigSyncer $syncer, EmbeddingService $embeddings, MemoryRepository $memories): void
    {
        $agent = app(MemoryExtractionAgent::class);
        $transcript = $this->transcript();

        foreach ($agent->extract($transcript, $syncer->chain()) as $memory) {
            $vector = $this->flatVector($embeddings->embed(EmbeddingService::DOCUMENT_PREFIX.$memory['summary']));

            if ($memories->findSimilar($this->chatId, $vector) !== null) {
                continue;
            }

            // The full transcript is stored as `content` on every memory of the
            // same exchange (up to 3x duplication). Acceptable for v1: it keeps
            // each memory self-contained for traceability; revisit if storage grows.
            $memories->create($this->chatId, [
                'content' => $transcript,
                'summary' => $memory['summary'],
                'category' => $memory['category'],
                'importance' => $memory['importance'],
                'source_message_id' => $this->sourceMessageId,
            ], $vector);
        }

        $memories->pruneToLimit($this->chatId, self::MAX_MEMORIES);
    }

    /**
     * Build the short transcript fed to the extraction agent.
     */
    private function transcript(): string
    {
        return "Usuario: {$this->userText}\nAsistente: {$this->reply}";
    }

    /**
     * Narrow a single-text embedding output to a flat vector.
     *
     * @param  list<float>|list<list<float>>  $vector
     * @return list<float>
     *
     * @throws EmbeddingException When the embedding engine returned a batch.
     */
    private function flatVector(array $vector): array
    {
        foreach ($vector as $component) {
            if (is_array($component)) {
                throw new EmbeddingException('Expected a flat embedding vector for a single text.');
            }
        }

        return $vector;
    }
}
