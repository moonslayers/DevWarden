<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeSessionDismissal;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionParser;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads the content of an opencode session so the bot can answer what happened
 * in it or how it ended, without touching the session.
 *
 * The session is resolved by session id or by a query hint, the window is read
 * straight from the opencode SQLite store (never through the MCP manager) and
 * the readable output is capped so a huge transcript never floods the model's
 * context.
 */
class ReadSessionTool extends OpencodeSessionTool
{
    protected OpencodeSessionStore $store;

    /**
     * Upper bound for the total formatted output, in characters.
     */
    private const OUTPUT_CAP = 6000;

    public function __construct(?OpencodeSessionManager $manager = null, ?OpencodeSessionStore $store = null)
    {
        parent::__construct($manager);

        $this->store = $store ?? app(OpencodeSessionStore::class);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Reads the content of an opencode session — the last or first messages, including the tool calls made and the sub-agents invoked — to answer what happened in the session or how it ended ("qué pasó", "en qué acabó"). READ-ONLY: it never sends prompts and never modifies the session. Pass the "session_id" (ses_...) or a "query" (session title or project directory) to identify it, plus optional "direction" ("last" or "first", default last) and "limit" (default 5, max 20).';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $sessionId = trim((string) ($request['session_id'] ?? ''));
        $query = trim((string) ($request['query'] ?? ''));
        $direction = trim((string) ($request['direction'] ?? 'last'));
        $limit = max(1, min(20, (int) ($request['limit'] ?? 5)));

        if ($sessionId === '' && $query === '') {
            return 'Error: missing "session_id" and "query" arguments. Pass the opencode session id or a "query" (title/directory) to identify the session.';
        }

        if ($sessionId === '') {
            $resolved = $this->resolveSessionByQuery($query);

            if ($resolved === null) {
                return "Error: no opencode session found for query '{$query}'.";
            }

            $sessionId = $resolved['id'];
        }

        $parts = $this->store->recentParts($sessionId, $limit, $direction);

        $state = $this->store->sessionState($sessionId);

        if ($parts === []) {
            return "Error: opencode session [{$sessionId}] not found or has no readable content.";
        }

        $dismissed = OpencodeSessionDismissal::query()->whereKey($sessionId)->exists();

        $lines = [];
        $lines[] = 'Session: '.$sessionId;

        if ($state['title'] !== null && $state['title'] !== '') {
            $lines[] = 'Title: '.$state['title'];
        }

        if ($state['directory'] !== null && $state['directory'] !== '') {
            $lines[] = 'Directory: '.$state['directory'];
        }

        $lines[] = 'Status: '.($state['has_running_part'] ? 'working' : 'idle');

        if ($state['last_turn_tool'] === 'question') {
            $lines[] = 'Nota: esperando respuesta — la sesión tiene preguntas pendientes.';
        }

        if ($dismissed) {
            $lines[] = 'Nota: marcada como terminada/inactiva por el usuario.';
        }

        $lines[] = ($direction === 'first' ? 'Primeros ' : 'Últimos ').$limit.' mensajes:';

        foreach ($parts as $index => $part) {
            $lines[] = $this->formatPart($part, $index);
        }

        return $this->capOutput(implode(PHP_EOL, $lines), $direction);
    }

    /**
     * Cap the total formatted output, keeping the end the reader cares about.
     *
     * For direction="last" the newest messages sit at the end of the text, so
     * the tail is preserved and the elided head is marked with a leading
     * ellipsis. For direction="first" the shared parser's head-preserving
     * truncation is kept. Both honor OUTPUT_CAP as the total length.
     */
    private function capOutput(string $text, string $direction): string
    {
        if ($direction === 'first') {
            return (new OpencodeSessionParser)->truncate($text, self::OUTPUT_CAP);
        }

        if (mb_strlen($text) <= self::OUTPUT_CAP) {
            return $text;
        }

        return '…'.mb_substr($text, -(self::OUTPUT_CAP - 1));
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()
                ->description('The opencode session id (ses_...) to read.'),
            'query' => $schema->string()
                ->description('Optional session title or project directory to resolve the session when the session id is not known.'),
            'direction' => $schema->string()
                ->default('last')
                ->enum(['last', 'first'])
                ->description('Which end of the session to read: "last" (default) reads the most recent messages, "first" the oldest ones.'),
            'limit' => $schema->integer()
                ->default(5)
                ->min(1)
                ->max(20)
                ->description('Maximum number of messages to read (default 5, max 20).'),
        ];
    }

    /**
     * Format one part as a readable, numbered line.
     *
     * The role always comes first, then the type: text parts show their message,
     * tool parts their name, status and truncated input/output, and agent parts
     * their agent name and sub-session id.
     *
     * @param  array<string, mixed>  $part
     */
    private function formatPart(array $part, int $index): string
    {
        $role = $part['role'] !== null ? $part['role'] : 'assistant';
        $line = ($index + 1).'. ['.$role.']';

        if ($part['type'] === 'agent') {
            $line .= ' sub-agent: '.($part['agent_name'] ?? 'unknown');

            if ($part['sub_session_id'] !== null) {
                $line .= ' (session '.$part['sub_session_id'].')';
            }

            return $this->appendPayload($line, $part);
        }

        if ($part['type'] === 'tool') {
            $line .= ' tool: '.($part['tool'] ?? 'unknown');

            if ($part['status'] !== null) {
                $line .= ' ['.$part['status'].']';
            }

            if ($part['agent_name'] !== null) {
                $line .= ' (sub-agent: '.$part['agent_name'];

                if ($part['sub_session_id'] !== null) {
                    $line .= ', session '.$part['sub_session_id'];
                }

                $line .= ')';
            }

            return $this->appendPayload($line, $part);
        }

        $line .= ' '.$this->typeLabel($part['type']);

        return $this->appendPayload($line, $part);
    }

    /**
     * Append the input/output payload of a part when present.
     *
     * Tool and agent parts show their input/output; text-like parts fall back
     * to the input when the text is empty.
     *
     * @param  array<string, mixed>  $part
     */
    private function appendPayload(string $line, array $part): string
    {
        if ($part['type'] === 'tool' || $part['type'] === 'agent') {
            if ($part['input'] !== null && $part['input'] !== '') {
                $line .= ' input: '.$part['input'];
            }

            if ($part['output'] !== null && $part['output'] !== '') {
                $line .= ' output: '.$part['output'];
            }

            return $line;
        }

        $text = $part['text'];

        if ($text === null || $text === '') {
            $text = $part['input'];
        }

        if ($text !== null && $text !== '') {
            $line .= ': '.$text;
        }

        return $line;
    }

    /**
     * Human-friendly label for a part type.
     */
    private function typeLabel(string $type): string
    {
        return match ($type) {
            'text' => 'message',
            'file' => 'file',
            'patch' => 'patch',
            default => $type,
        };
    }
}
