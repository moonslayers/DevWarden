<?php

use App\Enums\OpencodeConfirmationMode;
use App\Enums\OpencodeWorkflowStatus;
use App\Enums\OpencodeWorkflowTemplate;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/**
 * Bind a fake session manager whose check results and conversations are keyed
 * by session id.
 *
 * @param  array<string, array{status: string, finished: bool, raw: string}>  $checkResults
 * @param  array<string, string>  $conversations
 */
function bindSessionManager(array $checkResults, array $conversations = [], bool $throws = false): object
{
    $manager = mock(OpencodeSessionManager::class);

    if ($throws) {
        $manager->shouldReceive('checkSession')->andThrow(OpencodeException::class, 'session not found');
    } else {
        $manager->shouldReceive('checkSession')
            ->andReturnUsing(fn (string $sessionId): array => $checkResults[$sessionId] ?? ['status' => 'running', 'finished' => false, 'raw' => '']);
    }

    $manager->shouldReceive('conversation')
        ->andReturnUsing(fn (string $sessionId): string => $conversations[$sessionId] ?? '');
    $manager->shouldReceive('disconnect');

    app()->instance(OpencodeSessionManager::class, $manager);

    return $manager;
}

/**
 * Bind a fake notifier that records every notification and returns $sendResult
 * (a bool or a callable deciding the send outcome per notification).
 *
 * @param  array<int, array{chat_id: int, text: string}>  $messages
 */
function bindNotifier(array &$messages, bool|callable $sendResult = true): object
{
    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldReceive('notify')
        ->andReturnUsing(function (int $chatId, string $markdown) use (&$messages, $sendResult): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown];

            return is_bool($sendResult) ? $sendResult : $sendResult($chatId, $markdown);
        });

    app()->instance(OpencodeNotifier::class, $notifier);

    return $notifier;
}

/**
 * Bind a fake session watcher and assert it runs once per scheduled tick.
 */
function bindSessionWatcher(int $times = 1): object
{
    $watcher = mock(OpencodeSessionWatcher::class);
    $watcher->shouldReceive('check')->times($times);

    app()->instance(OpencodeSessionWatcher::class, $watcher);

    return $watcher;
}

function runningWorkflow(array $overrides = []): OpencodeWorkflow
{
    return OpencodeWorkflow::factory()->running()->create(array_merge([
        'chat_id' => 123456789,
        'opencode_session_id' => 'ses_test123',
        'template' => OpencodeWorkflowTemplate::Default,
        'current_step' => 'context-gather',
    ], $overrides));
}

function runningStep(OpencodeWorkflow $workflow, string $stepName): OpencodeWorkflowStep
{
    return OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => $stepName,
        'command' => $stepName,
        'status' => OpencodeWorkflowStatus::Running,
    ]);
}

function finishedTranscript(string $assistant): string
{
    return "--- Message 1 [user] ---\nGo.\n\n--- Message 2 [assistant] ---\n{$assistant}";
}

test('does nothing when no workflows are running', function () {
    $messages = [];
    bindSessionManager([], []);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($messages)->toBeEmpty();
});

test('skips workflows whose session is still running', function () {
    $messages = [];
    $workflow = runningWorkflow();
    $step = runningStep($workflow, 'context-gather');

    bindSessionManager([
        'ses_test123' => ['status' => 'running', 'finished' => false, 'raw' => 'Status: **running**'],
    ], []);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($workflow->fresh()->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($messages)->toBeEmpty();
});

test('marks the current step completed and asks to proceed when more steps remain', function () {
    $messages = [];
    $workflow = runningWorkflow();
    $step = runningStep($workflow, 'context-gather');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Context gathered.'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::WaitingConfirmation)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::Proceed)
        ->and($workflow->last_summary)->toContain('Context gathered.');

    $step->refresh();
    expect($step->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($step->summary)->toBe('Context gathered.')
        ->and($step->finished_at)->not->toBeNull();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($workflow->chat_id)
        ->and($messages[0]['text'])->toContain('¿Continúo con el paso "plan"?');
});

test('marks the workflow completed when the final step finishes without questions', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);
    $step = runningStep($workflow, 'commit');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Done! Committed.'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($workflow->confirmation_mode)->toBeNull()
        ->and($workflow->completed_at)->not->toBeNull()
        ->and($workflow->last_summary)->toContain('Workflow completado.');

    expect($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);

    expect($messages[0]['text'])->toContain('Workflow completado.')
        ->toContain('Done! Committed.');
});

