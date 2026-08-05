<?php

use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Http\Controllers\SubAgentController;
use App\Models\AiProvider;
use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from the sub-agents routes', function () {
    $this->get(route('subagents.index'))->assertRedirect(route('login'));
    $this->post(route('subagents.store'))->assertRedirect(route('login'));

    $subAgent = BotSubAgent::factory()->create();

    $this->patch(route('subagents.update', $subAgent))->assertRedirect(route('login'));
    $this->delete(route('subagents.destroy', $subAgent))->assertRedirect(route('login'));
});

test('a sub-agent can be created and is always a general sub-agent', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('subagents.index'))
        ->post(route('subagents.store'), [
            'name' => 'Research assistant',
            'slug' => 'research-assistant',
            'type' => BotSubAgentType::Vision->value,
            'description' => 'Searches the docs',
            'system_prompt' => 'Be concise.',
            'is_active' => '1',
            'sort_order' => '2',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('subagents.index'));

    $subAgent = BotSubAgent::where('slug', 'research-assistant')->firstOrFail();

    expect($subAgent->name)->toBe('Research assistant');
    expect($subAgent->type)->toBe(BotSubAgentType::General);
    expect($subAgent->is_system)->toBeFalse();
    expect($subAgent->is_active)->toBeTrue();
    expect($subAgent->sort_order)->toBe(2);
});

test('store validation rejects a sub-agent without a name or slug', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->post(route('subagents.store'), [
            'name' => '',
            'slug' => '',
        ])
        ->assertSessionHasErrors(['name', 'slug'])
        ->assertRedirect(route('subagents.index'));
});

test('a general sub-agent can be fully updated', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create();

    $subAgent = BotSubAgent::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
        'description' => 'Old description',
        'sort_order' => 1,
        'is_active' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $subAgent), [
            'name' => 'New Name',
            'slug' => 'new-name',
            'description' => 'Updated description',
            'system_prompt' => 'Updated prompt',
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o-mini',
            'is_active' => '1',
            'sort_order' => '4',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('subagents.index'));

    $subAgent->refresh();

    expect($subAgent->name)->toBe('New Name');
    expect($subAgent->slug)->toBe('new-name');
    expect($subAgent->description)->toBe('Updated description');
    expect($subAgent->system_prompt)->toBe('Updated prompt');
    expect($subAgent->ai_provider_id)->toBe($provider->id);
    expect($subAgent->model)->toBe('gpt-4o-mini');
    expect($subAgent->is_active)->toBeTrue();
    expect($subAgent->sort_order)->toBe(4);
});

test('update keeps its own slug but rejects a slug used by another sub-agent', function () {
    $user = User::factory()->create();
    $subAgent = BotSubAgent::factory()->create(['slug' => 'mine']);

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $subAgent), [
            'name' => $subAgent->name,
            'slug' => 'mine',
        ])
        ->assertSessionHasNoErrors();

    BotSubAgent::factory()->create(['slug' => 'taken']);

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $subAgent), [
            'name' => $subAgent->name,
            'slug' => 'taken',
        ])
        ->assertSessionHasErrors(['slug']);
});

test('updating a system sub-agent only allows provider, model, prompt and active state', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create();

    $vision = BotSubAgent::factory()->systemVision()->create();
    $originalDescription = $vision->description;

    $response = $this
        ->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Hacked Name',
            'slug' => 'hacked-slug',
            'type' => BotSubAgentType::General->value,
            'description' => 'Ignored description',
            'sort_order' => '99',
            'system_prompt' => 'New system prompt',
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'is_active' => '1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('subagents.index'));

    $vision->refresh();

    expect($vision->name)->toBe('Vision');
    expect($vision->slug)->toBe('vision');
    expect($vision->type)->toBe(BotSubAgentType::Vision);
    expect($vision->description)->toBe($originalDescription);
    expect($vision->sort_order)->toBe(0);
    expect($vision->is_system)->toBeTrue();
    expect($vision->system_prompt)->toBe('New system prompt');
    expect($vision->ai_provider_id)->toBe($provider->id);
    expect($vision->model)->toBe('gpt-4o');
    expect($vision->is_active)->toBeTrue();
});

