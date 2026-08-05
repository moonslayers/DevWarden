<?php

namespace Database\Factories;

use App\Models\BotSkill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BotSkill>
 */
class BotSkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'description' => fake()->sentence(),
            'content' => fake()->paragraph(),
            'trigger_keywords' => [fake()->word()],
            'active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
