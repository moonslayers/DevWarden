<?php

namespace App\Models;

use Database\Factories\OpencodeSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $root_projects_path
 * @property string $mcp_command
 * @property string|null $data_db_path
 * @property Carbon|null $session_watch_since
 * @property Carbon|null $session_watch_boot_reported_at
 * @property Carbon|null $schedule_booted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['root_projects_path', 'mcp_command', 'data_db_path', 'session_watch_since', 'session_watch_boot_reported_at', 'schedule_booted_at'])]
class OpencodeSetting extends Model
{
    /** @use HasFactory<OpencodeSettingFactory> */
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
            'session_watch_since' => 'datetime',
            'session_watch_boot_reported_at' => 'datetime',
            'schedule_booted_at' => 'datetime',
        ];
    }
}
