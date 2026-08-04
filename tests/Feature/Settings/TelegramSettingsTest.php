<?php

use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the telegram settings page', function () {
    $this->get(route('telegram.edit'))->assertRedirect(route('login'));
    $this->patch(route('telegram.update'), [])->assertRedirect(route('login'));
});

test('telegram settings page is displayed with current values without leaking the token', function () {
    $user = User::factory()->create();

    TelegramSetting::factory()->create([
        'id' => 1,
        'bot_token' => '123456789:ABCdef_XYZ-token',
        'allowed_user_ids' => [111, 222],
        'polling_enabled' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('telegram.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Telegram')
            ->where('has_bot_token', true)
            ->where('allowed_user_ids', [111, 222])
            ->where('polling_enabled', false)
            ->missing('bot_token'),
        );

    expect($response->getContent())->not->toContain('123456789:ABCdef_XYZ-token');
});

test('telegram settings can be updated', function () {
    $user = User::factory()->create();

    TelegramSetting::singleton();

    $response = $this
        ->actingAs($user)
        ->from(route('telegram.edit'))
        ->patch(route('telegram.update'), [
            'bot_token' => '987654321:new-token',
            'allowed_user_ids' => '1, 2,3',
            'polling_enabled' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('telegram.edit'));

    $settings = TelegramSetting::singleton();

    expect($settings->bot_token)->toBe('987654321:new-token');
    expect($settings->allowed_user_ids)->toBe([1, 2, 3]);
    expect($settings->polling_enabled)->toBeTrue();
});

test('telegram settings accept an array of allowed user ids', function () {
    $user = User::factory()->create();

    TelegramSetting::singleton();

    $response = $this
        ->actingAs($user)
        ->from(route('telegram.edit'))
        ->patch(route('telegram.update'), [
            'allowed_user_ids' => [42, 43],
            'polling_enabled' => false,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('telegram.edit'));

    expect(TelegramSetting::singleton()->allowed_user_ids)->toBe([42, 43]);
    expect(TelegramSetting::singleton()->polling_enabled)->toBeFalse();
});

test('a blank bot token keeps the existing token', function () {
    $user = User::factory()->create();

    TelegramSetting::singleton()->update(['bot_token' => 'existing-token']);

    $response = $this
        ->actingAs($user)
        ->from(route('telegram.edit'))
        ->patch(route('telegram.update'), [
            'bot_token' => '',
            'allowed_user_ids' => [],
            'polling_enabled' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('telegram.edit'));

    expect(TelegramSetting::singleton()->bot_token)->toBe('existing-token');
});

test('invalid telegram settings are rejected with validation errors', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('telegram.edit'))
        ->patch(route('telegram.update'), [
            'allowed_user_ids' => ['abc'],
            'polling_enabled' => 'not-a-boolean',
        ]);

    $response
        ->assertSessionHasErrors(['allowed_user_ids.0', 'polling_enabled'])
        ->assertRedirect(route('telegram.edit'));
});
