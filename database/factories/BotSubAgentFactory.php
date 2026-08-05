<?php

namespace Database\Factories;

use App\Enums\BotSubAgentType;
use App\Models\BotSubAgent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BotSubAgent>
 */
class BotSubAgentFactory extends Factory
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
            'type' => BotSubAgentType::General,
            'description' => fake()->sentence(),
            'system_prompt' => null,
            'ai_provider_id' => null,
            'model' => null,
            'is_active' => false,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Configure the model as the immutable system vision sub-agent.
     */
    public function systemVision(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Vision',
            'slug' => 'vision',
            'type' => BotSubAgentType::Vision,
            'is_active' => false,
            'is_system' => true,
            'ai_provider_id' => null,
            'model' => null,
            'sort_order' => 0,
        ]);
    }
}
