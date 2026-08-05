<?php

namespace App\Models;

use Database\Factories\TelegramChatBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $chat_id
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $processing_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['chat_id', 'scheduled_at', 'processing_at'])]
class TelegramChatBatch extends Model
{
    /** @use HasFactory<TelegramChatBatchFactory> */
    use HasFactory;

    /**
     * The primary key is the Telegram chat id and is not auto-incrementing.
     *
     * @var string
     */
    protected $primaryKey = 'chat_id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'scheduled_at' => 'datetime',
            'processing_at' => 'datetime',
        ];
    }
}
