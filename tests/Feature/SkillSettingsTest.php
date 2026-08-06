<?php

use App\Models\BotSkill;
use App\Models\SkillUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the skills routes', function () {
    $this->get(route('skills.index'))->assertRedirect(route('login'));
    $this->post(route('skills.store'))->assertRedirect(route('login'));

    $skill = BotSkill::factory()->create();

    $this->patch(route('skills.update', $skill))->assertRedirect(route('login'));
    $this->delete(route('skills.destroy', $skill))->assertRedirect(route('login'));
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
        ->get(route('skills.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills')
            ->has('skills', 2)
            ->where('skills.0.id', $first->id)
            ->where('skills.0.name', 'First Skill')
            ->where('skills.0.slug', 'first-skill')
            ->where('skills.0.active', true)
            ->where('skills.0.sort_order', 0)
            ->where('skills.0.trigger_keywords', $first->trigger_keywords)
            ->where('skills.1.id', $second->id)
            ->where('skills.1.active', false)
            ->where('skills.1.sort_order', 1)
            ->has('stats.total_matches')
            ->has('stats.active_count')
            ->has('stats.inactive_count')
            ->has('stats.matches_by_day.labels')
            ->has('stats.matches_by_day.count')
            ->has('stats.top_skills'),
        );
});

test('skills page aggregates usage stats from skill usage logs', function () {
    $user = User::factory()->create();

    $active = BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'active' => true,
    ]);

    $inactive = BotSkill::factory()->create([
        'name' => 'Deployment',
        'active' => false,
    ]);

    SkillUsageLog::factory()->create([
        'skill_id' => $active->id,
        'chat_id' => 123456789,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('skills.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills')
            ->has('skills', 2)
            ->where('stats.total_matches', 1)
            ->where('stats.active_count', 1)
            ->where('stats.inactive_count', 1)
            ->has('stats.matches_by_day.labels', 14)
            ->has('stats.matches_by_day.count', 14)
            ->where('stats.matches_by_day.labels.0', Carbon::today()->subDays(13)->toDateString())
            ->where('stats.matches_by_day.labels.13', Carbon::today()->toDateString())
            ->where('stats.matches_by_day.count.13', 1)
            ->has('stats.top_skills', 1)
            ->where('stats.top_skills.0.id', $active->id)
            ->where('stats.top_skills.0.name', 'Opencode Orchestration')
            ->where('stats.top_skills.0.match_count', 1),
        );
});

test('skills page stats are zero-filled when there is no usage', function () {
    $user = User::factory()->create();

    BotSkill::factory()->create(['name' => 'Active Skill', 'active' => true]);
    BotSkill::factory()->create(['name' => 'Inactive Skill', 'active' => false]);

    $response = $this
        ->actingAs($user)
        ->get(route('skills.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills')
            ->has('skills', 2)
            ->where('stats.total_matches', 0)
            ->where('stats.active_count', 1)
            ->where('stats.inactive_count', 1)
            ->has('stats.matches_by_day.labels', 14)
            ->where('stats.matches_by_day.labels.13', Carbon::today()->toDateString())
            ->where('stats.matches_by_day.count', array_fill(0, 14, 0))
            ->where('stats.top_skills', []),
        );
});

test('skills page returns the top 5 skills by usage count descending with a name tiebreak', function () {
    $user = User::factory()->create();

    $alpha = BotSkill::factory()->create(['name' => 'Alpha']);
    $beta = BotSkill::factory()->create(['name' => 'Beta']);
    $gamma = BotSkill::factory()->create(['name' => 'Gamma']);
    $delta = BotSkill::factory()->create(['name' => 'Delta']);
    $echo = BotSkill::factory()->create(['name' => 'Echo']);
    $foxtrot = BotSkill::factory()->create(['name' => 'Foxtrot']);

    SkillUsageLog::factory()->count(6)->create(['skill_id' => $alpha->id]);
    SkillUsageLog::factory()->count(5)->create(['skill_id' => $beta->id]);
    SkillUsageLog::factory()->count(4)->create(['skill_id' => $gamma->id]);
    SkillUsageLog::factory()->count(3)->create(['skill_id' => $delta->id]);
    SkillUsageLog::factory()->count(3)->create(['skill_id' => $echo->id]);
    SkillUsageLog::factory()->count(1)->create(['skill_id' => $foxtrot->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('skills.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills')
            ->where('stats.top_skills', [
                ['id' => $alpha->id, 'name' => 'Alpha', 'match_count' => 6],
                ['id' => $beta->id, 'name' => 'Beta', 'match_count' => 5],
                ['id' => $gamma->id, 'name' => 'Gamma', 'match_count' => 4],
                ['id' => $delta->id, 'name' => 'Delta', 'match_count' => 3],
                ['id' => $echo->id, 'name' => 'Echo', 'match_count' => 3],
            ]),
        );
});

test('a skill can be created with comma-separated trigger keywords', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('skills.index'))
        ->post(route('skills.store'), [
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
        ->assertRedirect(route('skills.index'));

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
        ->from(route('skills.index'))
        ->patch(route('skills.update', $skill), [
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
        ->assertRedirect(route('skills.index'));

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
        ->from(route('skills.index'))
        ->delete(route('skills.destroy', $skill));

    $response->assertRedirect(route('skills.index'));

    expect(BotSkill::find($skill->id))->toBeNull();
});

test('a duplicate slug is rejected', function () {
    $user = User::factory()->create();

    BotSkill::factory()->create(['slug' => 'existing-slug']);

    $this->actingAs($user)
        ->from(route('skills.index'))
        ->post(route('skills.store'), [
            'name' => 'Duplicate',
            'slug' => 'existing-slug',
            'content' => 'Content',
        ])
        ->assertSessionHasErrors(['slug'])
        ->assertRedirect(route('skills.index'));
});

test('invalid skill data is rejected with validation errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('skills.index'))
        ->post(route('skills.store'), [
            'name' => '',
            'slug' => 'not valid slug!',
            'content' => '',
        ])
        ->assertSessionHasErrors(['name', 'slug', 'content'])
        ->assertRedirect(route('skills.index'));
});
