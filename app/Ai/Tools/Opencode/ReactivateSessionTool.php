<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeSessionDismissal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReactivateSessionTool extends OpencodeSessionTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Reactivates a previously "done" opencode session: removes its dismissal so the bot treats the session as active again and reports it again. NOT destructive. Use when the user wants to undo a session they marked as done. Pass the "session_id" (ses_...) or a "query" (session title or project directory) to identify the session. Idempotent: reactivating a session that was not done reports there was nothing to do.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $sessionId = $this->resolveSessionId($request);

        if ($sessionId === null) {
            return 'Error: missing "session_id" argument. Pass the opencode session id or a "query" (title/directory) to identify the session.';
        }

        $deleted = OpencodeSessionDismissal::query()->whereKey($sessionId)->delete();

        if ($deleted === 0) {
            return "Session [{$sessionId}] was not marked as done; nothing to reactivate.";
        }

        return "Session [{$sessionId}] reactivated. The bot will treat it as active again.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()
                ->required()
                ->description('The opencode session id (ses_...).'),
            'query' => $schema->string()
                ->description('Optional session title or project directory to resolve the session when the session id is not known.'),
        ];
    }
}
