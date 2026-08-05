<?php

use App\Ai\Tools\Opencode\OpencodeAdvanceWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

test('advances the active workflow to the next step in the sequence', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toBeString()
        ->toContain('Advanced workflow #'.$workflow->id)
        ->toContain('"plan"')
        ->toContain('(2/6)');

    expect($workflow->fresh()->current_step)->toBe('plan');

    expect($workflow->steps)->toHaveCount(1);
    expect($workflow->steps->first()->step_name)->toBe('plan')
        ->and($workflow->steps->first()->status->value)->toBe('running');

    $call = $manager->lastCall('advanceSession');

    expect($call['sessionId'])->toBe('ses_abc')
        ->and($call['directory'])->toBe($workflow->project_path)
        ->and($call['prompt'])->toContain('plan')
        ->and($call['prompt'])->not->toContain('Add a login page')
        ->and($call['opts'])->toHaveKey('agent', 'orchestrator');
});

test('errors when there is no active workflow', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('no active workflow found');

    expect(OpencodeWorkflowStep::count())->toBe(0)
        ->and($manager->called('advanceSession'))->toBeFalse();
});

test('errors when the workflow has no opencode session', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => null,
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('no opencode session');

    expect($workflow->fresh()->current_step)->toBe('context-gather')
        ->and($manager->called('advanceSession'))->toBeFalse();
});

test('jumps to a specific step when next_step is given', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123, 'next_step' => 'commit']));

    expect($result)->toContain('"commit"')->toContain('(6/6)');

    expect($workflow->fresh()->current_step)->toBe('commit');
});

test('rejects an unknown next_step override', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123, 'next_step' => 'nope']));

    expect($result)->toContain('unknown step')->toContain('nope');

    expect($workflow->fresh()->current_step)->toBe('context-gather')
        ->and($manager->called('advanceSession'))->toBeFalse();
});

test('errors when the workflow has no remaining steps', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'commit',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('no remaining steps');

    expect($workflow->fresh()->current_step)->toBe('commit')
        ->and($manager->called('advanceSession'))->toBeFalse();
});

test('appends additional context to the step prompt', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'plan',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $tool->handle(new Request(['chat_id' => 123, 'additional_context' => 'Use the existing layout.']));

    $prompt = $manager->lastCall('advanceSession')['prompt'];

    expect($prompt)->toContain('Additional context')
        ->and($prompt)->toContain('Use the existing layout.')
        ->and($prompt)->toContain('execute');
});

test('starts from the first step when the workflow has no current step', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => null,
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $tool->handle(new Request(['chat_id' => 123]));

    expect($workflow->fresh()->current_step)->toBe('context-gather');
});

test('picks the most recent active workflow when no chat id is available', function () {
    OpencodeWorkflow::factory()->completed()->create(['chat_id' => 999]);
    $active = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 999,
        'opencode_session_id' => 'ses_active',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request);

    expect($result)->toContain('Advanced workflow #'.$active->id);

    expect($manager->lastCall('advanceSession')['sessionId'])->toBe('ses_active');
});

test('marks the step as failed when opencode errors', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $manager->error = new OpencodeException('boom');
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('opencode failed')->toContain('boom');

    expect($workflow->fresh()->current_step)->toBe('context-gather')
        ->and($workflow->steps->first()->status->value)->toBe(OpencodeWorkflowStatus::Failed->value);
});

test('sends the user reply to the session before advancing when reply_to_session is given', function () {
    $workflow = OpencodeWorkflow::factory()->waitingConfirmation()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'plan',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'chat_id' => 123,
        'reply_to_session' => 'Yes, you can modify config.php',
    ]));

    expect($result)->toContain("Replied to the session's questions.")
        ->toContain('Advanced workflow #'.$workflow->id)
        ->toContain('"execute"');

    $reply = $manager->calls[0];
    $advance = $manager->calls[1];

    expect($reply['method'])->toBe('reply')
        ->and($reply['sessionId'])->toBe('ses_abc')
        ->and($reply['directory'])->toBe($workflow->project_path)
        ->and($reply['prompt'])->toBe('Yes, you can modify config.php');

    expect($advance['method'])->toBe('advanceSession')
        ->and($advance['sessionId'])->toBe('ses_abc')
        ->and($advance['opts'])->toHaveKey('agent', 'orchestrator');
});

test('returns a readable error without advancing when sending the reply fails', function () {
    $workflow = OpencodeWorkflow::factory()->waitingConfirmation()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'plan',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $manager->error = new OpencodeException('session gone');
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'chat_id' => 123,
        'reply_to_session' => 'Yes',
    ]));

    expect($result)->toContain('could not send the reply to the session')
        ->toContain('session gone');

    expect($workflow->fresh()->current_step)->toBe('plan')
        ->and($manager->called('advanceSession'))->toBeFalse()
        ->and(OpencodeWorkflowStep::count())->toBe(0);
});

test('does not reply to the session when no reply_to_session is given', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $tool->handle(new Request(['chat_id' => 123]));

    expect($manager->called('reply'))->toBeFalse()
        ->and($workflow->fresh()->current_step)->toBe('plan');
});

test('forwards a custom agent to advance the next step', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'context-gather',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeAdvanceWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123, 'agent' => 'explore']));

    expect($result)->toContain('Advanced workflow #'.$workflow->id);

    expect($manager->lastCall('advanceSession')['opts'])->toHaveKey('agent', 'explore');
});
