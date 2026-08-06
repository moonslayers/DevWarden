<?php

namespace Database\Factories;

use App\Models\BotSkill;
use App\Models\SkillUsageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkillUsageLog>
 */
class SkillUsageLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'skill_id' => BotSkill::factory(),
            'chat_id' => fake()->numberBetween(100000000, 999999999),
        ];
    }
}
