<?php

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Services\AiConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->syncer = app(AiConfigSyncer::class);
});

test('sync writes provider config from the database for every enabled provider', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['api_key' => 'sk-openai', 'model_text' => 'gpt-4o-mini']);
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['api_key' => 'sk-anthropic']);
    AiProvider::factory()->forType(AiProviderType::DeepSeek)->create(['api_key' => 'sk-deepseek', 'failover_order' => 1]);
    AiProvider::factory()->forType(AiProviderType::OpenAiCompatible)->create([
        'api_key' => 'sk-local',
        'base_url' => 'https://llm.local/v1',
        'model_text' => 'local-model',
        'failover_order' => 2,
    ]);

    $this->syncer->sync();

    expect(config('ai.providers.openai'))->toMatchArray([
        'driver' => 'openai',
        'key' => 'sk-openai',
        'models' => ['text' => ['default' => 'gpt-4o-mini']],
    ]);

    expect(config('ai.providers.anthropic'))->toMatchArray([
        'driver' => 'anthropic',
        'key' => 'sk-anthropic',
    ])->not->toHaveKey('models');

    expect(config('ai.providers.deepseek'))->toMatchArray([
        'driver' => 'deepseek',
        'key' => 'sk-deepseek',
    ]);

    expect(config('ai.providers.openai-compatible'))->toMatchArray([
        'driver' => 'openai-compatible',
        'key' => 'sk-local',
        'url' => 'https://llm.local/v1',
        'models' => ['text' => ['default' => 'local-model']],
    ]);
});

test('sync omits the model override when no model is configured', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['api_key' => 'sk-openai', 'model_text' => null]);

    $this->syncer->sync();

    expect(config('ai.providers.openai'))->toMatchArray([
        'driver' => 'openai',
        'key' => 'sk-openai',
    ])->not->toHaveKey('models');
});

test('sync clears stale config for providers that are no longer enabled', function () {
    config(['ai.providers.openai' => ['driver' => 'openai', 'key' => 'sk-stale']]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-disabled',
        'is_enabled' => false,
    ]);

    $this->syncer->sync();

    expect(config('ai.providers.openai'))->toBeNull();
});

test('sync forgets cached instances for disabled providers so stale credentials are not reused', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['api_key' => 'sk-v1']);

    $this->syncer->sync();

    $first = app(AiManager::class)->textProvider('openai');
    expect($first->providerCredentials())->toBe(['key' => 'sk-v1']);

    $provider->update(['is_enabled' => false]);
    $this->syncer->sync();

    expect(config('ai.providers.openai'))->toBeNull();

    $second = app(AiManager::class)->textProvider('openai');

    expect($second)->not->toBe($first);
});

test('sync resets the default provider to null when no providers are enabled', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => false]);

    $this->syncer->sync();

    expect(config('ai.default'))->toBeNull();
});

test('sync disables automatic conversation title generation', function () {
    config(['ai.conversations.generate_title' => true]);

    $this->syncer->sync();

    expect(config('ai.conversations.generate_title'))->toBeFalse();
});

test('sync sets the default provider to the first enabled provider in failover order', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['failover_order' => 1]);
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['failover_order' => 0]);

    $this->syncer->sync();

    expect(config('ai.default'))->toBe('anthropic');
});

test('chain returns enabled providers ordered by failover order', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['failover_order' => 2]);
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['failover_order' => 0]);
    AiProvider::factory()->forType(AiProviderType::DeepSeek)->create(['failover_order' => 1, 'is_enabled' => false]);

    expect($this->syncer->chain())->toBe(['anthropic', 'openai']);
});

test('chain returns an empty array when no providers are enabled', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => false]);

    expect($this->syncer->chain())->toBe([]);
});

test('a second sync after a credential change re-resolves the provider with the new config', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['api_key' => 'sk-v1']);

    $this->syncer->sync();

    $first = app(AiManager::class)->textProvider('openai');
    expect($first->providerCredentials())->toBe(['key' => 'sk-v1']);

    $provider->update(['api_key' => 'sk-v2']);
    $this->syncer->sync();

    $second = app(AiManager::class)->textProvider('openai');

    expect($second)->not->toBe($first);
    expect($second->providerCredentials())->toBe(['key' => 'sk-v2']);
});

test('testConnection returns true when the provider responds', function () {
    AnonymousAgent::fake(['pong']);

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    expect($this->syncer->testConnection($provider))->toBeTrue();

    AnonymousAgent::assertPrompted('Ping.');
});

test('testConnection returns false when the provider call fails', function () {
    AnonymousAgent::fake(fn (): never => throw new RuntimeException('Network error'));

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    expect($this->syncer->testConnection($provider))->toBeFalse();
});

test('testConnection returns false without calling the SDK when openai-compatible has no model', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::OpenAiCompatible)->create(['model_text' => null]);

    expect($this->syncer->testConnection($provider))->toBeFalse();

    AnonymousAgent::assertNeverPrompted();
});

test('testConnection still works for a disabled provider by syncing it directly', function () {
    AnonymousAgent::fake(['pong']);

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => false]);

    expect($this->syncer->testConnection($provider))->toBeTrue();
});
