<?php

use App\Services\Opencode\OpencodeSessionStore;
use Tests\Support\OpencodeStoreFixture;

/**
 * Build a part row whose data is a tool part with the given state.
 */
function opencodeToolPart(string $id, string $sessionId, int $timeCreated, string $status, string $error = '', string $tool = 'bash'): array
{
    $state = ['status' => $status];

    if ($error !== '') {
        $state['error'] = $error;
    }

    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $timeCreated,
        'time_updated' => $timeCreated,
        'data' => json_encode(['type' => 'tool', 'tool' => $tool, 'state' => $state]),
    ];
}

/**
 * Build a part row whose data is a step-finish part closing a turn.
 */
function opencodeStepFinishPart(string $id, string $sessionId, int $timeCreated): array
{
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $timeCreated,
        'time_updated' => $timeCreated,
        'data' => json_encode(['type' => 'step-finish', 'reason' => 'stop']),
    ];
}

/**
 * Build a part row whose data is a non-tool (text) part.
 */
function opencodeTextPart(string $id, string $sessionId, int $timeCreated): array
{
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $timeCreated,
        'time_updated' => $timeCreated,
        'data' => json_encode(['type' => 'text', 'text' => 'continued']),
    ];
}

/**
 * Build a part row whose data is a question tool part carrying input questions.
 */
function opencodeQuestionPart(string $id, string $sessionId, int $timeCreated, array $questions, string $status = 'completed'): array
{
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $timeCreated,
        'time_updated' => $timeCreated,
        'data' => json_encode([
            'type' => 'tool',
            'tool' => 'question',
            'callID' => 'call_'.$id,
            'state' => [
                'status' => $status,
                'input' => ['questions' => $questions],
            ],
        ]),
    ];
}

test('hasTerminalError returns true when the most recent part is a tool error', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_terminal', 'title' => 'Terminal', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
        ],
        parts: [
            opencodeTextPart('part_1', 'ses_terminal', 1500),
            opencodeToolPart('part_2', 'ses_terminal', 2000, 'error', 'Query failed: boom'),
        ],
    ));

    expect($store->hasTerminalError('ses_terminal'))->toBeTrue();
});

test('hasTerminalError returns false when a newer part follows the error', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_recovered', 'title' => 'Recovered', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 4000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_recovered', 1500, 'error', 'Query failed: boom'),
            opencodeToolPart('part_2', 'ses_recovered', 2500, 'completed'),
        ],
    ));

    expect($store->hasTerminalError('ses_recovered'))->toBeFalse();
});

test('hasTerminalError returns false when the most recent part is an aborted tool call', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_aborted', 'title' => 'Aborted', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_aborted', 1500, 'error', 'Tool execution aborted'),
        ],
    ));

    expect($store->hasTerminalError('ses_aborted'))->toBeFalse();
});

test('hasTerminalError returns false when the most recent part is a rule-blocked tool call', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_blocked', 'title' => 'Blocked', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_blocked', 1500, 'error', 'The user has specified a rule which prevents you from using this specific tool call.'),
        ],
    ));

    expect($store->hasTerminalError('ses_blocked'))->toBeFalse();
});

test('hasTerminalError returns false when the most recent part is not an error', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_done', 'title' => 'Done', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_done', 1500, 'error', 'Query failed: boom'),
            opencodeTextPart('part_2', 'ses_done', 2500),
        ],
    ));

    expect($store->hasTerminalError('ses_done'))->toBeFalse();
});

test('hasTerminalError breaks time_created ties deterministically by rowid', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_tie', 'title' => 'Tie', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_tie', 2000, 'error', 'Tool execution aborted'),
            opencodeToolPart('part_2', 'ses_tie', 2000, 'error', 'Query failed: boom'),
        ],
    ));

    expect($store->hasTerminalError('ses_tie'))->toBeTrue();
});

test('hasTerminalError returns false when the session has no parts or does not exist', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_empty', 'title' => 'Empty', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
    ));

    expect($store->hasTerminalError('ses_empty'))->toBeFalse();
    expect($store->hasTerminalError('ses_unknown'))->toBeFalse();
});

afterEach(function () {
    OpencodeStoreFixture::cleanup();
});