test('updating a system sub-agent without is_active keeps its current active state', function () {
    $user = User::factory()->create();
    $vision = BotSubAgent::factory()->systemVision()->create(['is_active' => true]);

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Vision',
            'slug' => 'vision',
            'system_prompt' => 'New prompt',
        ])
        ->assertSessionHasNoErrors();

    expect($vision->refresh()->is_active)->toBeTrue();
    expect($vision->system_prompt)->toBe('New prompt');
});

test('activating a vision sub-agent requires a provider and model', function () {
    $user = User::factory()->create();
    $vision = BotSubAgent::factory()->systemVision()->create();

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Vision',
            'slug' => 'vision',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors(['ai_provider_id', 'model']);

    $provider = AiProvider::factory()->create();

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Vision',
            'slug' => 'vision',
            'is_active' => '1',
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
        ])
        ->assertSessionHasNoErrors();
});

test('activating a vision sub-agent rejects a disabled provider but accepts an enabled one', function () {
    $user = User::factory()->create();
    $vision = BotSubAgent::factory()->systemVision()->create();
    $disabled = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => false]);

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Vision',
            'slug' => 'vision',
            'is_active' => '1',
            'ai_provider_id' => $disabled->id,
            'model' => 'gpt-4o',
        ])
        ->assertSessionHasErrors(['ai_provider_id']);

    $enabled = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => true]);

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->patch(route('subagents.update', $vision), [
            'name' => 'Vision',
            'slug' => 'vision',
            'is_active' => '1',
            'ai_provider_id' => $enabled->id,
            'model' => 'gpt-4o',
        ])
        ->assertSessionHasNoErrors();
});

test('index providers list omits disabled providers unless referenced by a sub-agent', function () {
    $enabled = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => true, 'failover_order' => 0]);
    $disabled = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => false, 'failover_order' => 1]);
    $referenced = AiProvider::factory()->forType(AiProviderType::DeepSeek)->create(['is_enabled' => false, 'failover_order' => 2]);

    BotSubAgent::factory()->create(['ai_provider_id' => $referenced->id]);

    $props = subAgentsIndexProps();

    expect(collect($props['providers'])->pluck('id')->all())->toBe([$enabled->id, $referenced->id]);
    expect($props['providers'])->toHaveCount(2);
});

test('index flags exactly the first enabled provider in failover order as main', function () {
    $second = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => true, 'failover_order' => 1]);
    $first = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => true, 'failover_order' => 0]);
    $disabled = AiProvider::factory()->forType(AiProviderType::DeepSeek)->create(['is_enabled' => false, 'failover_order' => 2]);

    $props = subAgentsIndexProps();

    expect($props['providers'])->toHaveCount(2);
    expect($props['providers'][0]['id'])->toBe($first->id);
    expect($props['providers'][0]['is_main'])->toBeTrue();
    expect($props['providers'][1]['id'])->toBe($second->id);
    expect($props['providers'][1]['is_main'])->toBeFalse();
    expect(collect($props['providers'])->pluck('id'))->not->toContain($disabled->id);
});

test('a referenced-but-disabled provider appears in the index without is_main', function () {
    $main = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => true, 'failover_order' => 0]);
    $referenced = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => false, 'failover_order' => 1]);

    BotSubAgent::factory()->create(['ai_provider_id' => $referenced->id]);

    $props = subAgentsIndexProps();

    expect(collect($props['providers'])->pluck('id')->all())->toBe([$main->id, $referenced->id]);
    expect($props['providers'][0]['is_main'])->toBeTrue();
    expect($props['providers'][1]['is_main'])->toBeFalse();
});

