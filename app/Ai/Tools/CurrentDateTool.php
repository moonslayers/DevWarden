<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CurrentDateTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the current date and time so the assistant knows what day it is and what time it is.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $now = now();

        return sprintf(
            'Today is %s, %s. The current time is %s (%s, %s).',
            $now->format('l, F j, Y'),
            $now->toISOString(),
            $now->format('H:i:s'),
            $now->getTimezone()->getName(),
            $now->format('P'),
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
