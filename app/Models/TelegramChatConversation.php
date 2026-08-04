<?php

namespace App\Models;

use Database\Factories\TelegramChatConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int $user_id
 * @property string|null $conversation_id
 * @property User $user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['chat_id', 'user_id', 'conversation_id'])]
class TelegramChatConversation extends Model
{
    /** @use HasFactory<TelegramChatConversationFactory> */
    use HasFactory;

    /**
     * The owner user the conversation belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
