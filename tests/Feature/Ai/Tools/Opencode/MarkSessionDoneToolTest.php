<?php

use App\Ai\Tools\Opencode\MarkSessionDoneTool;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = new FakeOpencodeSessionManager;
    $this->tool = new MarkSessionDoneTool($this->manager);
});

test('marks a session as done by inserting a dismissal', function () {
    $result = $this->tool->handle(new Request(['session_id' => 'ses_done_001']));

    expect($result)->toContain('ses_done_001')
        ->toContain('marked as done');

    $dismissal = OpencodeSessionDismissal::find('ses_done_001');

    expect($dismissal)->not->toBeNull()
        ->and($dismissal->dismissed_at)->not->toBeNull();
});

test('marking an already done session is idempotent', function () {
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_done_001']);

    $result = $this->tool->handle(new Request(['session_id' => 'ses_done_001']));

    expect($result)->toContain('ses_done_001')
        ->toContain('already marked as done')
        ->and(OpencodeSessionDismissal::count())->toBe(1);
});

test('resolves the session by query title when the session id is missing', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_resolved_001',
        'title' => 'Refactor the auth module',
    ]);

    $result = $this->tool->handle(new Request(['query' => 'auth module']));

    expect($result)->toContain('ses_resolved_001')
        ->toContain('marked as done')
        ->and(OpencodeSessionDismissal::whereKey('ses_resolved_001')->exists())->toBeTrue();
});

test('resolves the session by query escaping LIKE wildcards', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_percent_001',
        'title' => 'Fix the 100% bug',
    ]);
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_plain_001',
        'title' => 'Fix the 100x bug',
    ]);

    $result = $this->tool->handle(new Request(['query' => '100%']));

    expect($result)->toContain('ses_percent_001')
        ->toContain('marked as done')
        ->and(OpencodeSessionDismissal::whereKey('ses_percent_001')->exists())->toBeTrue()
        ->and(OpencodeSessionDismissal::whereKey('ses_plain_001')->exists())->toBeFalse();
});

test('resolves the session by query treating underscores literally', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_underscore_001',
        'title' => 'Fix_the_bug',
    ]);
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_hyphen_001',
        'title' => 'Fix-the-bug',
    ]);

    $result = $this->tool->handle(new Request(['query' => 'Fix_the_bug']));

    expect($result)->toContain('ses_underscore_001')
        ->toContain('marked as done')
        ->and(OpencodeSessionDismissal::whereKey('ses_underscore_001')->exists())->toBeTrue()
        ->and(OpencodeSessionDismissal::whereKey('ses_hyphen_001')->exists())->toBeFalse();
});

test('returns a readable error when no session id or query is given', function () {
    $result = $this->tool->handle(new Request([]));

    expect($result)->toContain('Error: missing "session_id"')
        ->and(OpencodeSessionDismissal::count())->toBe(0);
});
