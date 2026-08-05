<?php

namespace App\Ai\Tools\Opencode;

use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OpencodeAskTool extends OpencodeWorkflowTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Asks a direct question to opencode in the project directory and returns the answer, or sends a response to the opencode session when the workflow has pending questions for the user. Does NOT advance the workflow: use it as an escape hatch to gather extra information or to answer the questions the session asked. When an active workflow exists its opencode session and project are reused automatically (the question/answer is sent as a reply to that session); otherwise pass the "directory" argument to open a fresh session.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $question = trim((string) ($request['question'] ?? ''));
        $directory = $request['directory'] ?? null;
        $chatId = $this->resolveChatId($request);

        if ($question === '') {
            return 'Error: missing required "question" argument.';
        }

        $workflow = $this->resolveWorkflow($chatId);

        if ($directory === null) {
            if ($workflow === null) {
                return 'Error: no directory given and no active workflow to derive it from. Pass the "directory" argument or start a workflow first.';
            }

            $directory = $workflow->project_path;
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
                ->description('The question to ask opencode.'),
            'directory' => $schema->string()
                ->description('Optional absolute project path. Defaults to the active workflow project.'),
            'chat_id' => $schema->integer()
                ->description('Optional Telegram chat id; normally set by the bot context.'),
        ];
    }
}
