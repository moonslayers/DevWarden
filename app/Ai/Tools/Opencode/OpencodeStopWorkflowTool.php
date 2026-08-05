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

class OpencodeStopWorkflowTool extends OpencodeWorkflowTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Stops the active opencode workflow: aborts the opencode session, marks the workflow (and its current step) as stopped and notifies the owner via Telegram. Use when the user wants to cancel or halt the current workflow. Pass "workflow_id" to stop a specific workflow instead.';
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
            return 'Error: no workflow found to stop.';
        }

        $abortDetails = '';

        if ($workflow->opencode_session_id !== null) {
            try {
                $abortDetails = $this->manager->abort($workflow->opencode_session_id, $workflow->project_path);
            } catch (OpencodeException $e) {
                $abortDetails = 'abort failed: '.$e->getMessage();
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

        $summary = sprintf(
            'Workflow #%d (template: %s) stopped.',
            $workflow->id,
            $workflow->template->value,
        );

        if ($abortDetails !== '') {
            $summary .= ' '.$abortDetails;
        }

        $notificationError = $this->notifyOwner($workflow, $summary);

        if ($notificationError !== null) {
            $summary .= "\nCould not notify the owner via Telegram: ".$notificationError;
        }

        return $summary;
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
     * Send the stop notification to the workflow owner's chat.
     *
     * Reuses OpencodeNotifier so the message goes through the same markdown→HTML
     * TelegramHtmlFormatter pipeline (and parse_mode HTML) as the monitor.
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
