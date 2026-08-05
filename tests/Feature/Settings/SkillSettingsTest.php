<?php

use App\Models\BotSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the skills settings routes', function () {
    $this->get(route('settings.skills.index'))->assertRedirect(route('login'));
    $this->post(route('settings.skills.store'))->assertRedirect(route('login'));

    $skill = BotSkill::factory()->create();

    $this->patch(route('settings.skills.update', $skill))->assertRedirect(route('login'));
    $this->delete(route('settings.skills.destroy', $skill))->assertRedirect(route('login'));
});

test('skills page lists skills in sort order', function () {
    $user = User::factory()->create();

    $first = BotSkill::factory()->create([
        'name' => 'First Skill',
        'slug' => 'first-skill',
        'sort_order' => 0,
        'active' => true,
    ]);

    $second = BotSkill::factory()->create([
        'name' => 'Second Skill',
        'slug' => 'second-skill',
        'sort_order' => 1,
        'active' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('settings.skills.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Skills')
            ->where('skills.0.id', $first->id)
            ->where('skills.0.name', 'First Skill')
            ->where('skills.0.slug', 'first-skill')
            ->where('skills.0.active', true)
            ->where('skills.0.sort_order', 0)
            ->where('skills.0.trigger_keywords', $first->trigger_keywords)
            ->where('skills.1.id', $second->id)
            ->where('skills.1.active', false)
            ->where('skills.1.sort_order', 1),
        );
});

test('a skill can be created with comma-separated trigger keywords', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('settings.skills.index'))
        ->post(route('settings.skills.store'), [
            'name' => 'Opencode Orchestration',
            'slug' => 'opencode-orchestration',
            'description' => 'Guides opencode sessions',
            'content' => 'You orchestrate opencode sessions step by step.',
            'trigger_keywords' => 'opencode, session, workflow',
            'active' => '1',
            'sort_order' => '0',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.skills.index'));

    $skill = BotSkill::where('slug', 'opencode-orchestration')->firstOrFail();

    expect($skill->name)->toBe('Opencode Orchestration');
    expect($skill->description)->toBe('Guides opencode sessions');
    expect($skill->trigger_keywords)->toBe(['opencode', 'session', 'workflow']);
    expect($skill->active)->toBeTrue();
    expect($skill->sort_order)->toBe(0);
});

test('a skill can be updated', function () {
    $user = User::factory()->create();

    $skill = BotSkill::factory()->create([
        'name' => 'Old Name',
        'trigger_keywords' => ['old'],
        'active' => true,
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('settings.skills.index'))
        ->patch(route('settings.skills.update', $skill), [
            'name' => 'New Name',
            'slug' => $skill->slug,
            'description' => 'Updated description',
            'content' => 'Updated content',
            'trigger_keywords' => 'new, keyword',
            'active' => '0',
            'sort_order' => '5',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.skills.index'));

    $skill->refresh();

    expect($skill->name)->toBe('New Name');
    expect($skill->trigger_keywords)->toBe(['new', 'keyword']);
    expect($skill->active)->toBeFalse();
    expect($skill->sort_order)->toBe(5);
});

test('a skill can be deleted', function () {
    $user = User::factory()->create();

    $skill = BotSkill::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('settings.skills.index'))
        ->delete(route('settings.skills.destroy', $skill));

    $response->assertRedirect(route('settings.skills.index'));

    expect(BotSkill::find($skill->id))->toBeNull();
});

test('a duplicate slug is rejected', function () {
    $user = User::factory()->create();

    BotSkill::factory()->create(['slug' => 'existing-slug']);

    $this->actingAs($user)
        ->from(route('settings.skills.index'))
        ->post(route('settings.skills.store'), [
            'name' => 'Duplicate',
            'slug' => 'existing-slug',
            'content' => 'Content',
        ])
        ->assertSessionHasErrors(['slug'])
        ->assertRedirect(route('settings.skills.index'));
});

test('invalid skill data is rejected with validation errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.skills.index'))
        ->post(route('settings.skills.store'), [
            'name' => '',
            'slug' => 'not valid slug!',
            'content' => '',
        ])
        ->assertSessionHasErrors(['name', 'slug', 'content'])
        ->assertRedirect(route('settings.skills.index'));
});
