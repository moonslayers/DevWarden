<?php

namespace App\Models;

use App\Enums\BotSubAgentType;
use Database\Factories\BotSubAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property BotSubAgentType $type
 * @property string|null $description
 * @property string|null $system_prompt
 * @property int|null $ai_provider_id
 * @property string|null $model
 * @property bool $is_active
 * @property bool $is_system
 * @property int $sort_order
 * @property AiProvider|null $aiProvider
 * @property Collection<int, SubAgentUsageLog> $usageLogs
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'type', 'description', 'system_prompt',
    'ai_provider_id', 'model', 'is_active', 'is_system', 'sort_order',
])]
class BotSubAgent extends Model
{
    /** @use HasFactory<BotSubAgentFactory> */
    use HasFactory;

    /**
     * Filter to sub-agents that are enabled.
     *
     * @param  Builder<BotSubAgent>  $query
     * @return Builder<BotSubAgent>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Order sub-agents by their display order.
     *
     * @param  Builder<BotSubAgent>  $query
     * @return Builder<BotSubAgent>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Filter to sub-agents of the vision type.
     *
     * @param  Builder<BotSubAgent>  $query
     * @return Builder<BotSubAgent>
     */
    public function scopeVision(Builder $query): Builder
    {
        return $query->where('type', BotSubAgentType::Vision);
    }

    /**
     * The AI provider backing this sub-agent, or null when it uses the main provider.
     */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * The usage logs recorded for this sub-agent.
     */
    public function usageLogs(): HasMany
    {
        return $this->hasMany(SubAgentUsageLog::class, 'sub_agent_id');
    }

    /**
     * Whether this sub-agent falls back to the system's main AI provider.
     */
    public function usesSystemProvider(): bool
    {
        return $this->ai_provider_id === null;
    }

    /**
     * Get the first active vision sub-agent usable with its configured provider.
     *
     * Vision sub-agents that reference a disabled provider are skipped.
     */
    public static function activeVision(): ?self
    {
        return static::query()
            ->active()
            ->vision()
            ->ordered()
            ->where(function (Builder $query): void {
                $query->whereNull('ai_provider_id')
                    ->orWhereHas('aiProvider', fn (Builder $provider) => $provider->where('is_enabled', true));
            })
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BotSubAgentType::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
