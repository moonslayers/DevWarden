<?php

namespace App\Models;

use Database\Factories\OpencodeSessionWatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $session_id
 * @property string|null $project_path
 * @property string|null $title
 * @property int|null $chat_id
 * @property string|null $last_seen_status
 * @property string|null $last_notified_event
 * @property Carbon|null $checked_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'session_id', 'project_path', 'title', 'chat_id',
    'last_seen_status', 'last_notified_event', 'checked_at', 'notified_at',
])]
class OpencodeSessionWatch extends Model
{
    /** @use HasFactory<OpencodeSessionWatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'checked_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
