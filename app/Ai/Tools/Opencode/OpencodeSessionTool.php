<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionStore;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Shared plumbing for the opencode session lifecycle tools.
 *
 * Sessions are resolved primarily by session id (ses_...). When the id is not
 * passed, an optional "query" (session title or project directory) is matched
 * against the tracked watches so the tools still work when the model only knows
 * the session by name.
 */
abstract class OpencodeSessionTool implements Tool
{
    protected OpencodeSessionManager $manager;

    public function __construct(?OpencodeSessionManager $manager = null)
    {
        $this->manager = $manager ?? app(OpencodeSessionManager::class);
    }

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

        $pattern = '%'.OpencodeSessionStore::escapeLike($query).'%';

        $watch = OpencodeSessionWatch::query()
            ->whereRaw("title LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("project_path LIKE ? ESCAPE '\\'", [$pattern])
            ->latest('id')
            ->first();

        return $watch?->session_id;
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
     * Turn a known opencode failure into a readable, non-throwing message.
     */
    protected function formatError(Throwable $e): string
    {
        return 'Error: opencode failed: '.$e->getMessage();
    }
}
