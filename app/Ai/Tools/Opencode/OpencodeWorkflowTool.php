<?php

namespace App\Ai\Tools\Opencode;

use App\Enums\OpencodeWorkflowStatus;
use App\Enums\OpencodeWorkflowTemplate;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\OpencodeSessionManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Shared plumbing for the opencode workflow orchestration tools.
 *
 * The session manager is resolved from the container (registered as a
 * singleton) so every tool call of a prompt shares a single MCP client. The
 * step prompt is defaulted to the "orchestrator" agent because the workflow
 * steps (context-gather, plan, execute, ...) are opencode slash commands that
 * the orchestrator agent interprets and runs.
 */
abstract class OpencodeWorkflowTool implements Tool
{
    /**
     * The opencode agent that interprets the workflow slash commands.
     */
    protected const DEFAULT_AGENT = 'orchestrator';

    protected OpencodeSessionManager $manager;

    public function __construct(?OpencodeSessionManager $manager = null)
    {
        $this->manager = $manager ?? app(OpencodeSessionManager::class);
    }

    /**
     * Resolve the Telegram chat id from an explicit argument or the chat context.
     */
    protected function resolveChatId(Request $request): ?int
    {
        $arg = $request['chat_id'] ?? null;

        return $arg !== null ? (int) $arg : OpencodeWorkflowContext::chatId();
    }

    /**
     * Resolve the owner user id from an explicit argument or the chat context.
     */
    protected function resolveUserId(Request $request): ?int
    {
        $arg = $request['user_id'] ?? null;

        return $arg !== null ? (int) $arg : OpencodeWorkflowContext::userId();
    }

    /**
     * Find the workflow to act on: a specific one or the most recent active.
     *
     * When a chat id is known the active workflow is scoped to that chat;
     * otherwise the most recent active workflow across chats is used.
     */
    protected function resolveWorkflow(?int $chatId, ?int $workflowId = null): ?OpencodeWorkflow
    {
        $query = OpencodeWorkflow::query()->latest('id');

        if ($workflowId !== null) {
            return $query->find($workflowId);
        }

        $query->whereIn('status', [
            OpencodeWorkflowStatus::Running->value,
            OpencodeWorkflowStatus::WaitingConfirmation->value,
        ]);

        if ($chatId !== null) {
            $query->where('chat_id', $chatId);
        }

        return $query->first();
    }

    /**
     * Resolve the opencode template enum for a raw template string, or null.
     */
    protected function templateFor(string $template): ?OpencodeWorkflowTemplate
    {
        return OpencodeWorkflowTemplate::tryFrom(strtolower(trim($template)));
    }

    /**
     * Build the instruction prompt that runs a single workflow step.
     *
     * The first step carries the requirement; later steps only name the step
     * because the opencode session already holds the requirement's context.
     */
    protected function stepPrompt(string $step, ?string $requirement = null, ?string $additionalContext = null): string
    {
        $prompt = sprintf('Run your "%s" workflow step', $step);

        if ($requirement !== null && trim($requirement) !== '') {
            $prompt .= " for this requirement:\n\n".trim($requirement);
        } else {
            $prompt .= ' now.';
        }

        if ($additionalContext !== null && trim($additionalContext) !== '') {
            $prompt .= "\n\nAdditional context:\n".trim($additionalContext);
        }

        return $prompt;
    }

    /**
     * The opencode agent options for a step, defaulting to the orchestrator.
     *
     * @return array{agent: string}
     */
    protected function agentOptions(?string $agent): array
    {
        return ['agent' => $agent !== null && trim($agent) !== '' ? trim($agent) : self::DEFAULT_AGENT];
    }

    /**
     * Turn a known opencode failure into a readable, non-throwing message.
     */
    protected function formatError(Throwable $e): string
    {
        return 'Error: opencode failed: '.$e->getMessage();
    }
}
