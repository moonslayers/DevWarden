<?php

namespace App\Models;

use Database\Factories\BotSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $system_prompt
 * @property int $max_history_messages
 * @property int|null $owner_user_id
 * @property User|null $owner
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['system_prompt', 'max_history_messages', 'owner_user_id'])]
class BotSetting extends Model
{
    /** @use HasFactory<BotSettingFactory> */
    use HasFactory;

    /**
     * Get the single settings row, creating it on first access.
     */
    public static function singleton(): static
    {
        return static::query()->firstOrCreate(['id' => 1])->refresh();
    }

    /**
     * The owner user the bot answers for.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_history_messages' => 'integer',
        ];
    }
}
