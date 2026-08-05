<?php

namespace Database\Factories;

use App\Models\OpencodeSessionWatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpencodeSessionWatch>
 */
class OpencodeSessionWatchFactory extends Factory
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
            'project_path' => '/home/junior/Projects/DevWarden',
            'title' => fake()->sentence(4),
            'chat_id' => fake()->numberBetween(100000000, 999999999),
            'last_seen_status' => 'running',
            'last_notified_event' => null,
            'checked_at' => now(),
            'notified_at' => null,
        ];
    }
}
