<?php

namespace App\Models;

use Database\Factories\TelegramSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $bot_token
 * @property array<int, int>|null $allowed_user_ids
 * @property bool $polling_enabled
 * @property int|null $last_update_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['bot_token', 'allowed_user_ids', 'polling_enabled', 'last_update_id'])]
class TelegramSetting extends Model
{
    /** @use HasFactory<TelegramSettingFactory> */
    use HasFactory;

    /**
     * Get the single settings row, creating it on first access.
     */
    public static function singleton(): static
    {
        return static::query()->firstOrCreate(['id' => 1])->refresh();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'allowed_user_ids' => 'array',
            'polling_enabled' => 'boolean',
            'last_update_id' => 'integer',
        ];
    }
}