test('activeSessions returns only sessions that are not archived', function () {
    $today = now()->getTimestampMs();

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_active', 'title' => 'Active', 'directory' => '/projects/a', 'time_created' => $today, 'time_updated' => $today, 'time_archived' => null],
            ['id' => 'ses_archived', 'title' => 'Archived', 'directory' => '/projects/b', 'time_created' => $today, 'time_updated' => $today, 'time_archived' => $today],
        ],
    ));

    $sessions = $store->activeSessions();

    expect($sessions)->toHaveCount(1);
    expect($sessions[0]['id'])->toBe('ses_active');
    expect($sessions[0]['title'])->toBe('Active');
    expect($sessions[0]['directory'])->toBe('/projects/a');
    expect($sessions[0]['parent_id'])->toBeNull();
});

test('activeSessions exposes the parent_id marker for sub-agent sessions', function () {
    $today = now()->getTimestampMs();

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_tui', 'title' => 'TUI', 'directory' => '/projects/a', 'time_created' => $today, 'time_updated' => $today, 'time_archived' => null],
            ['id' => 'ses_sub', 'title' => 'Sub', 'directory' => '/projects/a', 'parent_id' => 'ses_tui', 'time_created' => $today, 'time_updated' => $today, 'time_archived' => null],
        ],
    ));

    $sessions = $store->activeSessions();

    expect($sessions)->toHaveCount(2);

    $byId = collect($sessions)->keyBy('id');

    expect($byId['ses_tui']['parent_id'])->toBeNull();
    expect($byId['ses_sub']['parent_id'])->toBe('ses_tui');
});

test('activeSessions returns time_updated in epoch milliseconds ordered by time_updated desc', function () {
    $early = now()->startOfDay()->getTimestampMs();
    $late = now()->getTimestampMs();

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_old', 'title' => 'Old', 'directory' => '/projects/a', 'time_created' => $early, 'time_updated' => $early, 'time_archived' => null],
            ['id' => 'ses_new', 'title' => 'New', 'directory' => '/projects/b', 'time_created' => $late, 'time_updated' => $late, 'time_archived' => null],
        ],
    ));

    $sessions = $store->activeSessions();

    expect(array_column($sessions, 'id'))->toBe(['ses_new', 'ses_old']);
    expect($sessions[0]['time_updated'])->toBeInt();
    expect($sessions[0]['time_updated'])->toBe($late);
    expect($sessions[0]['directory'])->toBe('/projects/b');
});

test('activeSessions without a since watermark returns every non-archived session', function () {
    $now = now()->getTimestampMs();
    $twoDaysAgo = now()->subDays(2)->getTimestampMs();

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_today', 'title' => 'Today', 'directory' => '/projects/a', 'time_created' => $now, 'time_updated' => $now, 'time_archived' => null],
            ['id' => 'ses_stale', 'title' => 'Stale', 'directory' => '/projects/b', 'time_created' => $twoDaysAgo, 'time_updated' => $twoDaysAgo, 'time_archived' => null],
        ],
    ));

    $sessions = $store->activeSessions();

    expect(array_column($sessions, 'id'))->toBe(['ses_today', 'ses_stale']);
});

test('activeSessions with a since watermark only returns sessions updated at or after it', function () {
    $since = now()->subHours(3)->getTimestampMs();
    $old = $since - 1000;
    $boundary = $since;
    $fresh = $since + 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_old', 'title' => 'Old', 'directory' => '/projects/a', 'time_created' => $old, 'time_updated' => $old, 'time_archived' => null],
            ['id' => 'ses_boundary', 'title' => 'Boundary', 'directory' => '/projects/b', 'time_created' => $boundary, 'time_updated' => $boundary, 'time_archived' => null],
            ['id' => 'ses_fresh', 'title' => 'Fresh', 'directory' => '/projects/c', 'time_created' => $fresh, 'time_updated' => $fresh, 'time_archived' => null],
            ['id' => 'ses_archived', 'title' => 'Archived', 'directory' => '/projects/d', 'time_created' => $fresh, 'time_updated' => $fresh, 'time_archived' => $fresh],
        ],
    ));

    $sessions = $store->activeSessions($since);

    expect(array_column($sessions, 'id'))->toBe(['ses_fresh', 'ses_boundary']);
    expect($sessions[0]['time_updated'])->toBe($fresh);
});

