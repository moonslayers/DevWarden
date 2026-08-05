<?php

namespace App\Ai\Agents;

use App\Ai\Context\VisionWorkflowContext;
use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use App\Services\AiConfigSyncer;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;
use Throwable;

/**
 * The vision sub-agent: describes or answers questions about incoming images.
 *
 * Stateless on purpose (no conversation memory, no tools). It re-reads the
 * active vision sub-agent from the database on every call so provider/model
 * changes take effect without a worker restart, and it re-syncs the AI config
 * before each prompt so long-running queue workers pick up fresh credentials.
 */
class VisionAgent implements Agent
{
    use Promptable;

    /**
     * The fallback system prompt used when no sub-agent system prompt is set.
     */
    public const DEFAULT_INSTRUCTIONS = 'You are a concise, accurate vision assistant. Analyze the attached image carefully before answering, only describe or answer what you can actually see, and respond in the language the user is using.';

    public function __construct(protected AiConfigSyncer $syncer)
    {
        //
    }

    /**
     * Get the system prompt for the vision agent.
     */
    public function instructions(): string
    {
        return BotSubAgent::activeVision()?->system_prompt ?: self::DEFAULT_INSTRUCTIONS;
    }

    /**
     * Describe the image in detail, honoring the user's context.
     */
    public function describe(string $imagePath, string $userContext): string
    {
        $this->syncer->sync();

        $subAgent = $this->requireActiveVision();

        [$provider, $model] = $this->providerAndModelFor($subAgent);

        $response = $this->prompt(
            $this->describePrompt($userContext),
            attachments: [Image::fromPath($imagePath)],
            provider: $provider,
            model: $model,
        );

        $this->recordUsage($subAgent, 'describe', $response);

        return $response->text;
    }

    /**
     * Answer a specific question about the image.
     */
    public function ask(string $question, string $imagePath): string
    {
        $this->syncer->sync();

        $subAgent = $this->requireActiveVision();

        [$provider, $model] = $this->providerAndModelFor($subAgent);

        $response = $this->prompt(
            $this->askPrompt($question),
            attachments: [Image::fromPath($imagePath)],
            provider: $provider,
            model: $model,
        );

        $this->recordUsage($subAgent, 'ask', $response);

        return $response->text;
    }

    /**
     * Get the active vision sub-agent or fail loudly so callers can guard.
     */
    private function requireActiveVision(): BotSubAgent
    {
        $subAgent = BotSubAgent::activeVision();

        if ($subAgent === null) {
            throw new RuntimeException('No active vision sub-agent is configured. Enable a vision sub-agent before using the vision pipeline.');
        }

        return $subAgent;
    }

    /**
     * Resolve the provider and model for the given sub-agent.
     *
     * A sub-agent pinned to a provider uses that provider and its own model (or
     * the provider default); one without a pinned provider falls back to the
     * system failover chain.
     *
     * @return array{0: string|list<string>, 1: string|null}
     */
    private function providerAndModelFor(BotSubAgent $subAgent): array
    {
        if (! $subAgent->usesSystemProvider()) {
            $provider = $subAgent->aiProvider;

            if ($provider !== null) {
                return [$provider->provider->value, $subAgent->model ?? $provider->model_text];
            }
        }

        return [$this->syncer->chain(), $subAgent->model];
    }

    /**
     * Build the describe prompt, embedding the user's context.
     */
    private function describePrompt(string $userContext): string
    {
        $context = trim($userContext);

        $contextBlock = $context === ''
            ? ''
            : PHP_EOL.PHP_EOL.'Context from the user: '.$context;

        return 'Describe the attached image accurately and in detail.'.$contextBlock;
    }

    /**
     * Build the ask prompt for a specific question about the image.
     */
    private function askPrompt(string $question): string
    {
        return 'Answer the following question about the attached image, based only on what the image shows:'.PHP_EOL.PHP_EOL.$question;
    }

    /**
     * Record the usage of a successful vision call, never throwing on failure.
     */
    private function recordUsage(BotSubAgent $subAgent, string $kind, AgentResponse $response): void
    {
        try {
            SubAgentUsageLog::create([
                'sub_agent_id' => $subAgent->id,
                'chat_id' => VisionWorkflowContext::chatId(),
                'kind' => $kind,
                'tokens' => $this->usageTokens($response),
            ]);
        } catch (Throwable $e) {
            Log::warning("Failed to record vision usage for sub-agent [{$subAgent->id}]: {$e->getMessage()}");
        }
    }

    /**
     * Get the total token count reported by the provider, or zero when absent.
     */
    private function usageTokens(AgentResponse $response): int
    {
        return $response->usage->promptTokens + $response->usage->completionTokens;
    }
}
