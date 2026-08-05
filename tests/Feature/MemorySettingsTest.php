<?php

use App\Models\BotMemory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the memories routes', function () {
    $this->get(route('memories.index'))->assertRedirect(route('login'));

    $memory = BotMemory::factory()->create();

    $this->delete(route('memories.destroy', $memory))->assertRedirect(route('login'));
});

test('memories page lists memories without leaking embeddings', function () {
    $user = User::factory()->create();

    $memory = BotMemory::factory()->create([
        'summary' => 'Uses local embeddings',
        'category' => 'technical_context',
        'importance' => 8,
        'access_count' => 3,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('memories.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Memories')
            ->where('memories.total', 1)
            ->has('memories.data', 1)
            ->where('memories.data.0.id', $memory->id)
            ->where('memories.data.0.chat_id', $memory->chat_id)
            ->where('memories.data.0.summary', 'Uses local embeddings')
            ->where('memories.data.0.category', 'technical_context')
            ->where('memories.data.0.importance', 8)
            ->where('memories.data.0.access_count', 3)
            ->where('filters.search', null)
            ->where('filters.category', null)
            ->where('filters.sort', 'created_at')
            ->where('filters.dir', 'desc')
            ->where('stats.total', 1)
            ->where('stats.per_category.technical_context', 1)
            ->where('categories', [
                ['value' => 'technical_context', 'label' => 'Technical context'],
                ['value' => 'decision', 'label' => 'Decision'],
                ['value' => 'user_preference', 'label' => 'User preference'],
                ['value' => 'fact', 'label' => 'Fact'],
            ])
            ->missing('memories.data.0.embedding')
            ->missing('memories.data.0.embedding_model'),
        );
});

test('memories stats aggregate totals and recent counts', function () {
    $user = User::factory()->create();

    BotMemory::factory()->create(['category' => 'decision']);
    BotMemory::factory()->count(3)->create(['category' => 'fact']);
    BotMemory::factory()->create([
        'category' => 'user_preference',
        'created_at' => now()->subDays(10),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('memories.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 5)
            ->where('stats.per_category.decision', 1)
            ->where('stats.per_category.fact', 3)
            ->where('stats.per_category.user_preference', 1)
            ->where('stats.last_7_days', 4)
            ->has('stats.series_daily', 14)
            ->where('stats.series_daily.0.date', now()->subDays(13)->toDateString())
            ->where('stats.series_daily.0.count', 0)
            ->where('stats.series_daily.3.count', 1)
            ->where('stats.series_daily.13.date', now()->toDateString())
            ->where('stats.series_daily.13.count', 4)
            ->where('stats.series_by_category', [
                ['category' => 'decision', 'count' => 1],
                ['category' => 'user_preference', 'count' => 1],
                ['category' => 'fact', 'count' => 3],
            ]),
        );
});

test('memories can be searched by summary or content', function () {
    $user = User::factory()->create();

    $matching = BotMemory::factory()->create([
        'summary' => 'Remembers the database schema',
        'content' => 'Uses SQLite for storage',
    ]);

    BotMemory::factory()->create([
        'summary' => 'Prefers Spanish replies',
        'content' => 'User likes short answers',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('memories.index', ['search' => 'SQLite']));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('memories.total', 1)
            ->where('memories.data.0.id', $matching->id)
            ->where('filters.search', 'SQLite'),
        );
});

test('memories can be filtered by category', function () {
    $user = User::factory()->create();

    BotMemory::factory()->create(['category' => 'decision']);
    BotMemory::factory()->create(['category' => 'user_preference']);
    $fact = BotMemory::factory()->create(['category' => 'fact']);

    $response = $this
        ->actingAs($user)
        ->get(route('memories.index', ['category' => 'fact']));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('memories.total', 1)
            ->where('memories.data.0.id', $fact->id)
            ->where('filters.category', 'fact'),
        );
});

test('memories can be sorted by importance', function () {
    $user = User::factory()->create();

    $low = BotMemory::factory()->create(['importance' => 2]);
    $high = BotMemory::factory()->create(['importance' => 9]);

    $response = $this
        ->actingAs($user)
        ->get(route('memories.index', ['sort' => 'importance', 'dir' => 'desc']));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('memories.total', 2)
            ->where('memories.data.0.id', $high->id)
            ->where('memories.data.1.id', $low->id)
            ->where('filters.sort', 'importance')
            ->where('filters.dir', 'desc'),
        );
});

test('empty sort and dir query params fall back to defaults', function () {
    $user = User::factory()->create();

    $older = BotMemory::factory()->create(['created_at' => now()->subDay()]);
    $newer = BotMemory::factory()->create(['created_at' => now()]);

    $this->actingAs($user)
        ->get(route('memories.index', ['sort' => '', 'dir' => '']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'created_at')
            ->where('filters.dir', 'desc')
            ->where('memories.data.0.id', $newer->id)
            ->where('memories.data.1.id', $older->id),
        );
});

test('invalid memories filters are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('memories.index'))
        ->get(route('memories.index', [
            'category' => 'bogus',
            'sort' => 'embedding',
            'dir' => 'sideways',
        ]))
        ->assertSessionHasErrors(['category', 'sort', 'dir']);
});

test('a memory can be deleted', function () {
    $user = User::factory()->create();

    $memory = BotMemory::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('memories.index'))
        ->delete(route('memories.destroy', $memory));

    $response
        ->assertRedirect(route('memories.index'))
        ->assertSessionHas('inertia.flash_data.toast');

    expect(session('inertia.flash_data.toast'))->toMatchArray(['type' => 'success']);
    expect(BotMemory::find($memory->id))->toBeNull();
});
