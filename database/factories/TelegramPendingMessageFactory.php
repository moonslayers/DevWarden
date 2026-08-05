<?php

namespace Database\Factories;

use App\Models\TelegramPendingMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramPendingMessage>
 */
class TelegramPendingMessageFactory extends Factory
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
            'message_id' => fake()->unique()->numberBetween(1, 999999999),
            'text' => fake()->sentence(),
            'update_id' => fake()->unique()->numberBetween(1, 999999999),
            'is_edit' => false,
            'photo_file_id' => null,
        ];
    }

    /**
     * Give the pending message an incoming Telegram photo.
     */
    public function withPhoto(?string $fileId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'photo_file_id' => $fileId ?? fake()->uuid(),
        ]);
    }
}