test('a general sub-agent can be deleted', function () {
    $user = User::factory()->create();
    $subAgent = BotSubAgent::factory()->create();

    $this->actingAs($user)
        ->from(route('subagents.index'))
        ->delete(route('subagents.destroy', $subAgent))
        ->assertRedirect(route('subagents.index'));

    expect(BotSubAgent::find($subAgent->id))->toBeNull();
});

test('the system sub-agent cannot be deleted and returns an error toast', function () {
    $user = User::factory()->create();
    $vision = BotSubAgent::factory()->systemVision()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('subagents.index'))
        ->delete(route('subagents.destroy', $vision));

    $response->assertRedirect(route('subagents.index'));

    expect(BotSubAgent::find($vision->id))->not->toBeNull();
    expect(session('inertia.flash_data.toast.type'))->toBe('error');
});

test('index exposes frontend-safe sub-agent props with usage aggregates and stats', function () {
    $provider = AiProvider::factory()->create([
        'provider' => AiProviderType::OpenAiCompatible,
        'base_url' => 'https://compatible.example/v1',
        'failover_order' => 0,
    ]);

    $general = BotSubAgent::factory()->create([
        'name' => 'Researcher',
        'slug' => 'researcher',
        'type' => BotSubAgentType::General,
        'description' => 'Docs researcher',
        'system_prompt' => 'Be concise.',
        'ai_provider_id' => $provider->id,
        'model' => 'gpt-4o-mini',
        'is_active' => true,
        'is_system' => false,
        'sort_order' => 0,
    ]);

    $vision = BotSubAgent::factory()->systemVision()->create([
        'sort_order' => 1,
        'is_active' => true,
        'ai_provider_id' => $provider->id,
        'model' => 'gpt-4o',
    ]);

    foreach ([
        ['tokens' => 100, 'created_at' => Carbon::today()],
        ['tokens' => 50, 'created_at' => Carbon::today()],
        ['tokens' => 10, 'created_at' => Carbon::today()->subDays(3)],
    ] as $index => $usage) {
        $log = new SubAgentUsageLog([
            'sub_agent_id' => $general->id,
            'chat_id' => 1,
            'kind' => $index === 0 ? 'describe' : 'ask',
            'tokens' => $usage['tokens'],
        ]);
        $log->created_at = $usage['created_at'];
        $log->save();
    }

    $props = subAgentsIndexProps();

    expect($props['subAgents'])->toHaveCount(2);
    expect($props['subAgents'][0]['id'])->toBe($general->id);
    expect($props['subAgents'][0]['name'])->toBe('Researcher');
    expect($props['subAgents'][0]['type'])->toBe('general');
    expect($props['subAgents'][0]['invocations'])->toBe(3);
    expect($props['subAgents'][0]['tokens'])->toBe(160);
    expect($props['subAgents'][0]['ai_provider_id'])->toBe($provider->id);
    expect($props['subAgents'][0]['uses_system_provider'])->toBeFalse();
    expect($props['subAgents'][1]['id'])->toBe($vision->id);
    expect($props['subAgents'][1]['type'])->toBe('vision');
    expect($props['subAgents'][1]['is_system'])->toBeTrue();
    expect($props['subAgents'][1]['uses_system_provider'])->toBeFalse();
    expect($props['subAgents'][1]['invocations'])->toBe(0);
    expect($props['subAgents'][1]['tokens'])->toBe(0);

    expect($props['providers'])->toHaveCount(1);
    expect($props['providers'][0]['id'])->toBe($provider->id);
    expect($props['providers'][0]['label'])->toBe('openai-compatible');
    expect($props['providers'][0]['base_url'])->toBe('https://compatible.example/v1');
    expect($props['providers'][0]['is_main'])->toBeTrue();

    expect($props['types'])->toBe([
        ['value' => 'vision', 'label' => 'Vision'],
        ['value' => 'general', 'label' => 'General'],
    ]);

    expect($props['stats']['total'])->toBe(2);
    expect($props['stats']['active'])->toBe(2);
    expect($props['stats']['visionActive'])->toBeTrue();
    expect($props['stats']['generalCount'])->toBe(1);
    expect($props['stats']['totalInvocations'])->toBe(3);
    expect($props['stats']['totalTokens'])->toBe(160);
    expect($props['stats']['invocationsByKind'])->toBe([
        'describe' => 1,
        'ask' => 2,
    ]);
    expect($props['stats']['invocationsLast14d'])->toHaveCount(14);
    expect($props['stats']['invocationsLast14d'][13])->toBe([
        'date' => Carbon::today()->toDateString(),
        'total' => 2,
    ]);
    expect($props['stats']['invocationsLast14d'][10])->toBe([
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'total' => 1,
    ]);
    expect($props['stats']['tokensLast14d'][13])->toBe([
        'date' => Carbon::today()->toDateString(),
        'total' => 150,
    ]);
    expect($props['stats']['tokensLast14d'][10])->toBe([
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'total' => 10,
    ]);
});

