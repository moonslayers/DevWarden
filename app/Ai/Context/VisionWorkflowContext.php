<?php

namespace App\Ai\Context;

/**
 * Process-level context for the vision sub-agent.
 *
 * The pipeline binds the incoming Telegram image path and chat id before
 * invoking the vision agent so usage can be recorded per chat, and clears it
 * afterwards so a long-running queue worker never leaks one chat's context
 * into another.
 */
class VisionWorkflowContext
{
    private static ?string $imagePath = null;

    private static ?int $chatId = null;

    /**
     * Bind the image and chat currently being processed, or drop them.
     */
    public static function set(?string $imagePath, ?int $chatId = null): void
    {
        self::$imagePath = $imagePath;
        self::$chatId = $chatId;
    }

    /**
     * The path of the image bound to the current message, if any.
     */
    public static function imagePath(): ?string
    {
        return self::$imagePath;
    }

    /**
     * The chat id bound to the current message, if any.
     */
    public static function chatId(): ?int
    {
        return self::$chatId;
    }

    /**
     * Whether an image is bound to the current message.
     */
    public static function hasImage(): bool
    {
        return self::$imagePath !== null;
    }

    /**
     * Drop the current binding. Call this after the agent response is done so a
     * long-running queue worker never leaks one chat's context into another.
     */
    public static function clear(): void
    {
        self::$imagePath = null;
        self::$chatId = null;
    }
}
