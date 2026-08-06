<?php

namespace App\Services\Opencode;

/**
 * Parses opencode conversation transcripts into role blocks.
 *
 * Stateless helper shared by the monitor and any future monitors of external
 * opencode sessions, so the transcript parsing and question detection logic
 * lives in a single place instead of being duplicated per command.
 */
final class OpencodeSessionParser
{
    /**
     * Placeholder literals that opencode-mcp v1.11 emits as the whole text of an
     * assistant message that carries no real content (e.g. a message that only
     * made tool calls). They are not empty, so lastAssistantText() must skip them
     * and fall back to the previous assistant block with real text. `(no content)`
     * is emitted verbatim when a message has neither text parts nor recognizable
     * tool parts (verified against the installed opencode-mcp `dist/helpers.js`).
     * The "Agent performed N action(s):" template is intentionally NOT listed: it
     * is not a fixed literal (it embeds a count and tool summaries), and those
     * summaries carry real, useful content.
     *
     * @var array<int, string>
     */
    private const NON_DESCRIPTIVE_LITERALS = [
        '(no content)',
    ];

    /**
     * Last assistant block text with real content, falling back to the raw
     * conversation when no assistant block has descriptive text.
     */
    public function lastAssistantText(string $conversation): string
    {
        foreach (array_reverse($this->conversationBlocks($conversation)) as $block) {
            if ($block['role'] === 'assistant'
                && $block['text'] !== ''
                && ! in_array($block['text'], self::NON_DESCRIPTIVE_LITERALS, true)) {
                return $block['text'];
            }
        }

        return trim($conversation);
    }

    /**
     * Split a transcript into its `{role, text}` blocks using the
     * `--- Message N [role] ---` separator pattern. The message id suffix
     * (`(msg_xxx)`) is optional, and a trailing `_cost: ... _` telemetry line
     * is stripped from each block's text.
     *
     * @return array<int, array{role: string, text: string}>
     */
    public function conversationBlocks(string $conversation): array
    {
        $pattern = '/^---\s*Message\s*\d+\s*\[([a-zA-Z]+)\]\s*(?:\(msg_[A-Za-z0-9]+\))?\s*---\s*$/m';
        $tokens = preg_split($pattern, $conversation, -1, PREG_SPLIT_DELIM_CAPTURE);

        $blocks = [];
        $role = null;

        foreach ($tokens as $index => $token) {
            if ($index === 0) {
                continue;
            }

            if ($index % 2 === 1) {
                $role = $token;

                continue;
            }

            $text = trim($token);
            $text = preg_replace('/^_cost:[^\n]*_\s*$/m', '', $text) ?? $text;

            $blocks[] = ['role' => strtolower(trim($role ?? '')), 'text' => trim($text)];
            $role = null;
        }

        return $blocks;
    }

    /**
     * Whether the text reads as a question: ends with `?`/`？`, contains `¿`,
     * or mentions a question word in Spanish or English.
     */
    public function hasQuestions(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return false;
        }

        return str_ends_with($trimmed, '?')
            || str_ends_with($trimmed, '？')
            || str_contains($trimmed, '¿')
            || preg_match('/\b(?:qu[eé]|c[oó]mo|cua?l|d[oó]nde|cu[aá]ndo|por qu[eé]|how|what|which|where|when|why)\b/i', $trimmed) === 1;
    }

    /**
     * Truncate the text to a maximum length, adding an ellipsis character.
     */
    public function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1).'…';
    }
}
