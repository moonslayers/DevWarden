<?php

use App\Models\BotSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('factory creates a skill with the expected casts', function () {
    $skill = BotSkill::factory()->create();

    expect($skill->trigger_keywords)->toBeArray();
    expect($skill->active)->toBeBool();
    expect($skill->active)->toBeTrue();
    expect($skill->sort_order)->toBeInt();
});

test('matches returns true when the text contains a trigger keyword, ignoring case', function () {
    $skill = BotSkill::factory()->create([
        'trigger_keywords' => ['opencode', 'workflow'],
    ]);

    expect($skill->matches('How do I run an OpenCode session?'))->toBeTrue();
    expect($skill->matches('WORKFLOW status, please'))->toBeTrue();
});

test('matches returns false when no trigger keyword appears in the text', function () {
    $skill = BotSkill::factory()->create([
        'trigger_keywords' => ['opencode'],
    ]);

    expect($skill->matches('tell me a joke'))->toBeFalse();
});

test('matches returns false when the skill is inactive', function () {
    $skill = BotSkill::factory()->create([
        'trigger_keywords' => ['opencode'],
        'active' => false,
    ]);

    expect($skill->matches('opencode session'))->toBeFalse();
});

test('matches returns false when trigger keywords are empty', function () {
    $skill = BotSkill::factory()->create([
        'trigger_keywords' => [],
    ]);

    expect($skill->matches('opencode session'))->toBeFalse();
});

test('active scope only returns active skills, ordered by sort_order', function () {
    BotSkill::factory()->create(['name' => 'Inactive', 'active' => false, 'sort_order' => 1]);
    BotSkill::factory()->create(['name' => 'Second', 'active' => true, 'sort_order' => 10]);
    BotSkill::factory()->create(['name' => 'First', 'active' => true, 'sort_order' => 5]);

    $names = BotSkill::query()->active()->ordered()->pluck('name')->all();

    expect($names)->toBe(['First', 'Second']);
});