test('activeSessions with a since watermark returns a session whose most recent part is fresh even when session.time_updated is stale', function () {
    $since = now()->subHours(1)->getTimestampMs();
    $staleUpdated = $since - 1000;
    $freshPart = $since + 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_part_fresh', 'title' => 'Part Fresh', 'directory' => '/projects/a', 'time_created' => $staleUpdated, 'time_updated' => $staleUpdated, 'time_archived' => null],
        ],
        parts: [
            opencodeTextPart('part_1', 'ses_part_fresh', $freshPart),
        ],
    ));

    $sessions = $store->activeSessions($since);

    expect(array_column($sessions, 'id'))->toBe(['ses_part_fresh']);
    expect($sessions[0]['time_updated'])->toBe($freshPart);
});

test('activeSessions with a since watermark still returns a session whose session.time_updated is fresh', function () {
    $since = now()->subHours(1)->getTimestampMs();
    $freshUpdated = $since + 1000;
    $stalePart = $since - 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_session_fresh', 'title' => 'Session Fresh', 'directory' => '/projects/a', 'time_created' => $stalePart, 'time_updated' => $freshUpdated, 'time_archived' => null],
        ],
        parts: [
            opencodeTextPart('part_1', 'ses_session_fresh', $stalePart),
        ],
    ));

    $sessions = $store->activeSessions($since);

    expect(array_column($sessions, 'id'))->toBe(['ses_session_fresh']);
    expect($sessions[0]['time_updated'])->toBe($freshUpdated);
});

test('activeSessions with a since watermark excludes a session whose session.time_updated and parts are all stale', function () {
    $since = now()->subHours(1)->getTimestampMs();
    $stale = $since - 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_stale', 'title' => 'Stale', 'directory' => '/projects/a', 'time_created' => $stale, 'time_updated' => $stale, 'time_archived' => null],
        ],
        parts: [
            opencodeTextPart('part_1', 'ses_stale', $stale - 500),
            opencodeToolPart('part_2', 'ses_stale', $stale, 'completed'),
        ],
    ));

    expect($store->activeSessions($since))->toBe([]);
});

test('activeSessions with a since watermark falls back to session.time_updated when a session has no parts', function () {
    $since = now()->subHours(1)->getTimestampMs();
    $boundary = $since;
    $old = $since - 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_no_parts', 'title' => 'No Parts', 'directory' => '/projects/a', 'time_created' => $old, 'time_updated' => $boundary, 'time_archived' => null],
            ['id' => 'ses_no_parts_stale', 'title' => 'No Parts Stale', 'directory' => '/projects/b', 'time_created' => $old, 'time_updated' => $old, 'time_archived' => null],
        ],
    ));

    $sessions = $store->activeSessions($since);

    expect(array_column($sessions, 'id'))->toBe(['ses_no_parts']);
    expect($sessions[0]['time_updated'])->toBe($boundary);
});

test('activeSessions orders a session with a fresh part above a session with a stale time_updated', function () {
    $since = now()->subHours(1)->getTimestampMs();
    $stale = $since - 1000;
    $freshPart = $since + 2000;
    $freshUpdated = $since + 1000;

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_stale_updated', 'title' => 'Stale Updated', 'directory' => '/projects/a', 'time_created' => $stale, 'time_updated' => $stale, 'time_archived' => null],
            ['id' => 'ses_updated_fresh', 'title' => 'Updated Fresh', 'directory' => '/projects/b', 'time_created' => $stale, 'time_updated' => $freshUpdated, 'time_archived' => null],
        ],
        parts: [
            opencodeTextPart('part_1', 'ses_stale_updated', $freshPart),
        ],
    ));

    $sessions = $store->activeSessions($since);

    expect(array_column($sessions, 'id'))->toBe(['ses_stale_updated', 'ses_updated_fresh']);
    expect($sessions[0]['time_updated'])->toBe($freshPart);
    expect($sessions[1]['time_updated'])->toBe($freshUpdated);
});

test('searchSessions matches sessions by title substring', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_match', 'title' => 'Refactor the auth module', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_other', 'title' => 'Build the dashboard', 'directory' => '/projects/two', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $sessions = $store->searchSessions('auth');

    expect(array_column($sessions, 'id'))->toBe(['ses_match']);
    expect($sessions[0]['title'])->toBe('Refactor the auth module');
});

