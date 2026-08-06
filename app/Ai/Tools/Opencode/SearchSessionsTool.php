<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeSessionDismissal;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchSessionsTool extends OpencodeSessionTool
{
    protected OpencodeSessionStore $store;

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
        return 'Searches opencode sessions by keyword, INCLUDING old, archived and dismissed ones. Read-only. Use it to find a session the user mentions by title or project directory (e.g. "revisa la sesión vieja de X") so you can later reactivate it or ask it about its work. Returns the session id, title, directory, status, last activity and dismissal state. Pass the "query" keyword and optionally "limit" (default 10, max 25).';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Error: missing "query" argument. Pass a keyword to search opencode sessions by title or directory.';
        }

        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));

        $sessions = $this->store->searchSessions($query, $limit);

        if ($sessions === []) {
            return "No opencode sessions found for '{$query}'.";
        }

        $dismissed = OpencodeSessionDismissal::query()
            ->whereIn('session_id', array_column($sessions, 'id'))
            ->pluck('session_id')
            ->flip();

        $lines = [];

        foreach ($sessions as $index => $session) {
            $lines[] = sprintf(
                '%d. %s — "%s" — %s (%s%s, last activity %s)',
                $index + 1,
                $session['id'],
                $session['title'] ?? '<untitled>',
                $session['directory'] ?? '<unknown directory>',
                $this->statusFor($session),
                isset($dismissed[$session['id']]) ? ', dismissed' : '',
                $this->formatActivity($session['time_updated']),
            );
        }

        return 'Found '.count($lines)." opencode session(s) for '{$query}':".PHP_EOL.implode(PHP_EOL, $lines);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->required()
                ->description('Keyword to search opencode session titles and project directories.'),
            'limit' => $schema->integer()
                ->default(10)
                ->min(1)
                ->max(25)
                ->description('Maximum number of sessions to return (default 10, max 25).'),
        ];
    }

    /**
     * Derive a readable status for a session row: archived wins, then a running
     * part marks an actively working session, otherwise it is idle.
     *
     * @param  array<string, mixed>  $session
     */
    private function statusFor(array $session): string
    {
        if ($session['time_archived'] !== null) {
            return 'archived';
        }

        $state = $this->store->sessionState($session['id']);

        return $state['has_running_part'] ? 'working' : 'idle';
    }

    /**
     * Format an epoch-millis timestamp as a readable local date.
     */
    private function formatActivity(?int $epochMs): string
    {
        if ($epochMs === null) {
            return 'unknown';
        }

        return Carbon::createFromTimestampMs($epochMs)->format('Y-m-d H:i');
    }
}
