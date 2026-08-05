<?php

namespace App\Models;

use Database\Factories\TelegramPendingMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int $message_id
 * @property string $text
 * @property string|null $photo_file_id
 * @property int $update_id
 * @property bool $is_edit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['chat_id', 'message_id', 'text', 'photo_file_id', 'update_id', 'is_edit'])]
class TelegramPendingMessage extends Model
{
    /** @use HasFactory<TelegramPendingMessageFactory> */
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
            'message_id' => 'integer',
            'update_id' => 'integer',
            'is_edit' => 'boolean',
        ];
    }
}
