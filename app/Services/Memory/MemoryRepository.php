<?php

namespace App\Services\Memory;

use App\Models\BotMemory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MemoryRepository
{
    /**
     * Create a memory for a chat, persisting its embedding vector.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, float>  $embedding
     */
    public function create(int $chatId, array $attributes, array $embedding): BotMemory
    {
        $memory = new BotMemory([
            ...$attributes,
            'chat_id' => $chatId,
            'embedding_model' => BotMemory::EMBEDDING_MODEL,
            'embedding_dim' => count($embedding),
        ]);

        $memory->setEmbeddingVector($embedding);
        $memory->save();

        return $memory->refresh();
    }

    /**
     * Find the most similar memories for a chat by cosine similarity.
     *
     * @param  array<int, float>  $queryEmbedding
     * @return Collection<int, BotMemory>
     */
    public function search(int $chatId, array $queryEmbedding, int $topK = 5, float $threshold = 0.7): Collection
    {
        $query = BotMemory::query()
            ->forChat($chatId)
            ->where('embedding_model', BotMemory::EMBEDDING_MODEL)
            ->where('embedding_dim', count($queryEmbedding));

        $scored = $query->get()
            ->map(function (BotMemory $memory) use ($queryEmbedding): BotMemory {
                $vector = $memory->getEmbeddingVector();
                $score = $vector === null ? 0.0 : $this->cosineSimilarity($queryEmbedding, $vector);
                $memory->score = $score;

                return $memory;
            })
            ->filter(fn (BotMemory $memory): bool => $memory->score >= $threshold)
            ->sortByDesc(fn (BotMemory $memory): float => $memory->score);

        return $scored->take($topK)->values();
    }

    /**
     * Determine whether a memory already exists for the given source message.
     */
    public function existsForSource(int $chatId, string $sourceMessageId): bool
    {
        return BotMemory::query()
            ->forChat($chatId)
            ->forSourceMessage($sourceMessageId)
            ->exists();
    }

    /**
     * Find the single most similar memory above a dedup threshold.
     *
     * @param  array<int, float>  $embedding
     */
    public function findSimilar(int $chatId, array $embedding, float $threshold = 0.92): ?BotMemory
    {
        return $this->search($chatId, $embedding, 1, $threshold)->first();
    }

    /**
     * Record access to a memory, bumping its count and last-access timestamp.
     */
    public function recordAccess(BotMemory|int $memory): void
    {
        $memory = $memory instanceof BotMemory ? $memory : BotMemory::findOrFail($memory);

        $memory->increment('access_count', 1, ['last_accessed_at' => Carbon::now()]);
    }

    /**
     * Delete the oldest memories of a chat beyond the given retention limit.
     *
     * Returns the number of memories deleted.
     */
    public function pruneToLimit(int $chatId, int $limit = 50): int
    {
        $excess = BotMemory::forChat($chatId)->count() - $limit;

        if ($excess <= 0) {
            return 0;
        }

        $excessIds = BotMemory::forChat($chatId)
            ->orderBy('created_at', 'asc')
            ->limit($excess)
            ->pluck('id');

        return BotMemory::whereIn('id', $excessIds)->delete();
    }

    /**
     * Compute the cosine similarity between two equal-length vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $valueA) {
            $valueB = $b[$i] ?? 0.0;
            $dot += $valueA * $valueB;
            $normA += $valueA ** 2;
            $normB += $valueB ** 2;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
