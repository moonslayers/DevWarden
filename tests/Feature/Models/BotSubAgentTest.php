<?php

use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Models\AiProvider;
use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('bot_sub_agents table exists', function () {
    expect(Schema::hasTable('bot_sub_agents'))->toBeTrue();
});

test('type enum is cast correctly', function () {
    $subAgent = BotSubAgent::factory()->create(['type' => BotSubAgentType::Vision]);

    expect($subAgent->type)->toBe(BotSubAgentType::Vision);
    expect(DB::table('bot_sub_agents')->value('type'))->toBe('vision');
});

test('activeVision returns the first active vision sub-agent ordered by sort_order', function () {
    $provider = AiProvider::factory()->create(['is_enabled' => true]);

    $later = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => $provider->id,
        'sort_order' => 2,
    ]);
    $first = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => null,
        'sort_order' => 0,
    ]);

    expect(BotSubAgent::activeVision()?->is($first))->toBeTrue();
    expect($later->exists)->toBeTrue();
});

test('activeVision ignores inactive vision sub-agents', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => false,
        'sort_order' => 0,
    ]);

    expect(BotSubAgent::activeVision())->toBeNull();
});

test('activeVision ignores vision sub-agents whose provider is disabled', function () {
    $disabled = AiProvider::factory()->forType(AiProviderType::OpenAI)->create(['is_enabled' => false]);
    $enabled = AiProvider::factory()->forType(AiProviderType::Anthropic)->create(['is_enabled' => true]);

    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => $disabled->id,
        'sort_order' => 0,
    ]);
    $onEnabled = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => $enabled->id,
        'sort_order' => 1,
    ]);

    expect(BotSubAgent::activeVision()?->is($onEnabled))->toBeTrue();
});

test('activeVision ignores general sub-agents', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::General,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    expect(BotSubAgent::activeVision())->toBeNull();
});

test('active and ordered scopes filter and order', function () {
    $last = BotSubAgent::factory()->create(['is_active' => true, 'sort_order' => 2]);
    BotSubAgent::factory()->create(['is_active' => false, 'sort_order' => 0]);
    $first = BotSubAgent::factory()->create(['is_active' => true, 'sort_order' => 1]);

    $rows = BotSubAgent::query()->active()->ordered()->get();

    expect($rows->pluck('id')->all())->toBe([$first->id, $last->id]);
});

test('usesSystemProvider reflects a null ai_provider_id', function () {
    $system = BotSubAgent::factory()->create(['ai_provider_id' => null]);
    $dedicated = BotSubAgent::factory()->create(['ai_provider_id' => AiProvider::factory()->create()->id]);

    expect($system->usesSystemProvider())->toBeTrue();
    expect($dedicated->usesSystemProvider())->toBeFalse();
});

test('aiProvider relation resolves the backing provider', function () {
    $provider = AiProvider::factory()->create();

    $subAgent = BotSubAgent::factory()->create(['ai_provider_id' => $provider->id]);

    expect($subAgent->aiProvider->is($provider))->toBeTrue();
});

test('systemVision factory state builds the immutable vision default', function () {
    $vision = BotSubAgent::factory()->systemVision()->create();

    expect($vision->name)->toBe('Vision');
    expect($vision->slug)->toBe('vision');
    expect($vision->type)->toBe(BotSubAgentType::Vision);
    expect($vision->is_active)->toBeFalse();
    expect($vision->is_system)->toBeTrue();
    expect($vision->ai_provider_id)->toBeNull();
    expect($vision->model)->toBeNull();
    expect($vision->sort_order)->toBe(0);
    expect($vision->usesSystemProvider())->toBeTrue();
});

test('usageLogs relation resolves via the sub_agent_id foreign key', function () {
    $subAgent = BotSubAgent::factory()->create();
    SubAgentUsageLog::create([
        'sub_agent_id' => $subAgent->id,
        'kind' => 'chat',
        'tokens' => 250,
    ]);

    expect($subAgent->usageLogs->count())->toBe(1);

    $withCount = BotSubAgent::withCount('usageLogs')->find($subAgent->id);
    expect($withCount->usage_logs_count)->toBe(1);

    $withSum = BotSubAgent::withSum('usageLogs', 'tokens')->find($subAgent->id);
    expect($withSum->usage_logs_sum_tokens)->toBe(250);
});
