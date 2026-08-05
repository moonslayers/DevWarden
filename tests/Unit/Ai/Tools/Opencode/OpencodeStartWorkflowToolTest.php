<?php

use App\Ai\Tools\Opencode\OpencodeStartWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Models\User;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeTelegramClient;

uses(TestCase::class, RefreshDatabase::class);

class FailingAbortOpencodeSessionManager extends FakeOpencodeSessionManager
{
    public function abort(string $sessionId, string $directory): string
    {
        $this->calls[] = [
            'method' => __FUNCTION__,
            'sessionId' => $sessionId,
            'directory' => $directory,
        ];

        throw new OpencodeException('abort exploded');
    }
}

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

test('starts a workflow, creates the first step and dispatches it to opencode', function () {
    $user = User::factory()->create();
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'template' => 'feature',
        'requirement' => 'Add a login page',
        'chat_id' => 123,
        'user_id' => $user->id,
    ]));

    $workflow = OpencodeWorkflow::firstOrFail();

    expect($result)->toBeString()
        ->toContain('Workflow #'.$workflow->id)
        ->toContain('Step 1/6')
        ->toContain('context-gather');

    expect($workflow->chat_id)->toBe(123)
        ->and($workflow->user_id)->toBe($user->id)
        ->and($workflow->project_path)->toBe('/home/junior/Projects/DevWarden')
        ->and($workflow->template->value)->toBe('feature')
        ->and($workflow->status->value)->toBe('running')
        ->and($workflow->current_step)->toBe('context-gather')
        ->and($workflow->opencode_session_id)->toBe(FakeOpencodeSessionManager::SESSION_ID);

    expect($workflow->steps)->toHaveCount(1);
    expect($workflow->steps->first()->step_name)->toBe('context-gather')
        ->and($workflow->steps->first()->status->value)->toBe('running');

    $call = $manager->lastCall('startAsyncSession');

    expect($call['directory'])->toBe('/home/junior/Projects/DevWarden')
        ->and($call['prompt'])->toContain('context-gather')
        ->and($call['prompt'])->toContain('Add a login page')
        ->and($call['opts'])->toHaveKey('agent', 'orchestrator');
});

test('defaults to the default template when none is given', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'Explain this codebase',
        'chat_id' => 123,
    ]));

    $workflow = OpencodeWorkflow::firstOrFail();

    expect($workflow->template->value)->toBe('default')
        ->and($workflow->current_step)->toBe('context-gather');
});

test('rejects an unknown template without touching opencode or the database', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'template' => 'bogus',
        'requirement' => 'Whatever',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('unknown template')->toContain('bogus');

    expect(OpencodeWorkflow::count())->toBe(0)
        ->and($manager->called('startAsyncSession'))->toBeFalse();
});

test('rejects a missing project or requirement', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    expect($tool->handle(new Request([
        'requirement' => 'No project here',
        'chat_id' => 123,
    ])))->toContain('missing required "project"');

    expect($tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'chat_id' => 123,
    ])))->toContain('missing required "requirement"');

    expect(OpencodeWorkflow::count())->toBe(0);
});

test('rejects a project outside the allowed root without creating a workflow', function () {
    $manager = new FakeOpencodeSessionManager;
    $manager->allowed = false;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/etc',
        'requirement' => 'Nope',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('not allowed');

    expect(OpencodeWorkflow::count())->toBe(0)
        ->and($manager->called('startAsyncSession'))->toBeFalse();
});

test('requires a chat id when neither an argument nor the context is set', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'Something',
    ]));

    expect($result)->toContain('could not determine the chat_id');

    expect(OpencodeWorkflow::count())->toBe(0)
        ->and($manager->called('startAsyncSession'))->toBeFalse();
});

test('falls back to the chat context when no chat id argument is given', function () {
    $user = User::factory()->create();
    OpencodeWorkflowContext::set(999, $user->id);
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'From context',
    ]));

    $workflow = OpencodeWorkflow::firstOrFail();

    expect($workflow->chat_id)->toBe(999)
        ->and($workflow->user_id)->toBe($user->id);
});

