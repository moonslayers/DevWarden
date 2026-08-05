<?php

namespace Database\Factories;

use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpencodeWorkflowStep>
 */
class OpencodeWorkflowStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opencode_workflow_id' => OpencodeWorkflow::factory(),
            'step_name' => 'context-gather',
            'command' => 'context-gather',
            'status' => OpencodeWorkflowStatus::Running,
            'summary' => null,
            'raw_output' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }
}
