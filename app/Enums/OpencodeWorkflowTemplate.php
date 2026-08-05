<?php

namespace App\Enums;

enum OpencodeWorkflowTemplate: string
{
    case Default = 'default';
    case Feature = 'feature';
    case Bugfix = 'bugfix';
    case Refactor = 'refactor';

    /**
     * The ordered opencode command sequence for this template.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return match ($this) {
            self::Default => ['context-gather', 'plan', 'execute', 'validate', 'skill-review', 'commit'],
            self::Feature => ['context-gather', 'plan-feature', 'execute', 'validate', 'skill-review', 'commit'],
            self::Bugfix => ['context-gather', 'plan-bugfix', 'execute', 'validate', 'skill-review', 'commit'],
            self::Refactor => ['context-gather', 'plan-refactor', 'execute', 'validate', 'skill-review', 'commit'],
        };
    }
}
