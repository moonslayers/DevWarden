<?php

namespace Database\Factories;

use App\Enums\OpencodeConfirmationMode;
use App\Enums\OpencodeWorkflowStatus;
use App\Enums\OpencodeWorkflowTemplate;
use App\Models\OpencodeWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpencodeWorkflow>
 */
class OpencodeWorkflowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_id' => fake()->numberBetween(100000000, 999999999),
            'project_path' => '/home/junior/Projects/DevWarden',
            'opencode_session_id' => null,
            'template' => OpencodeWorkflowTemplate::Default,
            'status' => OpencodeWorkflowStatus::Running,
            'confirmation_mode' => null,
            'current_step' => null,
            'last_summary' => null,
            'failure_count' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Mark the workflow as currently running.
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => OpencodeWorkflowStatus::Running,
            'completed_at' => null,
        ]);
    }

    /**
     * Mark the workflow as waiting for the owner's confirmation.
     */
    public function waitingConfirmation(): static
    {
        return $this->state(fn (): array => [
            'status' => OpencodeWorkflowStatus::WaitingConfirmation,
            'confirmation_mode' => OpencodeConfirmationMode::Answer,
            'completed_at' => null,
        ]);
    }

    /**
     * Mark the workflow as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => OpencodeWorkflowStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
