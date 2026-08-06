<?php

use App\Ai\Tools\Opencode\SearchSessionsTool;
use App\Models\OpencodeSessionDismissal;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Support\OpencodeStoreFixture;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = new FakeOpencodeSessionManager;
});

afterEach(function () {
    OpencodeStoreFixture::cleanup();
});

test('returns the sessions matching the query with their metadata', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_match', 'title' => 'Refactor the auth module', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_other', 'title' => 'Build the dashboard', 'directory' => '/projects/two', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request(['query' => 'auth module']));

    expect($result)->toContain('Found 1 opencode session')
        ->toContain('ses_match')
        ->toContain('Refactor the auth module')
        ->toContain('/projects/one')
        ->not->toContain('ses_other');
});

test('returns a readable message when nothing matches', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_other', 'title' => 'Build the dashboard', 'directory' => '/projects/two', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request(['query' => 'nothing matches']));

    expect($result)->toContain('No opencode sessions found')
        ->toContain('nothing matches');
});

test('flags dismissed sessions in the results', function () {
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_done']);

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_done', 'title' => 'Old wiring', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
    ));

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request(['query' => 'wiring']));

    expect($result)->toContain('ses_done')
        ->toContain('dismissed');
});

test('flags archived sessions and marks working sessions as working', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_arch', 'title' => 'Old archived thing', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => 2000],
            ['id' => 'ses_work', 'title' => 'Still working thing', 'directory' => '/projects/two', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'part_1', 'session_id' => 'ses_work', 'time_created' => 2500, 'time_updated' => 3000, 'data' => json_encode(['type' => 'tool', 'state' => ['status' => 'running']])],
        ],
    ));

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request(['query' => 'thing']));

    expect($result)->toContain('ses_arch')
        ->toContain('archived')
        ->toContain('ses_work')
        ->toContain('working');
});

test('respects the limit argument', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Task one', 'directory' => '/p/1', 'time_created' => 1000, 'time_updated' => 5000, 'time_archived' => null],
            ['id' => 'ses_2', 'title' => 'Task two', 'directory' => '/p/2', 'time_created' => 1000, 'time_updated' => 4000, 'time_archived' => null],
            ['id' => 'ses_3', 'title' => 'Task three', 'directory' => '/p/3', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
        ],
    ));

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request(['query' => 'Task', 'limit' => 2]));

    expect($result)->toContain('Found 2 opencode session')
        ->toContain('ses_1')
        ->toContain('ses_2')
        ->not->toContain('ses_3');
});

test('returns a readable error when the query is missing', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create());

    $tool = new SearchSessionsTool($this->manager, $store);

    $result = $tool->handle(new Request([]));

    expect($result)->toContain('Error: missing "query"');
});
