<?php

namespace App\Console\Commands;

use App\Enums\OpencodeConfirmationMode;
use App\Enums\OpencodeWorkflowStatus;
use App\Models\OpencodeWorkflow;
use App\Models\OpencodeWorkflowStep;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionParser;
use App\Services\Opencode\OpencodeSessionWatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('opencode:monitor')]
#[Description('Monitor running opencode sessions and notify the owner via Telegram when a step finishes')]
class OpencodeMonitor extends Command
{
    /**
     * Max characters kept for a step summary and the notification message.
     */
    private const SUMMARY_MAX_LENGTH = 2000;

    /**
     * Consecutive check failures before a workflow is marked as failed.
     */
    private const MAX_FAILURES = 3;

    /**
     * Step names whose finished summary is presented as the plan executive summary.
     *
     * @var list<string>
     */
    private const PLAN_STEPS = ['plan', 'plan-feature', 'plan-bugfix', 'plan-refactor'];

    public function handle(OpencodeSessionManager $manager, OpencodeNotifier $notifier, OpencodeSessionParser $parser, OpencodeSessionWatcher $watcher): int
    {
        $running = OpencodeWorkflow::query()
            ->where('status', OpencodeWorkflowStatus::Running)
            ->get();

        try {
            $watcher->check();

            foreach ($running as $workflow) {
                $this->checkWorkflow($workflow, $manager, $notifier, $parser);
            }
        } finally {
            $manager->disconnect();
        }

        if ($running->isEmpty()) {
            $this->components->info('No running opencode workflows.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Monitored %d opencode workflow(s).', $running->count()));

        return self::SUCCESS;
    }

    private function checkWorkflow(OpencodeWorkflow $workflow, OpencodeSessionManager $manager, OpencodeNotifier $notifier, OpencodeSessionParser $parser): void
    {
        if ($workflow->opencode_session_id === null) {
            $this->handleCheckFailure($workflow, 'El workflow no tiene un opencode_session_id asociado.', $notifier);

            return;
        }

        try {
            $result = $manager->checkSession($workflow->opencode_session_id, $workflow->project_path);
        } catch (OpencodeException $e) {
            $this->handleCheckFailure($workflow, $e->getMessage(), $notifier);

            return;
        }

        if (! $result['finished']) {
            return;
        }

        $conversation = $manager->conversation($workflow->opencode_session_id, $workflow->project_path);
        $lastAssistant = $parser->lastAssistantText($conversation);
        $summary = $parser->truncate($lastAssistant, self::SUMMARY_MAX_LENGTH);
        $hasQuestions = $parser->hasQuestions($lastAssistant);

        $this->completeCurrentStep($workflow, $summary, $conversation, $parser);

        $steps = $workflow->template->steps();
        $currentIndex = $this->currentStepIndex($steps, $workflow->current_step);
        $nextStep = $currentIndex !== null && isset($steps[$currentIndex + 1])
            ? $steps[$currentIndex + 1]
            : null;

        $message = $this->buildMessage($workflow->current_step, $summary, $hasQuestions, $nextStep);
        $sent = $notifier->notify($workflow->chat_id, $message);

        $workflow->last_summary = $message;

        if ($hasQuestions) {
            $workflow->status = OpencodeWorkflowStatus::WaitingConfirmation;
            $workflow->confirmation_mode = OpencodeConfirmationMode::Answer;
        } elseif ($nextStep !== null) {
            $workflow->status = OpencodeWorkflowStatus::WaitingConfirmation;
            $workflow->confirmation_mode = OpencodeConfirmationMode::Proceed;
        } elseif ($sent) {
            $workflow->status = OpencodeWorkflowStatus::Completed;
            $workflow->confirmation_mode = null;
            $workflow->completed_at = now();
        } else {
            // The final step finished but the notification could not be sent:
            // keep the workflow running so the next tick re-detects the
            // finished session and retries the notification instead of leaving
            // it frozen waiting for a confirmation the monitor never polls.
            $workflow->failure_count++;

            if ($workflow->failure_count >= self::MAX_FAILURES) {
                $this->markFailed($workflow, 'No se pudo enviar la notificación de finalización a Telegram.', $notifier);

                return;
            }

            $workflow->status = OpencodeWorkflowStatus::Running;
        }

        if ($sent) {
            $workflow->failure_count = 0;
        }

        $workflow->save();
    }

    private function handleCheckFailure(OpencodeWorkflow $workflow, string $error, OpencodeNotifier $notifier): void
    {
        $workflow->increment('failure_count');

        if ($workflow->failure_count < self::MAX_FAILURES) {
            return;
        }

        $this->markFailed($workflow, $error, $notifier);
    }

    private function markFailed(OpencodeWorkflow $workflow, string $reason, OpencodeNotifier $notifier): void
    {
        $message = 'El workflow falló tras '.self::MAX_FAILURES.' intentos.'
            ."\n\nError: {$reason}"
            ."\n\nResponde /retry para reintentar o /abort para detener el workflow.";

        Log::warning('Opencode workflow marked as failed.', [
            'workflow_id' => $workflow->id,
            'error' => $reason,
        ]);

        $workflow->forceFill([
            'status' => OpencodeWorkflowStatus::Failed,
            'confirmation_mode' => OpencodeConfirmationMode::DecisionOnFailure,
            'last_summary' => $message,
        ])->save();

        $notifier->notify($workflow->chat_id, $message);
    }

    private function completeCurrentStep(OpencodeWorkflow $workflow, string $summary, string $rawOutput, OpencodeSessionParser $parser): void
    {
        $step = $workflow->current_step !== null
            ? $workflow->steps()
                ->where('step_name', $workflow->current_step)
                ->where('status', OpencodeWorkflowStatus::Running)
                ->first()
            : null;

        $step ??= $workflow->steps()
            ->where('status', OpencodeWorkflowStatus::Running)
            ->orderByDesc('id')
            ->first();

        if ($step === null) {
            return;
        }

        $step->forceFill([
            'summary' => $summary,
            'raw_output' => $parser->truncate($rawOutput, OpencodeWorkflowStep::MAX_RAW_OUTPUT_LENGTH),
            'status' => OpencodeWorkflowStatus::Completed,
            'finished_at' => now(),
        ])->save();
    }

    private function buildMessage(?string $currentStep, string $summary, bool $hasQuestions, ?string $nextStep): string
    {
        $prefix = $this->isPlanStep($currentStep) ? "Plan listo — resumen ejecutivo:\n\n" : '';

        if ($hasQuestions) {
            return $prefix.$summary
                ."\n\nLa sesión tiene preguntas:"
                ."\n\nResponde a las preguntas para continuar, o envía /abort para detener el workflow.";
        }

        if ($nextStep !== null) {
            return $prefix.$summary."\n\n¿Continúo con el paso \"{$nextStep}\"? Responde \"sí\" para continuar.";
        }

        return "Workflow completado.\n\n".$prefix.$summary;
    }

    private function currentStepIndex(array $steps, ?string $currentStep): ?int
    {
        if ($currentStep === null) {
            return 0;
        }

        $index = array_search($currentStep, $steps, true);

        return $index === false ? null : $index;
    }

    private function isPlanStep(?string $step): bool
    {
        return in_array($step, self::PLAN_STEPS, true);
    }
}
