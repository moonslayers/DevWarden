<?php

namespace App\Enums;

/**
 * The memory categories accepted by the extraction schema, repository and UI.
 *
 * Single source of truth for the category whitelist so agents, validation,
 * factories and the settings page cannot drift apart.
 */
enum BotMemoryCategory: string
{
    case TechnicalContext = 'technical_context';
    case Decision = 'decision';
    case UserPreference = 'user_preference';
    case Fact = 'fact';

    /**
     * The raw string values, as persisted in bot_memories.category.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category): string => $category->value, self::cases());
    }

    /**
     * The human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::TechnicalContext => 'Technical context',
            self::Decision => 'Decision',
            self::UserPreference => 'User preference',
            self::Fact => 'Fact',
        };
    }

    /**
     * Map of raw value to human-readable label, for the settings UI.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $category) {
            $labels[$category->value] = $category->label();
        }

        return $labels;
    }
}
