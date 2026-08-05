<?php

namespace Database\Factories;

use App\Enums\BotMemoryCategory;
use App\Models\BotMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotMemory>
 */
class BotMemoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => fake()->numberBetween(100000000, 999999999),
            'source_message_id' => (string) fake()->uuid(),
            'content' => fake()->sentence(),
            'summary' => fake()->sentence(),
            'category' => fake()->randomElement(BotMemoryCategory::cases())->value,
            'importance' => fake()->numberBetween(1, 10),
            'access_count' => 0,
            'last_accessed_at' => null,
            'embedding' => null,
            'embedding_model' => BotMemory::EMBEDDING_MODEL,
            'embedding_dim' => BotMemory::EMBEDDING_DIM,
        ];
    }
}
