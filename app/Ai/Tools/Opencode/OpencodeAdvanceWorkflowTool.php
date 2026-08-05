<?php

namespace App\Ai\Tools\Opencode;

use App\Enums\OpencodeWorkflowStatus;
use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OpencodeAdvanceWorkflowTool extends OpencodeWorkflowTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Advances the active opencode workflow to its next step (plan, execute, validate, skill-review, commit) by dispatching that step to the existing opencode session. Use after the current step finishes or when the user asks to continue. Pass "reply_to_session" to answer the questions the session asked before advancing (the answer is sent to the session first, then the next step is dispatched), "next_step" to jump to a specific step, "additional_context" with extra input for the step, or "agent" to override the opencode agent (default: orchestrator).';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $nextStepOverride = $request['next_step'] ?? null;
        $additionalContext = $request['additional_context'] ?? null;
        $replyToSession = trim((string) ($request['reply_to_session'] ?? ''));
        $agent = $request['agent'] ?? null;
        $chatId = $this->resolveChatId($request);

        $workflow = $this->resolveWorkflow($chatId);

        if ($workflow === null) {
            return 'Error: no active workflow found. Start one with OpencodeStartWorkflowTool first.';
        }

        if ($workflow->opencode_session_id === null) {
            return "Error: workflow #{$workflow->id} has no opencode session yet.";
        }

        $replyNote = '';

        if ($replyToSession !== '') {
            try {
                $this->manager->reply(
                    $workflow->opencode_session_id,
                    $workflow->project_path,
                    $replyToSession,
                );
                $replyNote = "Replied to the session's questions. ";
            } catch (OpencodeException $e) {
                return 'Error: could not send the reply to the session: '.$e->getMessage();
            }
        }

        $steps = $workflow->template->steps();
        $nextStep = $this->determineNextStep($steps, $workflow->current_step, $nextStepOverride);

        if ($nextStep === null) {
            if ($nextStepOverride !== null && trim((string) $nextStepOverride) !== '') {
                return 'Error: unknown step "'.trim((string) $nextStepOverride).'". Valid steps: '.implode(', ', $steps).'.';
            }

            return "Error: workflow #{$workflow->id} has no remaining steps.";
        }

        $step = $workflow->steps()->create([
            'step_name' => $nextStep,
            'command' => $nextStep,
            'status' => OpencodeWorkflowStatus::Running,
            'started_at' => now(),
        ]);

        $prompt = $this->stepPrompt(
            $nextStep,
            null,
            $additionalContext !== null ? (string) $additionalContext : null,
        );

        try {
            $result = $this->manager->advanceSession(
                $workflow->opencode_session_id,
                $workflow->project_path,
                $prompt,
                $this->agentOptions(is_string($agent) ? $agent : null),
            );
        } catch (OpencodeException $e) {
            $step->update(['status' => OpencodeWorkflowStatus::Failed]);

            return $this->formatError($e);
        }

        $workflow->update([
            'status' => OpencodeWorkflowStatus::Running,
            'current_step' => $nextStep,
        ]);

        $index = array_search($nextStep, $steps, true) + 1;

        return sprintf(
            '%sAdvanced workflow #%d to step "%s" (%d/%d). OpenCode session: %s.',
            $replyNote,
            $workflow->id,
            $nextStep,
            $index,
            count($steps),
            $result['sessionId'] ?? $workflow->opencode_session_id,
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'next_step' => $schema->string()
                ->description('Optional step to jump to, e.g. "execute". Defaults to the next step in the template sequence.'),
            'additional_context' => $schema->string()
                ->description('Optional extra context to pass to the step.'),
            'reply_to_session' => $schema->string()
                ->description('Optional text with the user\'s answer to the questions the opencode session asked. If given, it is sent to the session before the next step is dispatched.'),
            'agent' => $schema->string()
                ->description('Optional opencode agent to run the step (default: orchestrator).'),
            'chat_id' => $schema->integer()
                ->description('Optional Telegram chat id; normally set by the bot context.'),
        ];
    }

    /**
     * Determine the step to run: an explicit override, or the step that follows
     * the current one in the template sequence.
     *
     * @param  list<string>  $steps
     */
    protected function determineNextStep(array $steps, ?string $currentStep, mixed $nextStepOverride): ?string
    {
        if ($nextStepOverride !== null && trim((string) $nextStepOverride) !== '') {
            $override = trim((string) $nextStepOverride);

            return in_array($override, $steps, true) ? $override : null;
        }

        $index = array_search($currentStep, $steps, true);

        if ($index === false) {
            return $steps[0] ?? null;
        }

        return $steps[$index + 1] ?? null;
    }
}