test('searchSessions matches sessions by directory substring', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_dir', 'title' => 'Wiring', 'directory' => '/home/junior/Projects/DevWarden', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_other', 'title' => 'Wiring', 'directory' => '/home/junior/Projects/Other', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $sessions = $store->searchSessions('DevWarden');

    expect(array_column($sessions, 'id'))->toBe(['ses_dir']);
});

test('searchSessions includes archived sessions', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_archived', 'title' => 'Old archived wiring', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => 2000],
        ],
    ));

    $sessions = $store->searchSessions('wiring');

    expect($sessions)->toHaveCount(1);
    expect($sessions[0]['id'])->toBe('ses_archived');
    expect($sessions[0]['time_archived'])->toBe(2000);
});

test('searchSessions orders by time_updated desc and respects the limit', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Task one', 'directory' => '/p/1', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
            ['id' => 'ses_2', 'title' => 'Task two', 'directory' => '/p/2', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_3', 'title' => 'Task three', 'directory' => '/p/3', 'time_created' => 1000, 'time_updated' => 1000, 'time_archived' => null],
        ],
    ));

    $sessions = $store->searchSessions('Task', limit: 2);

    expect(array_column($sessions, 'id'))->toBe(['ses_1', 'ses_2']);
});

test('searchSessions caps the limit at 25', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: array_map(
            fn (int $index): array => ['id' => "ses_{$index}", 'title' => "Task {$index}", 'directory' => '/p', 'time_created' => 1000, 'time_updated' => 1000 + $index, 'time_archived' => null],
            range(1, 30),
        ),
    ));

    $sessions = $store->searchSessions('Task', limit: 100);

    expect($sessions)->toHaveCount(25);
});

test('searchSessions escapes LIKE wildcards in the query', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_percent', 'title' => 'Fix the 100% bug', 'directory' => '/p/1', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_plain', 'title' => 'Fix the 100x bug', 'directory' => '/p/2', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $sessions = $store->searchSessions('100%');

    expect(array_column($sessions, 'id'))->toBe(['ses_percent']);
});

test('searchSessions treats underscores and backslashes literally', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_under', 'title' => 'Fix_the_bug', 'directory' => '/p/1', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
            ['id' => 'ses_hyphen', 'title' => 'Fix-the-bug', 'directory' => '/p/2', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    $sessions = $store->searchSessions('Fix_the_bug');

    expect(array_column($sessions, 'id'))->toBe(['ses_under']);
});

test('searchSessions returns an empty array when nothing matches', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_other', 'title' => 'Build the dashboard', 'directory' => '/projects/two', 'time_created' => 1000, 'time_updated' => 1500, 'time_archived' => null],
        ],
    ));

    expect($store->searchSessions('nothing matches'))->toBe([]);
});

test('sessionState flags a session with a running part', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_running', 'title' => 'Running', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'part_1', 'session_id' => 'ses_running', 'time_created' => 1500, 'time_updated' => 2000, 'data' => json_encode(['type' => 'tool', 'state' => ['status' => 'running']])],
        ],
    ));

    $state = $store->sessionState('ses_running');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['has_any_part'])->toBeTrue();
    expect($state['has_error_part'])->toBeFalse();
    expect($state['title'])->toBe('Running');
    expect($state['directory'])->toBe('/projects/a');
    expect($state['time_updated'])->toBe(2000);
});

test('sessionState flags a session with an errored tool part', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_error', 'title' => 'Error', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'part_1', 'session_id' => 'ses_error', 'time_created' => 1500, 'time_updated' => 2000, 'data' => json_encode(['type' => 'tool', 'state' => ['status' => 'error']])],
        ],
    ));

    $state = $store->sessionState('ses_error');

    expect($state['has_error_part'])->toBeTrue();
    expect($state['has_any_part'])->toBeTrue();
    expect($state['has_running_part'])->toBeFalse();
});

test('sessionState does not flag completed or non-tool parts as running or errored', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_done', 'title' => 'Done', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'part_1', 'session_id' => 'ses_done', 'time_created' => 1500, 'time_updated' => 1600, 'data' => json_encode(['type' => 'tool', 'state' => ['status' => 'completed']])],
            ['id' => 'part_2', 'session_id' => 'ses_done', 'time_created' => 1700, 'time_updated' => 1800, 'data' => json_encode(['type' => 'text', 'state' => ['status' => 'error']])],
        ],
    ));

    $state = $store->sessionState('ses_done');

    expect($state['has_any_part'])->toBeTrue();
    expect($state['has_running_part'])->toBeFalse();
    expect($state['has_error_part'])->toBeFalse();
});

