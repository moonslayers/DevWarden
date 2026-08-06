<?php

namespace App\Models;

use Database\Factories\SkillUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $skill_id
 * @property int|null $chat_id
 * @property BotSkill $skill
 * @property Carbon $created_at
 */
#[Fillable(['skill_id', 'chat_id'])]
class SkillUsageLog extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<SkillUsageLogFactory> */
    use HasFactory;

    /**
     * The bot skill that recorded this usage log.
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(BotSkill::class);
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
        ];
    }
}
