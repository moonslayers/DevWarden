<?php

use App\Ai\Tools\Opencode\OpencodeAskTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;
use Tests\Support\OpencodeStoreFixture;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    OpencodeWorkflowContext::clear();
    OpencodeStoreFixture::cleanup();
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

test('sends a prompt to an external session by session_id in the background', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_ext_001',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'session_id' => 'ses_ext_001',
        'question' => 'Execute step 3 now',
    ]));

    expect($result)->toContain('Prompt sent to session')->toContain('ses_ext_001');

    $call = $manager->lastCall('advanceSession');

    expect($call['sessionId'])->toBe('ses_ext_001')
        ->and($call['directory'])->toBe('/home/junior/Projects/DevWarden')
        ->and($call['prompt'])->toBe('Execute step 3 now');
});

test('waits for the session answer when blocking is true', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_ext_001',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $manager->replyMessage = 'Here is the answer.';
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'session_id' => 'ses_ext_001',
        'question' => 'What did you find?',
        'blocking' => true,
    ]));

    expect($result)->toBe('Here is the answer.');

    $call = $manager->lastCall('reply');

    expect($call['sessionId'])->toBe('ses_ext_001')
        ->and($call['directory'])->toBe('/home/junior/Projects/DevWarden')
        ->and($call['prompt'])->toBe('What did you find?');
});

test('resolves the session by query through the tracked watch', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_query_001',
        'title' => 'Refactor the auth module',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $tool->handle(new Request([
        'query' => 'auth module',
        'question' => 'Send me the diff',
    ]));

    $call = $manager->lastCall('advanceSession');

    expect($call['sessionId'])->toBe('ses_query_001')
        ->and($call['directory'])->toBe('/home/junior/Projects/DevWarden');
});

test('resolves an untracked session by query through the store', function () {
    $store = new OpencodeSessionStore(OpencodeStoreFixture::create(sessions: [
        ['id' => 'ses_untracked', 'title' => 'Legacy wiring cleanup', 'directory' => '/home/junior/Projects/Legacy', 'time_created' => 1000, 'time_updated' => 2000, 'time_archived' => null],
    ]));

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager, $store);

    $result = $tool->handle(new Request([
        'query' => 'Legacy wiring',
        'question' => 'Keep going',
    ]));

    expect($result)->toContain('Prompt sent to session')->toContain('ses_untracked');

    $call = $manager->lastCall('advanceSession');

    expect($call['sessionId'])->toBe('ses_untracked')
        ->and($call['directory'])->toBe('/home/junior/Projects/Legacy');
});

test('errors when a query resolves no session', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'query' => 'nothing matches this',
        'question' => 'Hi?',
    ]));

    expect($result)->toContain('no opencode session matches query')
        ->and($manager->calls)->toBeEmpty();
});

test('errors when the resolved session has no directory', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'session_id' => 'ses_ghost',
        'question' => 'Where are you?',
    ]));

    expect($result)->toContain('could not determine the project directory')
        ->and($manager->calls)->toBeEmpty();
});

test('returns a readable error when dispatching to a session fails', function () {
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_ext_001',
        'project_path' => '/home/junior/Projects/DevWarden',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $manager->error = new OpencodeException('transport down');
    $tool = new OpencodeAskTool($manager);

    $result = $tool->handle(new Request([
        'session_id' => 'ses_ext_001',
        'question' => 'Go',
    ]));

    expect($result)->toContain('opencode failed')->toContain('transport down');
});

test('schema exposes session_id, query and blocking arguments', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAskTool($manager);

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties'])->toHaveKeys(['session_id', 'query', 'blocking'])
        ->and($schema['properties']['session_id']['type'])->toBe('string')
        ->and($schema['properties']['query']['type'])->toBe('string')
        ->and($schema['properties']['blocking']['type'])->toBe('boolean')
        ->and($schema['properties']['blocking']['default'])->toBeFalse();
});
