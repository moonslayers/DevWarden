<?php

namespace Database\Factories;

use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotSetting>
 */
class BotSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'system_prompt' => 'You are a concise development assistant for the project owner.',
            'max_history_messages' => 50,
            'owner_user_id' => User::factory(),
        ];
    }
}
