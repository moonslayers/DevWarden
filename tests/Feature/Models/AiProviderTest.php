<?php

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('ai_providers table exists', function () {
    expect(Schema::hasTable('ai_providers'))->toBeTrue();
});

test('provider enum is cast correctly', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::Anthropic)->create();

    expect($provider->provider)->toBe(AiProviderType::Anthropic);
    expect(DB::table('ai_providers')->value('provider'))->toBe('anthropic');
});

test('provider type is unique per row', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    expect(fn () => AiProvider::factory()->forType(AiProviderType::OpenAI)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('api_key is encrypted at rest and round-trips', function () {
    $key = 'sk-'.fake()->regexify('[A-Za-z0-9]{40}');

    $provider = AiProvider::factory()->create(['api_key' => $key]);

    expect($provider->api_key)->toBe($key);
    expect(DB::table('ai_providers')->value('api_key'))->not->toBe($key);
});

test('enabled scope orders by failover order', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['failover_order' => 2]);
    AiProvider::factory()->forType(AiProviderType::DeepSeek)->create(['failover_order' => 0, 'is_enabled' => false]);
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['failover_order' => 1]);

    $enabled = AiProvider::enabledOrdered();

    expect($enabled->pluck('provider')->toArray())->toBe([
        AiProviderType::Anthropic,
        AiProviderType::OpenAI,
    ]);
});

test('factory produces a valid row', function () {
    $provider = AiProvider::factory()->create();

    expect($provider->exists)->toBeTrue();
    expect($provider->provider)->toBe(AiProviderType::OpenAI);
    expect($provider->api_key)->not->toBeNull();
});