test('index reports no active vision sub-agent when none is enabled', function () {
    BotSubAgent::factory()->systemVision()->create();

    $props = subAgentsIndexProps();

    expect($props['stats']['visionActive'])->toBeFalse();
    expect($props['stats']['active'])->toBe(0);
    expect($props['stats']['totalInvocations'])->toBe(0);
    expect($props['stats']['totalTokens'])->toBe(0);
    expect($props['stats']['invocationsLast14d'])->toHaveCount(14);
    expect($props['stats']['invocationsLast14d'][13])->toBe([
        'date' => Carbon::today()->toDateString(),
        'total' => 0,
    ]);
});

test('index reports visionActive false when the active vision sub-agent references a disabled provider', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => false]);

    BotSubAgent::factory()->systemVision()->create([
        'is_active' => true,
        'ai_provider_id' => $provider->id,
        'model' => 'claude-3-5-sonnet',
    ]);

    $props = subAgentsIndexProps();

    expect($props['stats']['visionActive'])->toBeFalse();
    expect($props['stats']['active'])->toBe(1);
});

test('index reports visionActive true when the active vision sub-agent uses an enabled provider', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => true]);

    BotSubAgent::factory()->systemVision()->create([
        'is_active' => true,
        'ai_provider_id' => $provider->id,
        'model' => 'gpt-4o',
    ]);

    $props = subAgentsIndexProps();

    expect($props['stats']['visionActive'])->toBeTrue();
});

test('index reports visionActive true when the active vision sub-agent uses the system provider', function () {
    BotSubAgent::factory()->systemVision()->create([
        'is_active' => true,
        'ai_provider_id' => null,
        'model' => 'gpt-4o',
    ]);

    $props = subAgentsIndexProps();

    expect($props['stats']['visionActive'])->toBeTrue();
});

test('the sub-agents page renders with the expected component and props', function () {
    $user = User::factory()->create();
    AiProvider::factory()->create(['is_enabled' => true, 'failover_order' => 0]);

    $response = $this
        ->actingAs($user)
        ->get(route('subagents.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subagents/Index')
            ->has('subAgents')
            ->has('providers', fn (Assert $providers) => $providers
                ->each(fn (Assert $provider) => $provider
                    ->has('id')
                    ->has('label')
                    ->has('base_url')
                    ->has('is_main')))
            ->has('types', 2)
            ->has('stats', fn (Assert $stats) => $stats
                ->has('total')
                ->has('active')
                ->has('visionActive')
                ->has('generalCount')
                ->has('totalInvocations')
                ->has('totalTokens')
                ->has('invocationsByKind', fn (Assert $byKind) => $byKind
                    ->has('describe')
                    ->has('ask'))
                ->has('invocationsLast14d', 14)
                ->has('tokensLast14d', 14)));
});

/**
 * Resolve the index page props through direct controller invocation, which is
 * faster than a full HTML render and independent of the Vite manifest.
 */
function subAgentsIndexProps(): array
{
    $request = Request::create(route('subagents.index'), 'GET');
    $request->headers->set('X-Inertia', 'true');

    $page = app(SubAgentController::class)->index()->toResponse($request)->getData(true);

    return $page['props'];
}