test('forwards a custom agent instead of the orchestrator default', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'Custom agent',
        'chat_id' => 123,
        'agent' => 'explore',
    ]));

    expect($manager->lastCall('startAsyncSession')['opts'])->toHaveKey('agent', 'explore');
});

test('keeps the session id null when opencode reports none', function () {
    $manager = new FakeOpencodeSessionManager;
    $manager->startResult = ['sessionId' => null, 'message' => 'Task queued.'];
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'No session',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('pending');

    expect(OpencodeWorkflow::firstOrFail()->opencode_session_id)->toBeNull();
});

test('marks the workflow and step as failed when opencode errors', function () {
    $manager = new FakeOpencodeSessionManager;
    $manager->error = new OpencodeException('boom');
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'Will fail',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('opencode failed')->toContain('boom');

    $workflow = OpencodeWorkflow::firstOrFail();

    expect($workflow->status->value)->toBe('failed')
        ->and($workflow->steps->first()->status->value)->toBe('failed');
});

test('stops a previous active workflow for the same chat before starting a new one', function () {
    $previous = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_prev',
        'current_step' => 'execute',
    ]);

    $runningStep = OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $previous->id,
        'step_name' => 'execute',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'New feature',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('Previous workflow #'.$previous->id.' stopped');

    expect($previous->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($previous->fresh()->completed_at)->not->toBeNull();

    expect($runningStep->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($runningStep->fresh()->finished_at)->not->toBeNull();

    expect($manager->lastCall('abort')['sessionId'])->toBe('ses_prev')
        ->and($manager->lastCall('abort')['directory'])->toBe($previous->project_path);

    expect($telegram->sent)->toHaveCount(1)
        ->and($telegram->sent[0]['chat_id'])->toBe(123)
        ->and($telegram->sent[0]['text'])->toContain('Workflow #'.$previous->id)
        ->and($telegram->sent[0]['text'])->toContain('stopped because a new workflow')
        ->and($telegram->sent[0]['parse_mode'])->toBe('HTML');

    expect(OpencodeWorkflow::count())->toBe(2);

    $new = OpencodeWorkflow::latest('id')->firstOrFail();

    expect($new->status->value)->toBe(OpencodeWorkflowStatus::Running->value)
        ->and($new->chat_id)->toBe(123)
        ->and($manager->called('startAsyncSession'))->toBeTrue();
});

test('still starts the new workflow when aborting the previous session fails', function () {
    OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_prev',
    ]);

    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);

    $manager = new FailingAbortOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'New feature',
        'chat_id' => 123,
    ]));

    expect($result)->toContain('Previous workflow #1 stopped');

    expect($manager->called('abort'))->toBeTrue()
        ->and(OpencodeWorkflow::count())->toBe(2);

    $previous = OpencodeWorkflow::oldest('id')->firstOrFail();

    expect($previous->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($telegram->sent)->toHaveCount(1);
});

test('stops the previous workflow without aborting when it has no session', function () {
    OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => null,
    ]);

    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'New feature',
        'chat_id' => 123,
    ]));

    expect($manager->called('abort'))->toBeFalse()
        ->and(OpencodeWorkflow::count())->toBe(2);

    $previous = OpencodeWorkflow::oldest('id')->firstOrFail();

    expect($previous->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value);
});

test('does not stop a completed workflow before starting a new one', function () {
    $completed = OpencodeWorkflow::factory()->completed()->create(['chat_id' => 123]);

    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStartWorkflowTool($manager);

    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'requirement' => 'New feature',
        'chat_id' => 123,
    ]));

    expect($result)->not->toContain('Previous workflow');

    expect($manager->called('abort'))->toBeFalse()
        ->and($telegram->sent)->toBeEmpty()
        ->and($completed->fresh()->status->value)->toBe('completed')
        ->and(OpencodeWorkflow::count())->toBe(2);
});
