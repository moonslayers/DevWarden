<?php

namespace App\Ai\Tools;

use App\Ai\Agents\VisionAgent;
use App\Ai\Context\VisionWorkflowContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the main agent ask follow-up questions about the image bound to the
 * current turn through the vision sub-agent.
 *
 * The image path and chat id are read from the static VisionWorkflowContext,
 * which the pipeline binds before invoking BotAgent::respond() and clears in a
 * finally, so queue workers never leak one chat's image into another.
 */
class AskVisionTool implements Tool
{
    protected VisionAgent $vision;

    public function __construct(?VisionAgent $vision = null)
    {
        $this->vision = $vision ?? app(VisionAgent::class);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Asks a specific follow-up question about the image the user just sent in the current turn and returns the vision sub-agent\'s answer. Use this when you need more detail, a different interpretation, or a targeted answer about that image than what its description already provided; pass the exact question as the argument.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $imagePath = VisionWorkflowContext::imagePath();

        if ($imagePath === null) {
            return 'No hay imagen en este turno.';
        }

        $question = trim((string) ($request['question'] ?? ''));

        if ($question === '') {
            return 'Error: missing required "question" argument.';
        }

        return $this->vision->ask($question, $imagePath);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->required()
                ->description('The specific question to ask about the image the user sent in the current turn.'),
        ];
    }
}
