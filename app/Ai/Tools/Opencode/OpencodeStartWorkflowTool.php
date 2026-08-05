<?php

namespace App\Ai\Tools\Opencode;

use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeNotifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class OpencodeStartWorkflowTool extends OpencodeWorkflowTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Starts a new opencode workflow in a project: creates the workflow, dispatches the FIRST step (context-gather) to opencode and returns the session id. Use when the user asks you to open or use opencode on a project ("abre opencode", "usa opencode en X", "sigue el flujo"), asks to work on a project, or wants to implement a feature, bugfix or refactor. Pass the absolute project path, the template (default, feature, bugfix or refactor) and the user\'s requirement.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $project = trim((string) ($request['project'] ?? ''));
        $requirement = trim((string) ($request['requirement'] ?? ''));
        $template = trim((string) ($request['template'] ?? 'default'));
        $agent = $request['agent'] ?? null;

        if ($project === '') {
            return 'Error: missing required "project" argument. Pass the absolute path of the project directory.';
        }

        if ($requirement === '') {
            return 'Error: missing required "requirement" argument.';
        }

        $workflowTemplate = $this->templateFor($template);

        if ($workflowTemplate === null) {
            return 'Error: unknown template "'.$template.'". Valid templates: default, feature, bugfix, refactor.';
        }

        $chatId = $this->resolveChatId($request);
        $userId = $this->resolveUserId($request);

        if ($chatId === null) {
            return 'Error: could not determine the chat_id for this workflow. Pass the "chat_id" argument explicitly.';
        }

        if (! $this->manager->isAllowedProject($project)) {
            return 'Error: the project path is not allowed. Projects must live inside the configured root projects path.';
        }

        $stoppedNote = '';

        $previousWorkflow = $this->resolveWorkflow($chatId);

        if ($previousWorkflow !== null) {
            $this->stopActiveWorkflow($previousWorkflow);
            $stoppedNote = sprintf('Previous workflow #%d stopped because a new one was started. ', $previousWorkflow->id);
        }

        $steps = $workflowTemplate->steps();
        $firstStep = $steps[0];

        $workflow = OpencodeWorkflow::query()->create([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'project_path' => $project,
            'template' => $workflowTemplate,
            'status' => OpencodeWorkflowStatus::Running,
            'current_step' => $firstStep,
            'started_at' => now(),
        ]);

        $step = $workflow->steps()->create([
            'step_name' => $firstStep,
            'command' => $firstStep,
            'status' => OpencodeWorkflowStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = $this->manager->startAsyncSession(
                $project,
                $this->stepPrompt($firstStep, $requirement),
                $this->agentOptions(is_string($agent) ? $agent : null),
            );
        } catch (OpencodeException $e) {
            $workflow->update(['status' => OpencodeWorkflowStatus::Failed]);
            $step->update(['status' => OpencodeWorkflowStatus::Failed]);

            return $this->formatError($e);
        }

        if ($result['sessionId'] !== null) {
            $workflow->update(['opencode_session_id' => $result['sessionId']]);
        }

        return sprintf(
            '%sWorkflow #%d started in %s (template: %s). Step 1/%d: %s. OpenCode session: %s. Use OpencodeAdvanceWorkflowTool to run the next step when this one finishes.',
            $stoppedNote,
            $workflow->id,
            $project,
            $workflowTemplate->value,
            count($steps),
            $firstStep,
            $result['sessionId'] ?? 'pending',
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->required()
                ->description('Absolute path to the project directory, inside the configured root projects path.'),
            'template' => $schema->string()
                ->default('default')
                ->enum(['default', 'feature', 'bugfix', 'refactor'])
                ->description('Workflow template controlling the step sequence.'),
            'requirement' => $schema->string()
                ->required()
                ->description("The user's requirement to work on."),
            'agent' => $schema->string()
                ->description('Optional opencode agent to run the step (default: orchestrator).'),
            'chat_id' => $schema->integer()
                ->description('Optional Telegram chat id; normally set by the bot context.'),
            'user_id' => $schema->integer()
                ->description('Optional owner user id; normally set by the bot context.'),
        ];
    }

    /**
     * Stop a workflow that was still active for the same chat before starting a
     * new one: abort its opencode session, mark it (and its running step) as
     * stopped and notify the owner that it was superseded.
     */
    protected function stopActiveWorkflow(OpencodeWorkflow $workflow): void
    {
        if ($workflow->opencode_session_id !== null) {
            try {
                $this->manager->abort($workflow->opencode_session_id, $workflow->project_path);
            } catch (OpencodeException $e) {
                // The workflow is still stopped in the DB even when the abort fails.
            }
        }

        $workflow->update([
            'status' => OpencodeWorkflowStatus::Stopped,
            'completed_at' => now(),
        ]);

        $currentStep = $workflow->steps()
            ->where('status', OpencodeWorkflowStatus::Running->value)
            ->latest('id')
            ->first();

        if ($currentStep !== null) {
            $currentStep->update([
                'status' => OpencodeWorkflowStatus::Stopped,
                'finished_at' => now(),
            ]);
        }

        $this->notifyOwner($workflow, sprintf(
            'Workflow #%d (template: %s) was stopped because a new workflow was started in this chat.',
            $workflow->id,
            $workflow->template->value,
        ));
    }

    /**
     * Send a notification to the workflow owner's chat via OpencodeNotifier.
     *
     * @return string|null A readable error when the notification could not be sent.
     */
    protected function notifyOwner(OpencodeWorkflow $workflow, string $message): ?string
    {
        try {
            if (! app(OpencodeNotifier::class)->notify($workflow->chat_id, $message)) {
                return 'the Telegram message could not be sent.';
            }

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
