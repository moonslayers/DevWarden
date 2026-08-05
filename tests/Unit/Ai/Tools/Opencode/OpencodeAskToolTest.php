<?php

use App\Ai\Tools\Opencode\OpencodeAskTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

test('replies to the active workflow session when one exists', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123, 'question' => 'What is the current state?']));

    expect($result)->toBe('Reply from opencode.');

    $call = $manager->lastCall('reply');

    expect($call['sessionId'])->toBe('ses_abc')
        ->and($call['directory'])->toBe($workflow->project_path)
        ->and($call['prompt'])->toBe('What is the current state?');
});

test('opens a fresh session with the given directory when no active workflow exists', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'question' => 'Summarize this repo',
        'directory' => '/home/junior/Projects/DevWarden',
    ]));

    expect($result)->toBe('Answer from opencode.');

    $call = $manager->lastCall('ask');

    expect($call['directory'])->toBe('/home/junior/Projects/DevWarden')
        ->and($call['prompt'])->toBe('Summarize this repo');
});

test('prefers an explicit directory over the active workflow project', function () {
    OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $tool->handle(new Request([
        'chat_id' => 123,
        'question' => 'Question',
        'directory' => '/home/junior/Projects/Other',
    ]));

    expect($manager->lastCall('reply')['directory'])->toBe('/home/junior/Projects/Other');
});

test('rejects an empty question', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request(['question' => '   ']));

    expect($result)->toContain('missing required "question"')
        ->and($manager->calls)->toBeEmpty();
});

test('errors when there is no directory and no active workflow', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request(['question' => 'Where am I?']));

    expect($result)->toContain('no directory given and no active workflow')
        ->and($manager->calls)->toBeEmpty();
});

test('returns a readable error when opencode fails', function () {
    $manager = new FakeOpencodeSessionManager;
    $manager->error = new OpencodeException('boom');
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'question' => 'Question',
        'directory' => '/home/junior/Projects/DevWarden',
    ]));

    expect($result)->toContain('opencode failed')->toContain('boom');
});