test('sessionState reports no parts when the session has none', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_empty', 'title' => 'Empty', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
    ));

    $state = $store->sessionState('ses_empty');

    expect($state['has_any_part'])->toBeFalse();
    expect($state['has_running_part'])->toBeFalse();
    expect($state['has_error_part'])->toBeFalse();
    expect($state['title'])->toBe('Empty');
});

test('sessionState treats a running part before the last step-finish as stale', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_zombie', 'title' => 'Zombie', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 5000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_zombie', 1500, 'running'),
            opencodeStepFinishPart('part_2', 'ses_zombie', 2500),
            opencodeStepFinishPart('part_3', 'ses_zombie', 4000),
        ],
    ));

    $state = $store->sessionState('ses_zombie');

    expect($state['has_running_part'])->toBeFalse();
    expect($state['last_turn_tool'])->toBeNull();
    expect($state['has_any_part'])->toBeTrue();
});

test('sessionState treats a running part after the last step-finish as live', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_live', 'title' => 'Live', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeStepFinishPart('part_1', 'ses_live', 2000),
            opencodeStepFinishPart('part_2', 'ses_live', 3000),
            opencodeToolPart('part_3', 'ses_live', 4500, 'running'),
        ],
    ));

    $state = $store->sessionState('ses_live');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['last_turn_tool'])->toBe('bash');
});

test('sessionState reports a question as the live running tool', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_question', 'title' => 'Question', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeStepFinishPart('part_1', 'ses_question', 3000),
            opencodeToolPart('part_2', 'ses_question', 5000, 'running', tool: 'question'),
        ],
    ));

    $state = $store->sessionState('ses_question');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['last_turn_tool'])->toBe('question');
});

test('sessionState picks the most recent live running tool', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_multi', 'title' => 'Multi', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeStepFinishPart('part_1', 'ses_multi', 2000),
            opencodeToolPart('part_2', 'ses_multi', 3500, 'running', tool: 'read'),
            opencodeToolPart('part_3', 'ses_multi', 4500, 'running', tool: 'bash'),
        ],
    ));

    $state = $store->sessionState('ses_multi');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['last_turn_tool'])->toBe('bash');
});

test('sessionState ignores stale running parts when a live one exists', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_mixed', 'title' => 'Mixed', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_mixed', 1500, 'running', tool: 'task'),
            opencodeStepFinishPart('part_2', 'ses_mixed', 3000),
            opencodeToolPart('part_3', 'ses_mixed', 5000, 'running', tool: 'question'),
        ],
    ));

    $state = $store->sessionState('ses_mixed');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['last_turn_tool'])->toBe('question');
});

test('sessionState treats a running part closed by a newer step-finish as stale', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_closed', 'title' => 'Closed', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_closed', 4000, 'running'),
            opencodeStepFinishPart('part_2', 'ses_closed', 5000),
        ],
    ));

    $state = $store->sessionState('ses_closed');

    expect($state['has_running_part'])->toBeFalse();
    expect($state['last_turn_tool'])->toBeNull();
});

test('sessionState treats a running part without any step-finish as live', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_no_finish', 'title' => 'No Finish', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_no_finish', 4500, 'running', tool: 'bash'),
        ],
    ));

    $state = $store->sessionState('ses_no_finish');

    expect($state['has_running_part'])->toBeTrue();
    expect($state['last_turn_tool'])->toBe('bash');
});

test('sessionState returns an empty state for an unknown session id', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_known', 'title' => 'Known', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
    ));

    expect($store->sessionState('ses_unknown'))->toBe([
        'title' => null,
        'directory' => null,
        'time_updated' => null,
        'has_running_part' => false,
        'has_error_part' => false,
        'has_any_part' => false,
        'last_turn_tool' => null,
    ]);
});

