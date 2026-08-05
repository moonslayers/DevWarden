<?php

use App\Ai\Tools\Opencode\OpencodeAdvanceWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeStartWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeStopWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Enums\OpencodeConfirmationMode;
use App\Enums\OpencodeWorkflowStatus;
use App\Enums\OpencodeWorkflowTemplate;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Models\User;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Feature\Opencode\Support\FakeOpencodeSessionManager;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->chatId = 123456789;
    $this->user = User::factory()->create();

    $this->telegram = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $this->telegram);

    $this->manager = new FakeOpencodeSessionManager;
    app()->instance(OpencodeSessionManager::class, $this->manager);

    OpencodeWorkflowContext::set($this->chatId, $this->user->id);
});

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

function lifecycleFinishedTranscript(string $assistant): string
{
    return "--- Message 1 [user] ---\nGo.\n\n--- Message 2 [assistant] ---\n{$assistant}";
}

function lifecycleIdleCheck(): array
{
    return ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'];
}

test('runs the full workflow lifecycle from start to completion', function () {
    $tool = new OpencodeStartWorkflowTool($this->manager);
    $result = $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'template' => 'feature',
        'requirement' => 'Add a login page with 2FA',
    ]));

    $workflow = OpencodeWorkflow::query()->firstOrFail();

    expect($result)->toBeString()
        ->toContain('Workflow #'.$workflow->id)
        ->toContain('Step 1/6')
        ->toContain('context-gather');

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($workflow->current_step)->toBe('context-gather')
        ->and($workflow->chat_id)->toBe($this->chatId)
        ->and($workflow->user_id)->toBe($this->user->id)
        ->and($workflow->opencode_session_id)->toBe(FakeOpencodeSessionManager::SESSION_ID);

    expect($workflow->steps)->toHaveCount(1)
        ->and($workflow->steps->first()->step_name)->toBe('context-gather')
        ->and($workflow->steps->first()->status)->toBe(OpencodeWorkflowStatus::Running);

    $this->manager->checkResults[FakeOpencodeSessionManager::SESSION_ID] = lifecycleIdleCheck();
    $this->manager->conversation = lifecycleFinishedTranscript('Context gathered.');

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::WaitingConfirmation)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::Proceed)
        ->and($workflow->last_summary)->toContain('Context gathered.');

    $firstStep = $workflow->steps()->where('step_name', 'context-gather')->firstOrFail();

    expect($firstStep->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($firstStep->summary)->toBe('Context gathered.')
        ->and($firstStep->raw_output)->toContain('Context gathered.')
        ->and($firstStep->finished_at)->not->toBeNull();

    expect($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['chat_id'])->toBe($this->chatId)
        ->and($this->telegram->sent[0]['text'])->toContain('¿Continúo con el paso')
        ->and($this->telegram->sent[0]['text'])->toContain('plan-feature');

    $advance = new OpencodeAdvanceWorkflowTool($this->manager);
    $advanceResult = $advance->handle(new Request(['next_step' => 'commit']));

    $workflow->refresh();

    expect($advanceResult)->toContain('Advanced workflow #'.$workflow->id)
        ->and($advanceResult)->toContain('"commit"')
        ->and($workflow->current_step)->toBe('commit')
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Running);

    $commitStep = $workflow->steps()->where('step_name', 'commit')->firstOrFail();

    expect($commitStep->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($this->manager->called('advanceSession'))->toBeTrue();

    $call = $this->manager->lastCall('advanceSession');

    expect($call['sessionId'])->toBe(FakeOpencodeSessionManager::SESSION_ID)
        ->and($call['prompt'])->toContain('commit');

    $this->manager->conversation = lifecycleFinishedTranscript('Done! Committed.');

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($workflow->confirmation_mode)->toBeNull()
        ->and($workflow->completed_at)->not->toBeNull()
        ->and($workflow->last_summary)->toContain('Workflow completado.');

    expect($commitStep->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);

    expect($this->telegram->sent)->toHaveCount(2)
        ->and($this->telegram->sent[1]['chat_id'])->toBe($this->chatId)
        ->and($this->telegram->sent[1]['text'])->toContain('Workflow completado.')
        ->and($this->telegram->sent[1]['text'])->toContain('Done! Committed.');
});

