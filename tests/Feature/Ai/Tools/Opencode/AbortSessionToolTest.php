<?php

use App\Ai\Tools\Opencode\AbortSessionTool;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = new FakeOpencodeSessionManager;
    $this->tool = new AbortSessionTool($this->manager);
});

test('refuses to abort without explicit confirmation', function () {
    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_001',
        'directory' => '/home/junior/Projects/DevWarden',
    ]));

    expect($result)->toContain('confirmation required')
        ->and($this->manager->called('abort'))->toBeFalse();
});

test('aborts the session when confirmed with an explicit directory', function () {
    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_001',
        'directory' => '/home/junior/Projects/DevWarden',
        'confirm' => true,
    ]));

    expect($result)->toContain('ses_abort_001')
        ->toContain('aborted')
        ->and($this->manager->called('abort'))->toBeTrue();

    $call = $this->manager->lastCall('abort');

    expect($call['sessionId'])->toBe('ses_abort_001')
        ->and($call['directory'])->toBe('/home/junior/Projects/DevWarden');
});

test('abort resolves the directory from the tracked watch when not passed', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_abort_002',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_002',
        'confirm' => true,
    ]));

    expect($result)->toContain('aborted')
        ->and($this->manager->lastCall('abort')['directory'])->toBe('/home/junior/Projects/DevWarden');
});

test('abort resolves the directory from the workflow when no watch exists', function () {
    OpencodeWorkflow::factory()->create([
        'opencode_session_id' => 'ses_abort_003',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_003',
        'confirm' => true,
    ]));

    expect($result)->toContain('aborted')
        ->and($this->manager->lastCall('abort')['directory'])->toBe('/home/junior/Projects/DevWarden');
});

test('abort returns a readable error when the directory cannot be determined', function () {
    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_004',
        'confirm' => true,
    ]));

    expect($result)->toContain('could not determine the project directory')
        ->and($this->manager->called('abort'))->toBeFalse();
});

test('abort surfaces opencode failures as a readable error', function () {
    $this->manager->error = new OpencodeException('session not found');

    $result = $this->tool->handle(new Request([
        'session_id' => 'ses_abort_005',
        'directory' => '/home/junior/Projects/DevWarden',
        'confirm' => true,
    ]));

    expect($result)->toContain('Error: opencode failed')
        ->toContain('session not found');
});

test('abort returns a readable error when no session id or query is given', function () {
    $result = $this->tool->handle(new Request(['confirm' => true]));

    expect($result)->toContain('Error: missing "session_id"')
        ->and($this->manager->called('abort'))->toBeFalse();
});
