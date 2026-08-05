<?php

namespace Database\Factories;

use App\Models\TelegramChatBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramChatBatch>
 */
class TelegramChatBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'scheduled_at' => null,
            'processing_at' => null,
        ];
    }
}
