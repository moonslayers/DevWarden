<?php

use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Ai\Tools\Opencode\OpencodeWorkflowStatusTool;
use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    OpencodeWorkflowContext::clear();
});

test('reports the status of the active workflow for the chat including recent steps', function () {
    $workflow = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 123,
        'current_step' => 'execute',
        'last_summary' => 'Plan approved.',
    ]);

    OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'context-gather',
        'status' => OpencodeWorkflowStatus::Completed,
    ]);

    OpencodeWorkflowStep::factory()->create([
        'opencode_workflow_id' => $workflow->id,
        'step_name' => 'execute',
        'status' => OpencodeWorkflowStatus::Running,
    ]);

    $tool = new OpencodeWorkflowStatusTool(new FakeOpencodeSessionManager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toBeString()
        ->toContain('Workflow #'.$workflow->id)
        ->toContain('Template: default')
        ->toContain('Status: running')
        ->toContain('Current step: execute')
        ->toContain('Last summary: Plan approved.')
        ->toContain('Recent steps')
        ->toContain('context-gather: completed')
        ->toContain('execute: running');
});

test('reports a specific workflow by id even when it is not active', function () {
    $workflow = OpencodeWorkflow::factory()->completed()->create(['chat_id' => 123]);

    $tool = new OpencodeWorkflowStatusTool(new FakeOpencodeSessionManager);

    $result = $tool->handle(new Request(['workflow_id' => $workflow->id]));

    expect($result)->toContain('Workflow #'.$workflow->id)
        ->toContain('Status: completed');
});

test('errors when no workflow matches', function () {
    $tool = new OpencodeWorkflowStatusTool(new FakeOpencodeSessionManager);

    $result = $tool->handle(new Request(['chat_id' => 123]));

    expect($result)->toContain('no workflow found');
});

test('falls back to the most recent active workflow when no chat id is available', function () {
    OpencodeWorkflow::factory()->completed()->create(['chat_id' => 999]);
    $active = OpencodeWorkflow::factory()->running()->create([
        'chat_id' => 999,
        'current_step' => 'plan',
    ]);

    $tool = new OpencodeWorkflowStatusTool(new FakeOpencodeSessionManager);

    $result = $tool->handle(new Request);

    expect($result)->toContain('Workflow #'.$active->id)
        ->toContain('Current step: plan');
});
