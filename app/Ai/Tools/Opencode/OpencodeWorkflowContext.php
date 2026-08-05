<?php

namespace App\Ai\Tools\Opencode;

/**
 * Process-level chat context for the opencode workflow tools.
 *
 * The model cannot know the Telegram chat_id/user_id of the current message,
 * so the pipeline sets these before invoking the agent and the workflow tools
 * read them when no explicit chat_id/user_id argument was passed.
 */
class OpencodeWorkflowContext
{
    private static ?int $chatId = null;

    private static ?int $userId = null;

    /**
     * Bind the context to the Telegram chat currently being answered.
     */
    public static function set(int $chatId, int $userId): void
    {
        self::$chatId = $chatId;
        self::$userId = $userId;
    }

    /**
     * The chat id bound to the current message, if any.
     */
    public static function chatId(): ?int
    {
        return self::$chatId;
    }

    /**
     * The owner user id bound to the current message, if any.
     */
    public static function userId(): ?int
    {
        return self::$userId;
    }

    /**
     * Drop the current binding. Call this after the agent response is done so
     * a long-running queue worker never leaks one chat's context into another.
     */
    public static function clear(): void
    {
        self::$chatId = null;
        self::$userId = null;
    }
}
