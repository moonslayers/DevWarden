<?php

namespace App\Ai\Tools\Opencode;

use App\Models\OpencodeWorkflow;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OpencodeAskTool extends OpencodeWorkflowTool
{
    use ResolvesOpencodeSession;

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
        return 'Sends a prompt or instruction to an opencode session and returns the outcome. You can work in ANY session — including external/manual TUI sessions listed in <active_opencode_sessions> — by sending it prompts, instructions or step commands. Identify the session with "session_id" (ses_...) or "query" (its title or project directory). Set "blocking" to true to wait for the session\'s answer, or leave it false to dispatch the prompt in the background and continue. When no session is given, the question is sent as a reply to the active workflow\'s session (answering its pending questions), or to a fresh session in "directory" when no workflow exists. Does NOT advance the workflow.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $question = trim((string) ($request['question'] ?? ''));
        $blocking = filter_var($request['blocking'] ?? false, FILTER_VALIDATE_BOOL);
        $chatId = $this->resolveChatId($request);

        if ($question === '') {
            return 'Error: missing required "question" argument.';
        }

        $session = $this->resolveAskSession($request);

        if ($session !== null) {
            return $this->sendToSession($request, $session, $question, $blocking);
        }

        $query = trim((string) ($request['query'] ?? ''));

        if ($query !== '') {
            return "Error: no opencode session matches query '{$query}'.";
        }

        $workflow = $this->resolveWorkflow($chatId);
        $directory = $this->resolveDefaultDirectory($request, $workflow);

        if ($directory === null) {
            return 'Error: no directory given and no active workflow to derive it from. Pass the "directory" argument or start a workflow first.';
        }

        try {
            if ($workflow !== null && $workflow->opencode_session_id !== null) {
                $result = $this->manager->reply(
                    $workflow->opencode_session_id,
                    $directory,
                    $question,
                );

                return $result['message'];
            }

            return $this->manager->ask($directory, $question);
        } catch (OpencodeException $e) {
            return $this->formatError($e);
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->required()
                ->description('The prompt or instruction to send to the opencode session.'),
            'session_id' => $schema->string()
                ->description('Optional opencode session id (ses_...) to send the prompt to. Defaults to the "query" resolution or the active workflow session.'),
            'query' => $schema->string()
                ->description('Optional session title or project directory to resolve the target session when the session id is not known.'),
            'blocking' => $schema->boolean()
                ->default(false)
                ->description('When true, waits for the session\'s answer and returns it. When false (default), dispatches the prompt in the background and returns a confirmation.'),
            'directory' => $schema->string()
                ->description('Optional absolute project path. Defaults to the session\'s project path (tracked watch, workflow, or the session record).'),
            'chat_id' => $schema->integer()
                ->description('Optional Telegram chat id; normally set by the bot context.'),
        ];
    }

    /**
     * Resolve the target session and its directory from the request arguments.
     *
     * An explicit session_id wins and its directory is derived via the base
     * resolveDirectory(); otherwise the query hint is matched against the
     * watches and then the store. Returns null when no session is requested.
     *
     * @return array{id: string, directory: ?string}|null
     */
    private function resolveAskSession(Request $request): ?array
    {
        $sessionId = trim((string) ($request['session_id'] ?? ''));
        $query = trim((string) ($request['query'] ?? ''));
        $explicitDirectory = trim((string) ($request['directory'] ?? ''));

        if ($sessionId !== '') {
            return [
                'id' => $sessionId,
                'directory' => $explicitDirectory !== ''
                    ? $explicitDirectory
                    : $this->resolveDirectory($request, $sessionId),
            ];
        }

        if ($query !== '') {
            $session = $this->resolveSessionByQuery($query);

            if ($session === null) {
                return null;
            }

            if ($explicitDirectory !== '') {
                $session['directory'] = $explicitDirectory;
            }

            return $session;
        }

        return null;
    }

    /**
     * Send the prompt to a resolved session: blocking reply or background
     * dispatch. The explicit directory argument wins over the session's own.
     */
    private function sendToSession(Request $request, array $session, string $question, bool $blocking): Stringable|string
    {
        $explicitDirectory = trim((string) ($request['directory'] ?? ''));
        $directory = $explicitDirectory !== '' ? $explicitDirectory : ($session['directory'] ?? null);

        if ($directory === null || trim($directory) === '') {
            return 'Error: could not determine the project directory for session ['.$session['id'].']. Pass the "directory" argument.';
        }

        try {
            if ($blocking) {
                $result = $this->manager->reply($session['id'], $directory, $question);

                return $result['message'];
            }

            $result = $this->manager->advanceSession($session['id'], $directory, $question);

            return 'Prompt sent to session '.($result['sessionId'] ?? $session['id']).' in the background.';
        } catch (OpencodeException $e) {
            return $this->formatError($e);
        }
    }

    /**
     * Resolve the directory for the no-session path: the explicit argument, or
     * the active workflow project.
     */
    private function resolveDefaultDirectory(Request $request, ?OpencodeWorkflow $workflow): ?string
    {
        $directory = trim((string) ($request['directory'] ?? ''));

        if ($directory !== '') {
            return $directory;
        }

        return $workflow?->project_path;
    }
}
