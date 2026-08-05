<?php

namespace App\Models;

use App\Enums\OpencodeConfirmationMode;
use App\Enums\OpencodeWorkflowStatus;
use App\Enums\OpencodeWorkflowTemplate;
use Database\Factories\OpencodeWorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $chat_id
 * @property string $project_path
 * @property string|null $opencode_session_id
 * @property OpencodeWorkflowTemplate $template
 * @property OpencodeWorkflowStatus $status
 * @property OpencodeConfirmationMode|null $confirmation_mode
 * @property string|null $current_step
 * @property string|null $last_summary
 * @property int $failure_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property User|null $user
 * @property Collection<int, OpencodeWorkflowStep> $steps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'chat_id', 'project_path', 'opencode_session_id',
    'template', 'status', 'confirmation_mode', 'current_step',
    'last_summary', 'failure_count', 'started_at', 'completed_at',
])]
class OpencodeWorkflow extends Model
{
    /** @use HasFactory<OpencodeWorkflowFactory> */
    use HasFactory;

    /**
     * The ordered steps executed by this workflow.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(OpencodeWorkflowStep::class);
    }

    /**
     * The owner user who requested this workflow.
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
            'template' => OpencodeWorkflowTemplate::class,
            'status' => OpencodeWorkflowStatus::class,
            'confirmation_mode' => OpencodeConfirmationMode::class,
            'failure_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
