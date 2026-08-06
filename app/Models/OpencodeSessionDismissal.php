<?php

namespace App\Models;

use Database\Factories\OpencodeSessionDismissalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A session the owner explicitly marked as "done" so the bot stops treating it
 * as active and stops reporting it. Independent from opencode_session_watches,
 * which is rebuilt with the watermark and would lose the mark.
 *
 * @property string $session_id
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['session_id', 'dismissed_at'])]
class OpencodeSessionDismissal extends Model
{
    /** @use HasFactory<OpencodeSessionDismissalFactory> */
    use HasFactory;

    protected $primaryKey = 'session_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }
}
