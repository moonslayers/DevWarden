<?php

namespace App\Models;

use Database\Factories\BotMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property string|null $source_message_id
 * @property string $content
 * @property string|null $summary
 * @property string|null $category
 * @property int $importance
 * @property int $access_count
 * @property Carbon|null $last_accessed_at
 * @property string|null $embedding
 * @property string|null $embedding_model
 * @property int|null $embedding_dim
 * @property float $score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'chat_id', 'source_message_id', 'content', 'summary', 'category',
    'importance', 'access_count', 'last_accessed_at', 'embedding',
    'embedding_model', 'embedding_dim',
])]
class BotMemory extends Model
{
    /** @use HasFactory<BotMemoryFactory> */
    use HasFactory;

    /**
     * The canonical embedding model identifier stored alongside each memory vector.
     *
     * Every embedder (storage and retrieval) and the repository filters reference
     * this single value so persisted rows always carry the same model identifier.
     */
    public const EMBEDDING_MODEL = 'Xenova/nomic-embed-text-v1';

    /**
     * The default embedding dimensionality used by the local model.
     */
    public const EMBEDDING_DIM = 768;

    /**
     * Scope to memories belonging to a single Telegram chat.
     *
     * @param  Builder<BotMemory>  $query
     * @return Builder<BotMemory>
     */
    public function scopeForChat(Builder $query, int $chatId): Builder
    {
        return $query->where('chat_id', $chatId);
    }

    /**
     * Scope to memories captured from a single source Telegram message.
     *
     * @param  Builder<BotMemory>  $query
     * @return Builder<BotMemory>
     */
    public function scopeForSourceMessage(Builder $query, string $sourceMessageId): Builder
    {
        return $query->where('source_message_id', $sourceMessageId);
    }

    /**
     * Decode the stored float32 blob into a plain float array.
     *
     * @return array<int, float>|null
     */
    public function getEmbeddingVector(): ?array
    {
        $blob = $this->attributes['embedding'] ?? null;

        if ($blob === null || $blob === '') {
            return null;
        }

        $values = unpack('f*', $blob);

        return $values === false ? null : array_values($values);
    }

    /**
     * Encode a float array into the float32 blob persisted on the model.
     *
     * @param  array<int, float>  $vector
     */
    public function setEmbeddingVector(array $vector): void
    {
        $this->attributes['embedding'] = pack('f*', ...$vector);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'importance' => 'integer',
            'access_count' => 'integer',
            'embedding_dim' => 'integer',
            'last_accessed_at' => 'datetime',
        ];
    }
}
