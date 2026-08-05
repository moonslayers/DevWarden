<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sub_agent_id
 * @property int|null $chat_id
 * @property string $kind
 * @property int|null $tokens
 * @property BotSubAgent $subAgent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sub_agent_id', 'chat_id', 'kind', 'tokens'])]
class SubAgentUsageLog extends Model
{
    /**
     * Scope to usage logs of a single kind.
     *
     * @param  Builder<SubAgentUsageLog>  $query
     * @return Builder<SubAgentUsageLog>
     */
    public function scopeByKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * The sub-agent that recorded this usage log.
     */
    public function subAgent(): BelongsTo
    {
        return $this->belongsTo(BotSubAgent::class);
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
            'tokens' => 'integer',
        ];
    }
}
