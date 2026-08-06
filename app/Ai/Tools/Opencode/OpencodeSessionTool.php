<?php

namespace App\Ai\Tools\Opencode;

use App\Services\Opencode\OpencodeSessionManager;
use Laravel\Ai\Contracts\Tool;
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
    use ResolvesOpencodeSession;

    protected OpencodeSessionManager $manager;

    public function __construct(?OpencodeSessionManager $manager = null)
    {
        $this->manager = $manager ?? app(OpencodeSessionManager::class);
    }

    /**
     * Turn a known opencode failure into a readable, non-throwing message.
     */
    protected function formatError(Throwable $e): string
    {
        return 'Error: opencode failed: '.$e->getMessage();
    }
}
