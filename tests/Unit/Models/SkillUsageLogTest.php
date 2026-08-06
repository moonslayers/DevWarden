<?php

use App\Models\BotSkill;
use App\Models\SkillUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('factory creates a usage log with a real related skill and a chat id', function () {
    $log = SkillUsageLog::factory()->create();

    expect($log->skill_id)->toBeInt()
        ->and($log->chat_id)->toBeInt()
        ->and($log->created_at)->not->toBeNull()
        ->and($log->skill)->toBeInstanceOf(BotSkill::class);
});

test('skill returns the bot skill that recorded the usage log', function () {
    $skill = BotSkill::factory()->create();

    $log = SkillUsageLog::factory()->create(['skill_id' => $skill->id]);

    expect($log->skill)->toBeInstanceOf(BotSkill::class)
        ->and($log->skill->is($skill))->toBeTrue();
});

test('usageLogs returns the logs belonging to a skill and withCount aggregates them', function () {
    $skill = BotSkill::factory()->create();

    SkillUsageLog::factory()->count(2)->create(['skill_id' => $skill->id]);
    SkillUsageLog::factory()->create();

    expect($skill->usageLogs()->count())->toBe(2);

    $withCount = BotSkill::query()->withCount('usageLogs')->findOrFail($skill->id);

    expect($withCount->usage_logs_count)->toBe(2);
});

test('deleting a bot skill cascades to its usage logs only', function () {
    $deleted = BotSkill::factory()->create();
    $kept = BotSkill::factory()->create();

    SkillUsageLog::factory()->create(['skill_id' => $deleted->id]);
    SkillUsageLog::factory()->create(['skill_id' => $kept->id]);

    $deleted->delete();

    expect(SkillUsageLog::query()->where('skill_id', $deleted->id)->exists())->toBeFalse()
        ->and(SkillUsageLog::query()->where('skill_id', $kept->id)->exists())->toBeTrue()
        ->and(SkillUsageLog::count())->toBe(1);
});
