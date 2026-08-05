<?php

use App\Ai\Tools\Opencode\OpencodeStopWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeTelegramClient;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

test('stops the active workflow, aborts its session and notifies the owner', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
        'current_step' => 'execute',
    ]);

    $currentStep = OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'execute',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $manager = new FakeOpencodeSessionManager;
    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);
    $tool = new OpencodeStopWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toBeString()
        ->toContain('Workflow #'.$workflow->id.' (template: default) stopped.')
        ->toContain('Session ses_fake123 aborted.');

    expect($workflow->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($workflow->fresh()->completed_at)->not->toBeNull();

    expect($currentStep->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($currentStep->fresh()->finished_at)->not->toBeNull();

    expect($manager->lastCall('abort')['sessionId'])->toBe('ses_abc')
        ->and($manager->lastCall('abort')['directory'])->toBe($workflow->project_path);

    expect($telegram->sent)->toHaveCount(1);
    expect($telegram->sent[0]['chat_id'])->toBe(123)
        ->and($telegram->sent[0]['text'])->toContain('Workflow #'.$workflow->id)
        ->and($telegram->sent[0]['parse_mode'])->toBe('HTML');
});

test('still stops the workflow when the Telegram notification fails', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_abc',
    ]);

    $telegram = new FakeTelegramClient;
    $telegram->error = new TelegramApiException('Telegram is down.');
    $this->app->instance(TelegramClient::class, $telegram);

    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStopWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('Workflow #'.$workflow->id.' (template: default) stopped.')
        ->toContain('Could not notify the owner via Telegram')
        ->toContain('the Telegram message could not be sent.');

    expect($workflow->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value);
});

test('errors when no workflow is found', function () {
    $manager = new FakeOpencodeSessionManager;
    $tool = new OpencodeStopWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('no workflow found to stop')
        ->and($manager->called('abort'))->toBeFalse();
});

test('marks the workflow stopped without aborting when it has no session', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => null,
    ]);

    $manager = new FakeOpencodeSessionManager;
    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);
    $tool = new OpencodeStopWorkflowTool($manager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('Workflow #'.$workflow->id.' (template: default) stopped.');

    expect($workflow->fresh()->status->value)->toBe(OpencodeWorkflowStatus::Stopped->value)
        ->and($manager->called('abort'))->toBeFalse();
});

test('stops a specific workflow by id', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'opencode_session_id' => 'ses_specific',
    ]);

    $manager = new FakeOpencodeSessionManager;
    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);
    $tool = new OpencodeStopWorkflowTool($manager);

    $result = $tool->handle(new Request(['workflow_id' => $workflow->id]));

    expect($result)->toContain('Workflow #'.$workflow->id.' (template: default) stopped.')
        ->and($manager->lastCall('abort')['sessionId'])->toBe('ses_specific');
});
