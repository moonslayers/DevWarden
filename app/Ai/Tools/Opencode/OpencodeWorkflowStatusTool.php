<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeWorkflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OpencodeWorkflowStatusTool extends OpencodeWorkflowTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Reports the current state of an opencode workflow: status, template, current step, last summary and recent steps. Use when the user asks how a workflow is going or what its progress is. Pass "workflow_id" for a specific workflow, or omit it to inspect the active workflow for the chat. Read-only: does not dispatch anything to opencode.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $workflowId = $request['workflow_id'] ?? null;
        $chatId = $this->resolveChatId($request);

        $workflow = $this->resolveWorkflow($chatId, $workflowId !== null ? (int) $workflowId : null);

        if ($workflow === null) {
            return 'Error: no workflow found.';
        }

        return $this->formatStatus($workflow);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->integer()
                ->description('Optional workflow id. Defaults to the active workflow for the chat.'),
            'chat_id' => $schema->integer()
                ->description('Optional Telegram chat id; normally set by the bot context.'),
        ];
    }

    /**
     * Render a readable, plain-text status report for a workflow.
     */
    protected function formatStatus(OpencodeWorkflow $workflow): string
    {
        $workflow->loadMissing('steps');

        $lines = [
            'Workflow #'.$workflow->id,
            'Template: '.$workflow->template->value,
            'Status: '.$workflow->status->value,
            'Project: '.$workflow->project_path,
            'Current step: '.($workflow->current_step ?? 'none'),
        ];

        if ($workflow->opencode_session_id !== null) {
            $lines[] = 'OpenCode session: '.$workflow->opencode_session_id;
        }

        if ($workflow->last_summary !== null) {
            $lines[] = 'Last summary: '.$workflow->last_summary;
        }

        $lines[] = 'Started: '.($workflow->started_at?->toDateTimeString() ?? 'unknown');

        $recentSteps = $workflow->steps->sortByDesc('id')->take(6)->reverse();

        if ($recentSteps->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent steps:';

            foreach ($recentSteps as $step) {
                $lines[] = sprintf(
                    '- %s: %s (started %s)',
                    $step->step_name,
                    $step->status->value,
                    $step->started_at?->toDateTimeString() ?? 'unknown',
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
