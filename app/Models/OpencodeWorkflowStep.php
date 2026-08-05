<?php

namespace App\Models;

use App\Enums\OpencodeWorkflowStatus;
use Database\Factories\OpencodeWorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $opencode_workflow_id
 * @property string $step_name
 * @property string $command
 * @property OpencodeWorkflowStatus $status
 * @property string|null $summary
 * @property string|null $raw_output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property OpencodeWorkflow $workflow
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'opencode_workflow_id', 'step_name', 'command', 'status',
    'summary', 'raw_output', 'started_at', 'finished_at',
])]
class OpencodeWorkflowStep extends Model
{
    /**
     * Max characters kept for raw_output before truncation.
     */
    public const MAX_RAW_OUTPUT_LENGTH = 20000;

    /** @use HasFactory<OpencodeWorkflowStepFactory> */
    use HasFactory;

    /**
     * The workflow this step belongs to.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(OpencodeWorkflow::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OpencodeWorkflowStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
