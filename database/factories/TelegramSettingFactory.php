<?php

namespace Database\Factories;

use App\Models\TelegramSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramSetting>
 */
class TelegramSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_token' => fake()->regexify('[0-9]{9,10}:[A-Za-z0-9_-]{35}'),
            'allowed_user_ids' => [fake()->numberBetween(100000000, 999999999)],
            'polling_enabled' => true,
            'last_update_id' => null,
        ];
    }
}
