<?php

namespace Database\Factories;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiProviderType::OpenAI,
            'is_enabled' => true,
            'api_key' => fake()->regexify('[A-Za-z0-9_-]{32,48}'),
            'base_url' => null,
            'model_text' => null,
            'failover_order' => 0,
        ];
    }

    /**
     * Set the provider type.
     */
    public function forType(AiProviderType $type): static
    {
        return $this->state(fn (): array => [
            'provider' => $type,
        ]);
    }
}
