<?php

namespace App\Ai\Tools\Opencode;

use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class AbortSessionTool extends OpencodeSessionTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'ABORTS a real running opencode session via the opencode MCP (opencode_session_abort). DESTRUCTIVE: any running work in the session is cancelled and the session cannot be continued. It does NOT stop the bot from remembering the session — use MarkSessionDoneTool for that. REQUIRES CONFIRMATION: ask the user to confirm, then call again with "confirm" set to true. Pass the "session_id" (ses_...) or a "query" (session title or project directory); "directory" defaults to the session\'s project path when omitted.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! (bool) ($request['confirm'] ?? false)) {
            return 'Error: confirmation required. Ask the user to confirm they want to abort the session (this destroys any running work), then call the tool again with "confirm" set to true.';
        }

        $sessionId = $this->resolveSessionId($request);

        if ($sessionId === null) {
            return 'Error: missing "session_id" argument. Pass the opencode session id or a "query" (title/directory) to identify the session.';
        }

        $directory = $this->resolveDirectory($request, $sessionId);

        if ($directory === null) {
            return 'Error: could not determine the project directory for this session. Pass the "directory" argument.';
        }

        try {
            $result = $this->manager->abort($sessionId, $directory);
        } catch (OpencodeException $e) {
            return $this->formatError($e);
        }

        return "Session [{$sessionId}] aborted in {$directory}. {$result}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()
                ->required()
                ->description('The opencode session id (ses_...) to abort.'),
            'query' => $schema->string()
                ->description('Optional session title or project directory to resolve the session when the session id is not known.'),
            'directory' => $schema->string()
                ->description('Optional absolute project path. Defaults to the session\'s project path (tracked watch or workflow).'),
            'confirm' => $schema->boolean()
                ->default(false)
                ->description('Must be true to abort. Only set it after the user explicitly confirmed.'),
        ];
    }
}
