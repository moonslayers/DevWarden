<?php

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the ai providers settings routes', function () {
    $this->get(route('providers.index'))->assertRedirect(route('login'));
    $this->post(route('providers.store'))->assertRedirect(route('login'));

    $provider = AiProvider::factory()->create();

    $this->patch(route('providers.update', $provider))->assertRedirect(route('login'));
    $this->delete(route('providers.destroy', $provider))->assertRedirect(route('login'));
    $this->post(route('providers.test', $provider))->assertRedirect(route('login'));
});

test('ai providers page lists providers without leaking api keys', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-secret-key',
        'model_text' => 'gpt-4o-mini',
        'failover_order' => 0,
    ]);

    $disabled = AiProvider::factory()->forType(AiProviderType::Anthropic)->create([
        'is_enabled' => false,
        'failover_order' => 1,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('providers.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Providers')
            ->where('providers.0.id', $provider->id)
            ->where('providers.0.provider', 'openai')
            ->where('providers.0.is_enabled', true)
            ->where('providers.0.has_api_key', true)
            ->where('providers.0.model_text', 'gpt-4o-mini')
            ->where('providers.0.failover_order', 0)
            ->where('providers.1.id', $disabled->id)
            ->where('providers.1.is_enabled', false)
            ->where('provider_types.0.value', 'openai')
            ->where('provider_types.0.label', 'OpenAI')
            ->where('provider_types.3.value', 'openai-compatible')
            ->missing('providers.0.api_key'),
        );

    expect($response->getContent())->not->toContain('sk-secret-key');
});

test('a provider can be created with a default failover order', function () {
    $user = User::factory()->create();

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['failover_order' => 2]);

    $response = $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.store'), [
            'provider' => 'anthropic',
            'api_key' => 'sk-anthropic',
            'model_text' => 'claude-sonnet-4-5',
            'is_enabled' => '1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index'));

    $provider = AiProvider::where('provider', 'anthropic')->firstOrFail();

    expect($provider->api_key)->toBe('sk-anthropic');
    expect($provider->model_text)->toBe('claude-sonnet-4-5');
    expect($provider->is_enabled)->toBeTrue();
    expect($provider->failover_order)->toBe(3);
});

test('an openai-compatible provider requires a base url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.store'), [
            'provider' => 'openai-compatible',
            'api_key' => 'sk-local',
        ])
        ->assertSessionHasErrors(['base_url']);

    $response = $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.store'), [
            'provider' => 'openai-compatible',
            'api_key' => 'sk-local',
            'base_url' => 'https://llm.local/v1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index'));

    expect(AiProvider::where('provider', 'openai-compatible')->exists())->toBeTrue();
});

test('a provider can be updated and a blank api key keeps the existing key', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-existing',
        'model_text' => 'gpt-4o-mini',
        'failover_order' => 0,
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->patch(route('providers.update', $provider), [
            'is_enabled' => '0',
            'api_key' => '',
            'model_text' => 'gpt-4o',
            'failover_order' => '1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index'));

    $provider->refresh();

    expect($provider->api_key)->toBe('sk-existing');
    expect($provider->model_text)->toBe('gpt-4o');
    expect($provider->failover_order)->toBe(1);
    expect($provider->is_enabled)->toBeFalse();
});

test('updating an openai-compatible provider requires a base url', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAiCompatible)->create([
        'base_url' => 'https://llm.local/v1',
        'failover_order' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('providers.index'))
        ->patch(route('providers.update', $provider), [
            'is_enabled' => '1',
            'failover_order' => '0',
        ])
        ->assertSessionHasErrors(['base_url']);
});

test('a provider can be deleted', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    $response = $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->delete(route('providers.destroy', $provider));

    $response->assertRedirect(route('providers.index'));

    expect(AiProvider::find($provider->id))->toBeNull();
});

test('the test endpoint reports a successful connection', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    $this->mock(AiConfigSyncer::class)
        ->shouldReceive('testConnection')
        ->once()
        ->andReturn(true);

    $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.test', $provider))
        ->assertRedirect(route('providers.index'))
        ->assertSessionHas('inertia.flash_data.toast');

    expect(session('inertia.flash_data.toast'))->toMatchArray(['type' => 'success']);
});

test('the test endpoint reports a failed connection', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create();

    $this->mock(AiConfigSyncer::class)
        ->shouldReceive('testConnection')
        ->once()
        ->andReturn(false);

    $this
        ->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.test', $provider))
        ->assertRedirect(route('providers.index'));

    expect(session('inertia.flash_data.toast'))->toMatchArray(['type' => 'error']);
});

test('invalid provider data is rejected with validation errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('providers.index'))
        ->post(route('providers.store'), [
            'provider' => 'unknown-provider',
            'failover_order' => 'abc',
        ])
        ->assertSessionHasErrors(['provider', 'failover_order'])
        ->assertRedirect(route('providers.index'));
});
