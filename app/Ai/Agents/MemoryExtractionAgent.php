<?php

namespace App\Ai\Agents;

use App\Enums\BotMemoryCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;

/**
 * Extracts durable memories from a single Telegram exchange.
 *
 * Stateless on purpose (no conversation memory, no tools): it runs inside a queued
 * capture job and only needs to turn a short transcript into structured JSON. The
 * provider chain is passed in by the caller — never hardcoded.
 */
class MemoryExtractionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the system prompt for the memory extraction agent.
     */
    public function instructions(): Stringable|string
    {
        $categories = implode(', ', BotMemoryCategory::values());

        return 'You are a memory extraction assistant for a personal Telegram development assistant. '
            .'You receive a short conversation exchange between the owner and the assistant and '
            .'extract up to 3 durable, long-lived memories that would still be useful in future '
            .'conversations. Only extract durable facts, preferences, decisions or technical context '
            .'about the owner or their projects. Skip greetings, small talk, one-off requests and '
            .'transient chatter. Write every summary in SPANISH by default; the owner mixes Spanish '
            .'and English, so keep a quoted phrase or technical term in its original language when '
            .'that feels natural, but prefer Spanish. `category` must be one of: '.$categories.'. '
            .'`importance` is an integer from 1 to 10 (10 = most '
            .'valuable to remember). If nothing durable is present, return an empty `memories` '
            .'array. Never invent facts that are not in the exchange. '
            .'Respond with a single JSON object only, using exactly this shape: '
            .'{"memories":[{"summary": string, "category": "technical_context|decision|user_preference|fact", "importance": int from 1 to 10}]} '
            .'with 0 to 3 items (an empty array when nothing durable is present). '
            .'No markdown fences, no commentary around the JSON.';
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * The opencode.ai "Console Go" gateway rejects `response_format.type =
     * json_schema` (the SDK's default for HasStructuredOutput) with HTTP 400, but
     * accepts `json_object`. The schema is therefore also embedded in
     * instructions() so the model still knows the exact JSON shape.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        if (str_contains($name, 'openai-compatible')) {
            return ['response_format' => ['type' => 'json_object']];
        }

        return [];
    }

    /**
     * Keep enough headroom for the reasoning model: deepseek-v4-flash fills
     * `reasoning_content` before `content`, so a low cap can truncate the JSON
     * (finish_reason=length) and the extraction would come back empty.
     */
    public function maxTokens(): int
    {
        return 1000;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'memories' => $schema->array()
                ->min(0)
                ->max(3)
                ->items($schema->object([
                    'summary' => $schema->string()->required(),
                    'category' => $schema->string()->required()->enum(BotMemoryCategory::values()),
                    'importance' => $schema->integer()->required()->min(1)->max(10),
                ]))
                ->required(),
        ];
    }

    /**
     * Invoke the agent over a transcript and return the extracted memories.
     *
     * Entries with an empty or non-string summary are dropped, categories are
     * constrained to the allowed list and importance is clamped to 1-10 so a
     * provider that bends the schema cannot corrupt the stored data.
     *
     * @param  list<string>  $provider
     * @return list<array{summary: string, category: string, importance: int}>
     */
    public function extract(string $transcript, array $provider): array
    {
        $response = $this->prompt($transcript, provider: $provider);

        $raw = $response instanceof StructuredAgentResponse
            ? ($response['memories'] ?? null)
            : $this->decodeMemories($response->text);

        if (! is_array($raw)) {
            return [];
        }

        // Hard cap: a provider that bends the schema max(3) cannot produce more
        // than three embeddings or rows per exchange.
        $raw = array_slice(array_values($raw), 0, 3);

        $memories = [];

        foreach ($raw as $candidate) {
            $memory = $this->normalize($candidate);

            if ($memory !== null) {
                $memories[] = $memory;
            }
        }

        return $memories;
    }

    /**
     * Decode a memories array from a plain text response.
     *
     * Defensive fallback: the openai-compatible `json_object` path still yields a
     * StructuredAgentResponse, but a gateway that returns prose (or wraps the
     * JSON in markdown fences) must degrade to an empty list, never throw.
     *
     * @return array<string, mixed>|null
     */
    private function decodeMemories(string $text): ?array
    {
        $json = preg_replace('/^```(?:json)?\s*/i', '', trim($text)) ?? '';
        $json = preg_replace('/\s*```$/', '', $json) ?? '';

        $decoded = json_decode($json, true);

        return is_array($decoded) && isset($decoded['memories']) && is_array($decoded['memories'])
            ? $decoded['memories']
            : null;
    }

    /**
     * Normalize a raw candidate memory or return null when it should be skipped.
     *
     * Public so it can be unit-tested directly; it is a pure function.
     *
     * @return array{summary: string, category: string, importance: int}|null
     */
    public function normalize(mixed $candidate): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $summary = isset($candidate['summary']) && is_string($candidate['summary'])
            ? trim($candidate['summary'])
            : '';

        if ($summary === '') {
            return null;
        }

        $category = isset($candidate['category']) && is_string($candidate['category'])
            && BotMemoryCategory::tryFrom($candidate['category']) !== null
            ? $candidate['category']
            : BotMemoryCategory::Fact->value;

        $importance = isset($candidate['importance']) && is_numeric($candidate['importance'])
            ? max(1, min(10, (int) $candidate['importance']))
            : 5;

        return [
            'summary' => $summary,
            'category' => $category,
            'importance' => $importance,
        ];
    }
}
