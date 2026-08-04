<?php

use App\Models\TelegramSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('telegram_settings table exists', function () {
    expect(Schema::hasTable('telegram_settings'))->toBeTrue();
});

test('bot_token is encrypted at rest and round-trips', function () {
    $token = '123456789:ABCdef0123456789_-ZYXWVUTSRQ';

    $setting = TelegramSetting::factory()->create(['bot_token' => $token]);

    expect($setting->bot_token)->toBe($token);
    expect($setting->getRawOriginal('bot_token'))->not->toBe($token);
    expect(DB::table('telegram_settings')->value('bot_token'))->not->toBe($token);
});

test('allowed_user_ids is cast to an array', function () {
    $ids = [123456789, 987654321];

    $setting = TelegramSetting::factory()->create(['allowed_user_ids' => $ids]);

    expect($setting->allowed_user_ids)->toBeArray()->toBe($ids);
});

test('polling is enabled by default', function () {
    $setting = TelegramSetting::factory()->create();

    expect($setting->polling_enabled)->toBeTrue();
});

test('singleton returns and reuses a single row with database defaults', function () {
    $first = TelegramSetting::singleton();

    $second = TelegramSetting::singleton();

    expect($second->is($first))->toBeTrue();
    expect(TelegramSetting::count())->toBe(1);
    expect($first->exists)->toBeTrue();
    expect($first->polling_enabled)->toBeTrue();
});

test('factory produces a valid row', function () {
    $setting = TelegramSetting::factory()->create();

    expect($setting->exists)->toBeTrue();
    expect($setting->bot_token)->not->toBeNull();
    expect($setting->allowed_user_ids)->toBeArray();
});
