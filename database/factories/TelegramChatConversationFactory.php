<?php

namespace Database\Factories;

use App\Models\TelegramChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramChatConversation>
 */
class TelegramChatConversationFactory extends Factory
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
            'user_id' => User::factory(),
            'conversation_id' => (string) Str::uuid(),
        ];
    }
}
