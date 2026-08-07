<?php

use App\Ai\Tools\Opencode\ReadSessionTool;
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

test('reads the last messages in ascending chronological order with role and text', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Refactor the auth module', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        messages: array_map(
            fn (int $ms) => ['id' => 'm'.$ms, 'session_id' => 'ses_1', 'time_created' => $ms, 'time_updated' => $ms, 'data' => json_encode(['role' => 'user'])],
            [1000, 2000, 3000, 4000, 5000, 6000],
        ),
        parts: array_map(
            fn (int $ms) => ['id' => 'p'.$ms, 'message_id' => 'm'.$ms, 'session_id' => 'ses_1', 'time_created' => $ms, 'time_updated' => $ms, 'data' => json_encode(['type' => 'text', 'text' => 'msg '.substr((string) $ms, 0, 1)])],
            [1000, 2000, 3000, 4000, 5000, 6000],
        ),
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1']));

    expect($result)
        ->toContain('Session: ses_1')
        ->toContain('Title: Refactor the auth module')
        ->toContain('Directory: /projects/one')
        ->toContain('Últimos 5 mensajes:')
        ->toContain('1. [user] message: msg 2')
        ->toContain('2. [user] message: msg 3')
        ->toContain('3. [user] message: msg 4')
        ->toContain('4. [user] message: msg 5')
        ->toContain('5. [user] message: msg 6')
        ->not->toContain('msg 1');
});

test('reads the first messages when the direction is first', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Refactor the auth module', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 6000, 'time_archived' => null],
        ],
        parts: array_map(
            fn (int $ms) => ['id' => 'p'.$ms, 'session_id' => 'ses_1', 'time_created' => $ms, 'time_updated' => $ms, 'data' => json_encode(['type' => 'text', 'text' => 'msg '.substr((string) $ms, 0, 1)])],
            [1000, 2000, 3000, 4000, 5000, 6000],
        ),
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1', 'direction' => 'first', 'limit' => 3]));

    expect($result)
        ->toContain('Primeros 3 mensajes:')
        ->toContain('1. [assistant] message: msg 1')
        ->toContain('3. [assistant] message: msg 3')
        ->not->toContain('msg 4');
});

test('shows tool calls with their name, status, input and output', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Wiring check', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'p1', 'session_id' => 'ses_1', 'time_created' => 2000, 'time_updated' => 2000, 'data' => json_encode(['type' => 'tool', 'tool' => 'bash', 'state' => ['status' => 'completed', 'input' => 'ls -la', 'output' => 'total 8']])],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1']));

    expect($result)
        ->toContain('tool: bash')
        ->toContain('[completed]')
        ->toContain('input: ls -la')
        ->toContain('output: total 8');
});

test('shows the invoked sub-agent with its name and sub-session id', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Big refactor', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 4000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'sub_agent', 'session_id' => 'ses_sub', 'time_created' => 3000, 'time_updated' => 3000, 'data' => json_encode(['type' => 'agent', 'name' => 'builder'])],
            ['id' => 'task_part', 'session_id' => 'ses_1', 'time_created' => 4000, 'time_updated' => 4000, 'data' => json_encode(['type' => 'tool', 'tool' => 'task', 'state' => ['status' => 'completed', 'metadata' => ['sessionId' => 'ses_sub'], 'input' => 'Build the thing']])],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1']));

    expect($result)
        ->toContain('tool: task')
        ->toContain('sub-agent: builder')
        ->toContain('session ses_sub')
        ->toContain('input: Build the thing');
});

test('resolves the session by query hint when the session id is missing', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Refactor the auth module', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'p1', 'session_id' => 'ses_1', 'time_created' => 2000, 'time_updated' => 2000, 'data' => json_encode(['type' => 'text', 'text' => 'First message'])],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['query' => 'auth module']));

    expect($result)->toContain('Session: ses_1');
});

test('shows the working status with a question note when the session is waiting for input', function () {
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_1']);

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Interactive thing', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 5000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'p1', 'session_id' => 'ses_1', 'time_created' => 5000, 'time_updated' => 5000, 'data' => json_encode(['type' => 'tool', 'tool' => 'question', 'state' => ['status' => 'running']])],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1']));

    expect($result)
        ->toContain('Status: working')
        ->toContain('esperando respuesta')
        ->toContain('terminada/inactiva');
});

test('shows the idle status when the session has no running part', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Finished thing', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
        parts: [
            ['id' => 'p1', 'session_id' => 'ses_1', 'time_created' => 2000, 'time_updated' => 2000, 'data' => json_encode(['type' => 'text', 'text' => 'Done.'])],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1']));

    expect($result)->toContain('Status: idle');
});

test('returns a readable error when both session_id and query are missing', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create());

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request([]));

    expect($result)->toContain('Error: missing "session_id" and "query"');
});

test('returns a readable error when the session is not found', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create());

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_ghost']));

    expect($result)->toContain('Error: opencode session [ses_ghost] not found');
});

test('returns a readable error when the query hint matches no session', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Unrelated thing', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
        ],
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['query' => 'nothing matches']));

    expect($result)->toContain('Error: no opencode session found for query');
});

test('caps the total output keeping the tail for the last direction and the head for the first', function () {
    $parts = [];

    foreach (range(1, 5) as $index) {
        $parts[] = ['id' => 'p'.$index, 'session_id' => 'ses_1', 'time_created' => $index * 1000, 'time_updated' => $index * 1000, 'data' => json_encode(['type' => 'text', 'text' => 'SEED-'.$index.' '.str_repeat('m', 1900)])];
    }

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Huge session', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 5000, 'time_archived' => null],
        ],
        parts: $parts,
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $last = (string) $tool->handle(new Request(['session_id' => 'ses_1', 'direction' => 'last']));
    $first = (string) $tool->handle(new Request(['session_id' => 'ses_1', 'direction' => 'first']));

    expect(mb_strlen($last))->toBeLessThanOrEqual(6000);

    expect($last)
        ->toStartWith('…')
        ->toContain('SEED-5')
        ->not->toContain('SEED-1');

    expect(mb_strlen($first))->toBeLessThanOrEqual(6000);

    expect($first)
        ->toContain('Primeros 5 mensajes:')
        ->toContain('SEED-1')
        ->not->toContain('SEED-5');
});

test('clamps the limit to the maximum of 20', function () {
    $parts = [];

    foreach (range(1, 25) as $index) {
        $parts[] = ['id' => 'p'.$index, 'session_id' => 'ses_1', 'time_created' => $index * 1000, 'time_updated' => $index * 1000, 'data' => json_encode(['type' => 'text', 'text' => 'part '.$index])];
    }

    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(
        sessions: [
            ['id' => 'ses_1', 'title' => 'Long session', 'directory' => '/projects/one', 'time_created' => 1000, 'time_updated' => 25000, 'time_archived' => null],
        ],
        parts: $parts,
    ));

    $tool = new ReadSessionTool($this->manager, $store);

    $result = (string) $tool->handle(new Request(['session_id' => 'ses_1', 'limit' => 100]));

    expect($result)
        ->toContain('Últimos 20 mensajes:')
        ->toContain('20. [assistant] message: part 25')
        ->not->toContain('21.');
});