test('questionOptions extracts questions and options from the most recent question part', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_question', 'title' => 'Question', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: [
            opencodeQuestionPart('part_1', 'ses_question', 3000, [
                ['question' => 'Stale question', 'options' => [['label' => 'Stale', 'description' => 'old']]],
            ]),
            opencodeQuestionPart('part_2', 'ses_question', 5000, [
                ['question' => 'Migration approach?', 'options' => [
                    ['label' => 'Fresh migration', 'description' => 'Recreate the schema from scratch'],
                    ['label' => 'Incremental'],
                ]],
                ['question' => 'Rollout?', 'options' => [
                    ['label' => 'All at once'],
                ]],
            ]),
        ],
    ));

    expect($store->questionOptions('ses_question'))->toBe([
        [
            'question' => 'Migration approach?',
            'options' => [
                ['label' => 'Fresh migration', 'description' => 'Recreate the schema from scratch'],
                ['label' => 'Incremental', 'description' => null],
            ],
        ],
        [
            'question' => 'Rollout?',
            'options' => [
                ['label' => 'All at once', 'description' => null],
            ],
        ],
    ]);
});

test('questionOptions returns an empty array when the session has no question part', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_plain', 'title' => 'Plain', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeToolPart('part_1', 'ses_plain', 1500, 'completed'),
        ],
    ));

    expect($store->questionOptions('ses_plain'))->toBe([]);
    expect($store->questionOptions('ses_unknown'))->toBe([]);
});

test('questionOptions returns an empty array for an invalid or empty question payload', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_bad', 'title' => 'Bad', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 3000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'part_1', 'session_id' => 'ses_bad', 'time_created' => 1500, 'time_updated' => 1500, 'data' => json_encode(['type' => 'tool', 'tool' => 'question', 'state' => ['status' => 'completed', 'input' => ['questions' => 'not an array']]])],
            ['id' => 'part_2', 'session_id' => 'ses_bad', 'time_created' => 2500, 'time_updated' => 2500, 'data' => '{not json'],
        ],
    ));

    expect($store->questionOptions('ses_bad'))->toBe([]);
});

test('questionOptions skips questions without valid options and normalizes labels', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_q', 'title' => 'Question', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeQuestionPart('part_1', 'ses_q', 1500, [
                ['question' => 'No options', 'options' => []],
                ['question' => 'Bad options', 'options' => [['foo' => 'bar'], ['label' => 42, 'description' => 7]]],
                ['question' => 'Kept', 'options' => [['label' => 'Go']]],
            ]),
        ],
    ));

    expect($store->questionOptions('ses_q'))->toBe([
        ['question' => 'Bad options', 'options' => [['label' => '42', 'description' => null]]],
        ['question' => 'Kept', 'options' => [['label' => 'Go', 'description' => null]]],
    ]);
});

test('questionOptions falls back to the header field when the question text is missing', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_header', 'title' => 'Header', 'directory' => '/projects/a', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            opencodeQuestionPart('part_1', 'ses_header', 1500, [
                ['header' => 'Choose an approach', 'options' => [['label' => 'Fresh', 'description' => 'Recreate the schema']]],
                ['question' => 'Real question', 'header' => 'Ignored header', 'options' => [['label' => 'Real option']]],
                ['options' => [['label' => 'No text at all']]],
            ]),
        ],
    ));

    expect($store->questionOptions('ses_header'))->toBe([
        ['question' => 'Choose an approach', 'options' => [['label' => 'Fresh', 'description' => 'Recreate the schema']]],
        ['question' => 'Real question', 'options' => [['label' => 'Real option', 'description' => null]]],
        ['question' => '', 'options' => [['label' => 'No text at all', 'description' => null]]],
    ]);
});

test('questionOptions returns an empty array without throwing when the database is missing', function () {
    $store = new OpencodeSessionStore(sys_get_temp_dir().'/opencode_store_missing_'.uniqid().'.db');

    expect($store->questionOptions('ses_unknown'))->toBe([]);
});

test('returns empty results without throwing when the database file does not exist', function () {
    $store = new OpencodeSessionStore(sys_get_temp_dir().'/opencode_store_missing_'.uniqid().'.db');

    expect($store->activeSessions())->toBe([]);
    expect($store->searchSessions('anything'))->toBe([]);
    expect($store->hasTerminalError('ses_unknown'))->toBeFalse();
    expect($store->sessionState('ses_unknown'))->toBe([
        'title' => null,
        'directory' => null,
        'time_updated' => null,
        'has_running_part' => false,
        'has_error_part' => false,
        'has_any_part' => false,
        'last_turn_tool' => null,
    ]);
});
