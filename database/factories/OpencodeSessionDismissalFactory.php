<?php

namespace Database\Factories;

use App\Models\OpencodeSessionDismissal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpencodeSessionDismissal>
 */
class OpencodeSessionDismissalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => 'ses_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'dismissed_at' => now(),
        ];
    }
}