test('waits for an answer when the session ends with questions', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'execute']);
    $step = runningStep($workflow, 'execute');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('¿Confirmas que debo modificar config.php?'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::WaitingConfirmation)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::Answer)
        ->and($workflow->last_summary)->toContain('La sesión tiene preguntas:');

    expect($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);

    expect($messages[0]['text'])->toContain('La sesión tiene preguntas:');
});

test('does not duplicate the summary in the questions message', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'execute']);
    runningStep($workflow, 'execute');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('¿Confirmas que debo modificar config.php?'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::WaitingConfirmation)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::Answer);

    expect($messages[0]['text'])->toBe(
        "¿Confirmas que debo modificar config.php?\n\n"
        ."La sesión tiene preguntas:\n\n"
        .'Responde a las preguntas para continuar, o envía /abort para detener el workflow.'
    );
});

test('presents the plan step result as the executive summary', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'plan']);
    runningStep($workflow, 'plan');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Plan ready.'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($messages[0]['text'])->toStartWith('Plan listo — resumen ejecutivo:');
});

test('truncates the raw output to the step maximum length', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'validate']);
    $step = runningStep($workflow, 'validate');

    $longTranscript = finishedTranscript(str_repeat('a', 30000));

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => $longTranscript,
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $step->refresh();
    expect(mb_strlen($step->raw_output))->toBeLessThanOrEqual(OpencodeWorkflowStep::MAX_RAW_OUTPUT_LENGTH)
        ->and(mb_strlen($step->summary))->toBeLessThanOrEqual(2000);
});

test('keeps the workflow running so a failed final notification is retried next tick', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);
    runningStep($workflow, 'commit');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Done!'),
    ]);
    bindNotifier($messages, sendResult: false);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($workflow->status)->not->toBe(OpencodeWorkflowStatus::Completed)
        ->and($workflow->failure_count)->toBe(1)
        ->and($workflow->last_summary)->not->toBeNull();
});

test('completes the workflow when the final notification succeeds on a later tick', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);
    $step = runningStep($workflow, 'commit');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Done!'),
    ]);

    $attempts = 0;
    bindNotifier($messages, function () use (&$attempts): bool {
        $attempts++;

        return $attempts > 1;
    });
    bindSessionWatcher(times: 2);

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($workflow->failure_count)->toBe(1)
        ->and($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($workflow->confirmation_mode)->toBeNull()
        ->and($workflow->completed_at)->not->toBeNull()
        ->and($workflow->failure_count)->toBe(0);

    expect($messages)->toHaveCount(2);
});

test('marks the workflow failed after repeated final notification failures', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);
    runningStep($workflow, 'commit');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Done!'),
    ]);
    bindNotifier($messages, sendResult: false);
    bindSessionWatcher(times: 3);

    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->failure_count)->toBe(3)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::DecisionOnFailure)
        ->and($workflow->last_summary)->toContain('/abort');

    expect($messages)->toHaveCount(4)
        ->and($messages[3]['text'])->toContain('/retry')
        ->and($messages[3]['text'])->toContain('/abort');
});

test('keeps the workflow running after a single check failure', function () {
    $messages = [];
    $workflow = runningWorkflow();
    runningStep($workflow, 'context-gather');

    bindSessionManager([], [], throws: true);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->failure_count)->toBe(1)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($messages)->toBeEmpty();
});

