<?php

use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the bot settings page', function () {
    $this->get(route('bot.edit'))->assertRedirect(route('login'));
    $this->patch(route('bot.update'), [])->assertRedirect(route('login'));
});

test('bot settings page is displayed with current values and users', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Owner User']);

    BotSetting::factory()->create([
        'id' => 1,
        'system_prompt' => 'You are the DevWarden bot.',
        'max_history_messages' => 20,
        'owner_user_id' => $owner->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('bot.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Bot')
            ->where('system_prompt', 'You are the DevWarden bot.')
            ->where('max_history_messages', 20)
            ->where('owner_user_id', $owner->id)
            ->has('users', 2),
        );

    expect($response->viewData('page')['props']['users'])
        ->toContain(['id' => $owner->id, 'name' => 'Owner User']);
});

test('bot settings can be updated', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    BotSetting::singleton();

    $response = $this
        ->actingAs($user)
        ->from(route('bot.edit'))
        ->patch(route('bot.update'), [
            'system_prompt' => 'Be concise.',
            'max_history_messages' => 30,
            'owner_user_id' => $owner->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('bot.edit'));

    $settings = BotSetting::singleton();

    expect($settings->system_prompt)->toBe('Be concise.');
    expect($settings->max_history_messages)->toBe(30);
    expect($settings->owner_user_id)->toBe($owner->id);
});

test('max history messages defaults to 50 when not provided', function () {
    $user = User::factory()->create();

    BotSetting::singleton();

    $response = $this
        ->actingAs($user)
        ->from(route('bot.edit'))
        ->patch(route('bot.update'), []);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('bot.edit'));

    expect(BotSetting::singleton()->max_history_messages)->toBe(50);
});

test('invalid bot settings are rejected with validation errors', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('bot.edit'))
        ->patch(route('bot.update'), [
            'max_history_messages' => 0,
            'owner_user_id' => 999999,
        ]);

    $response
        ->assertSessionHasErrors(['max_history_messages', 'owner_user_id'])
        ->assertRedirect(route('bot.edit'));
});
