<?php

namespace App\Services;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Laravel\Ai\AiManager;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Keeps the laravel/ai runtime config in sync with the providers stored in the database.
 *
 * The database is the source of truth for AI provider credentials. This service writes the
 * per-provider config arrays laravel/ai reads at resolve time and clears the cached provider
 * instances held by the AiManager singleton so the next SDK call re-reads the new values.
 */
class AiConfigSyncer
{
    public function __construct(protected AiManager $manager)
    {
        //
    }

    /**
     * Rebuild config('ai.providers') from the enabled providers in the database and clear the
     * cached manager instances for every provider whose config changed or was removed.
     *
     * Providers no longer enabled (or never present) are removed from the repository so a
     * subsequent resolution cannot silently reuse stale credentials. When no provider is
     * enabled, config('ai.default') is reset to null so resolution fails loudly.
     */
    public function sync(): void
    {
        $providers = AiProvider::enabledOrdered();

        $enabledNames = $providers
            ->map(fn (AiProvider $provider): string => $provider->provider->value)
            ->all();

        foreach (array_unique([
            ...array_keys(config('ai.providers', [])),
            ...$enabledNames,
        ]) as $name) {
            $this->manager->forgetInstance($name);
        }

        config([
            'ai.providers' => $providers->mapWithKeys(
                fn (AiProvider $provider): array => [$provider->provider->value => $this->configFor($provider)]
            )->all(),
            'ai.default' => $enabledNames[0] ?? null,
            'ai.conversations.generate_title' => false,
        ]);
    }

    /**
     * Write a single provider into config and forget its cached manager instance.
     */
    private function syncProvider(AiProvider $provider): void
    {
        $name = $provider->provider->value;

        config(["ai.providers.{$name}" => $this->configFor($provider)]);

        $this->manager->forgetInstance($name);
    }

    /**
     * Build the config array laravel/ai expects for a provider.
     *
     * @return array<string, mixed>
     */
    private function configFor(AiProvider $provider): array
    {
        $config = [
            'driver' => $provider->provider->value,
            'key' => $provider->api_key,
        ];

        if ($provider->provider === AiProviderType::OpenAiCompatible && $provider->base_url !== null) {
            $config['url'] = $provider->base_url;
        }

        if ($provider->model_text !== null) {
            $config['models'] = ['text' => ['default' => $provider->model_text]];
        }

        return $config;
    }

    /**
     * Get the names of the enabled providers ordered for the failover chain.
     *
     * @return list<string>
     */
    public function chain(): array
    {
        return AiProvider::enabledOrdered()
            ->map(fn (AiProvider $provider): string => $provider->provider->value)
            ->all();
    }

    /**
     * Attempt a minimal real generation through the SDK to verify the provider credentials.
     *
     * Never throws; returns whether the call completed without error.
     */
    public function testConnection(AiProvider $provider): bool
    {
        $this->syncProvider($provider);

        if ($provider->provider === AiProviderType::OpenAiCompatible && $provider->model_text === null) {
            return false;
        }

        try {
            $response = agent(
                instructions: 'You are a connectivity test assistant. Reply with exactly the word "pong".'
            )->prompt(
                'Ping.',
                provider: $provider->provider->value,
                model: $provider->model_text,
                timeout: 10,
            );

            return $response->text !== '';
        } catch (Throwable) {
            return false;
        }
    }
}