test('marks the workflow failed and asks for a decision after repeated check failures', function () {
    $messages = [];
    $workflow = runningWorkflow();
    runningStep($workflow, 'context-gather');

    bindSessionManager([], [], throws: true);
    bindNotifier($messages);
    bindSessionWatcher(times: 3);

    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->failure_count)->toBe(3)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::DecisionOnFailure)
        ->and($workflow->last_summary)->toContain('/abort');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('/retry')
        ->and($messages[0]['text'])->toContain('/abort');
});

test('resets the failure count after a successful check', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);
    $step = runningStep($workflow, 'commit');

    $calls = 0;
    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('checkSession')
        ->andReturnUsing(function () use (&$calls): array {
            $calls++;

            if ($calls <= 2) {
                throw new OpencodeException('session not found');
            }

            return ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'];
        });
    $manager->shouldReceive('conversation')->andReturn(finishedTranscript('Done!'));
    $manager->shouldReceive('disconnect');
    app()->instance(OpencodeSessionManager::class, $manager);

    bindNotifier($messages);
    bindSessionWatcher(times: 3);

    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->failure_count)->toBe(0)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Completed)
        ->and($step->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);

    expect($messages)->toHaveCount(1);
});

test('does not resurrect a failed step as completed when no step is running', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'commit']);

    $failedStep = OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'commit',
        'command' => 'commit',
        'status' => OpencodeWorkflowStatus::Failed,
    ]);

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Done!'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($failedStep->fresh()->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($failedStep->fresh()->finished_at)->toBeNull();
});

test('completes the running step for the current step and leaves failed steps untouched', function () {
    $messages = [];
    $workflow = runningWorkflow(['current_step' => 'execute']);

    $failedStep = OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'plan',
        'command' => 'plan',
        'status' => OpencodeWorkflowStatus::Failed,
    ]);
    $runningStep = runningStep($workflow, 'execute');

    bindSessionManager([
        'ses_test123' => ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'],
    ], [
        'ses_test123' => finishedTranscript('Executed.'),
    ]);
    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($failedStep->fresh()->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($runningStep->fresh()->status)->toBe(OpencodeWorkflowStatus::Completed);
});

test('counts a workflow without a session id as a check failure', function () {
    $messages = [];
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123456789,
        'opencode_session_id' => null,
    ]);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldNotReceive('checkSession');
    $manager->shouldReceive('disconnect');
    app()->instance(OpencodeSessionManager::class, $manager);

    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();

    expect($workflow->fresh()->status)->toBe(OpencodeWorkflowStatus::Running)
        ->and($workflow->fresh()->failure_count)->toBe(1)
        ->and($messages)->toBeEmpty();
});

test('marks a workflow without a session id as failed after repeated ticks', function () {
    $messages = [];
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123456789,
        'opencode_session_id' => null,
    ]);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldNotReceive('checkSession');
    $manager->shouldReceive('disconnect');
    app()->instance(OpencodeSessionManager::class, $manager);

    bindNotifier($messages);
    bindSessionWatcher(times: 3);

    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();
    $this->artisan('opencode:monitor')->assertSuccessful();

    $workflow->refresh();
    expect($workflow->failure_count)->toBe(3)
        ->and($workflow->status)->toBe(OpencodeWorkflowStatus::Failed)
        ->and($workflow->confirmation_mode)->toBe(OpencodeConfirmationMode::DecisionOnFailure)
        ->and($workflow->last_summary)->toContain('/retry');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('/retry')
        ->and($messages[0]['text'])->toContain('/abort');
});

test('disconnects the session manager after monitoring', function () {
    $messages = [];
    $workflow = runningWorkflow();
    runningStep($workflow, 'context-gather');

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('checkSession')->andReturn(['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**']);
    $manager->shouldReceive('conversation')->andReturn(finishedTranscript('Hello.'));
    $manager->shouldReceive('disconnect')->once();
    app()->instance(OpencodeSessionManager::class, $manager);

    bindNotifier($messages);
    bindSessionWatcher();

    $this->artisan('opencode:monitor')->assertSuccessful();
});
