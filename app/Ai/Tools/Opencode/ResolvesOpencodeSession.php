<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\OpencodeSessionStore;
use Laravel\Ai\Tools\Request;

/**
 * Shared session resolution for the opencode tools.
 *
 * Sessions are resolved primarily by session id (ses_...). When the id is not
 * passed, an optional "query" (session title or project directory) is matched
 * against the tracked watches first, then against every opencode session in the
 * SQLite store (including archived and dismissed ones).
 */
trait ResolvesOpencodeSession
{
    /**
     * Resolve the target session id from the request arguments.
     *
     * An explicit session_id wins; otherwise the "query" hint is matched against
     * the tracked session watches by title or project path. Returns null when
     * neither yields a session.
     */
    protected function resolveSessionId(Request $request): ?string
    {
        $sessionId = trim((string) ($request['session_id'] ?? ''));
        $query = trim((string) ($request['query'] ?? ''));

        if ($sessionId !== '') {
            return $sessionId;
        }

        if ($query === '') {
            return null;
        }

        return $this->watchByQuery($query)?->session_id;
    }

    /**
     * Resolve the project directory for a session.
     *
     * An explicit "directory" argument wins; otherwise the session's tracked
     * watch project path is used, then its workflow project path. Returns null
     * when none is known.
     */
    protected function resolveDirectory(Request $request, string $sessionId): ?string
    {
        $directory = trim((string) ($request['directory'] ?? ''));

        if ($directory !== '') {
            return $directory;
        }

        $watch = OpencodeSessionWatch::query()->where('session_id', $sessionId)->first();

        if ($watch?->project_path !== null) {
            return $watch->project_path;
        }

        $workflow = OpencodeWorkflow::query()
            ->where('opencode_session_id', $sessionId)
            ->latest('id')
            ->first();

        return $workflow?->project_path;
    }

    /**
     * Resolve a session from a query hint: the tracked watch first, then any
     * opencode session (including archived and dismissed ones) via the store.
     *
     * @return array{id: string, directory: ?string}|null
     */
    protected function resolveSessionByQuery(string $query): ?array
    {
        $watch = $this->watchByQuery($query);

        if ($watch !== null) {
            return ['id' => $watch->session_id, 'directory' => $watch->project_path];
        }

        $sessions = $this->sessionStore()->searchSessions($query, 1);

        $session = $sessions[0] ?? null;

        if ($session === null) {
            return null;
        }

        return ['id' => $session['id'], 'directory' => $session['directory']];
    }

    /**
     * The store to search for untracked sessions; an injected store wins.
     */
    protected function sessionStore(): OpencodeSessionStore
    {
        if (isset($this->store)) {
            return $this->store;
        }

        return app(OpencodeSessionStore::class);
    }

    /**
     * Match a query hint against the tracked session watches.
     */
    private function watchByQuery(string $query): ?OpencodeSessionWatch
    {
        $pattern = '%'.OpencodeSessionStore::escapeLike($query).'%';

        return OpencodeSessionWatch::query()
            ->whereRaw("title LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("project_path LIKE ? ESCAPE '\\'", [$pattern])
            ->latest('id')
            ->first();
    }
}