test('walks every template step sequentially through advance and monitor runs', function () {
    $steps = OpencodeWorkflowTemplate::Feature->steps();

    $tool = new OpencodeStartWorkflowTool($this->manager);
    $tool->handle(new Request([
        'project' => '/home/junior/Projects/DevWarden',
        'template' => 'feature',
        'requirement' => 'Add a login page',
    ]));

    $workflow = OpencodeWorkflow::query()->firstOrFail();

    foreach ($steps as $index => $step) {
        if ($index > 0) {
            $advance = new OpencodeAdvanceWorkflowTool($this->manager);
            $advance->handle(new Request([]));

            expect($workflow->fresh()->status)->toBe(OpencodeWorkflowStatus::Running);
        }

        $this->manager->checkResults[FakeOpencodeSessionManager::SESSION_ID] = lifecycleIdleCheck();
        $this->manager->conversation = lifecycleFinishedTranscript("Finished {$step}.");

        $this->artisan('opencode:monitor')->assertSuccessful();

        $workflow->refresh();

        expect($workflow->steps()->where('step_name', $step)->firstOrFail()->fresh()->status)
            ->toBe(OpencodeWorkflowStatus::Completed);
    }

    $workflow->refresh();

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($workflow->steps)->toHaveCount(count($steps))
        ->and($this->telegram->sent)->toHaveCount(count($steps));
});

test('stops the active workflow, aborts the session and notifies the owner', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => $this->chatId,
        'user_id' => $this->user->id,
        'opencode_session_id' => FakeOpencodeSessionManager::SESSION_ID,
        'template' => OpencodeWorkflowTemplate::Default,
        'current_step' => 'execute',
    ]);

    $step = OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'execute',
        'command' => 'execute',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $tool = new OpencodeStopWorkflowTool($this->manager);
    $result = $tool->handle(new Request);

    expect($result)->toContain('Workflow #'.$workflow->id)
        ->toContain('stopped')
        ->toContain('aborted');

    expect($this->manager->called('abort'))->toBeTrue();

    $workflow->refresh();

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Stopped)
        ->and($workflow->completed_at)->not->toBeNull();

    expect($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Stopped);

    expect($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['chat_id'])->toBe($this->chatId)
        ->and($this->telegram->sent[0]['text'])->toContain('Workflow #'.$workflow->id)
        ->and($this->telegram->sent[0]['text'])->toContain('stopped');
});

test('waits for an answer when the finished session ends with questions', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => $this->chatId,
        'user_id' => $this->user->id,
        'opencode_session_id' => FakeOpencodeSessionManager::SESSION_ID,
        'template' => OpencodeWorkflowTemplate::Default,
        'current_step' => 'execute',
    ]);

    OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'execute',
        'command' => 'execute',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $this->manager->checkResults[FakeOpencodeSessionManager::SESSION_ID] = lifecycleIdleCheck();
    $this->manager->conversation = lifecycleFinishedTranscript('¿Confirmas que debo modificar config.php?');

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();

    expect($workflow->status)->toBe(OpencodeWorkflowStatus::WaitingConfirmation)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::Answer)
        ->and($workflow->last_summary)->toContain('La sesión tiene preguntas:');

    expect($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['text'])->toContain('La sesión tiene preguntas:');
});

test('marks the workflow failed and asks for a decision after repeated check exceptions', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => $this->chatId,
        'user_id' => $this->user->id,
        'opencode_session_id' => FakeOpencodeSessionManager::SESSION_ID,
        'template' => OpencodeWorkflowTemplate::Default,
        'current_step' => 'context-gather',
    ]);

    OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'context-gather',
        'command' => 'context-gather',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $this->manager->checkError = new OpencodeException('session not found');

    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();

    expect($workflow->failure_count)->toBe(3)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::DecisionOnFailure)
        ->and($workflow->last_summary)->toContain('/abort');

    expect($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['text'])->toContain('/retry')
        ->and($this->telegram->sent[0]['text'])->toContain('/abort');
});
