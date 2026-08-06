<?php

use App\Ai\Tools\Opencode\ReactivateSessionTool;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = new FakeOpencodeSessionManager;
    $this->tool = new ReactivateSessionTool($this->manager);
});

test('reactivates a dismissed session by deleting its dismissal', function () {
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_react_001']);

    $result = $this->tool->handle(new Request(['session_id' => 'ses_react_001']));

    expect($result)->toContain('ses_react_001')
        ->toContain('reactivated')
        ->and(OpencodeSessionDismissal::whereKey('ses_react_001')->exists())->toBeFalse();
});

test('returns a message when the session was not dismissed', function () {
    $result = $this->tool->handle(new Request(['session_id' => 'ses_react_002']));

    expect($result)->toContain('ses_react_002')
        ->toContain('not marked as done');
});

test('resolves the session by query directory when the session id is missing', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_react_003',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_react_003']);

    $result = $this->tool->handle(new Request(['query' => 'DevWarden']));

    expect($result)->toContain('ses_react_003')
        ->toContain('reactivated')
        ->and(OpencodeSessionDismissal::whereKey('ses_react_003')->exists())->toBeFalse();
});

test('returns a readable error when no session id or query is given', function () {
    $result = $this->tool->handle(new Request([]));

    expect($result)->toContain('Error: missing "session_id"');
});
