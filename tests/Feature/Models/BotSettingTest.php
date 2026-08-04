<?php

use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('bot_settings table exists', function () {
    expect(Schema::hasTable('bot_settings'))->toBeTrue();
});

test('singleton returns and reuses a single row with database defaults', function () {
    $first = BotSetting::singleton();

    $second = BotSetting::singleton();

    expect($second->is($first))->toBeTrue();
    expect(BotSetting::count())->toBe(1);
    expect($first->exists)->toBeTrue();
    expect($first->max_history_messages)->toBe(50);
});

test('system_prompt can be set and max_history_messages casts to integer', function () {
    $setting = BotSetting::factory()->create([
        'system_prompt' => 'Custom prompt',
        'max_history_messages' => 20,
    ]);

    expect($setting->system_prompt)->toBe('Custom prompt');
    expect($setting->max_history_messages)->toBe(20)->toBeInt();
});

test('belongs to an owner user', function () {
    $user = User::factory()->create();

    $setting = BotSetting::factory()->create(['owner_user_id' => $user->id]);

    expect($setting->owner->is($user))->toBeTrue();
});

test('factory produces a valid row', function () {
    $setting = BotSetting::factory()->create();

    expect($setting->exists)->toBeTrue();
    expect($setting->system_prompt)->not->toBeNull();
    expect($setting->owner)->not->toBeNull();
});
