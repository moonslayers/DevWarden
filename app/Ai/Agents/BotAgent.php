<?php

namespace App\Ai\Agents;

use App\Models\BotSetting;
use App\Models\TelegramChatConversation;
use App\Models\User;
use App\Services\AiConfigSyncer;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

/**
 * The AI agent powering the Telegram bot.
 *
 * One conversation is kept per Telegram chat: the first message starts a
 * conversation for the owner user and its ID is persisted in
 * TelegramChatConversation, later messages resume it with the stored ID.
 */
class BotAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    /**
     * The fallback system prompt used when no BotSetting is configured.
     */
    public const DEFAULT_INSTRUCTIONS = 'You are a helpful, concise development assistant. Provide clear, direct answers with practical examples and avoid unnecessary detail.';

    public function __construct(protected AiConfigSyncer $syncer) {}

    /**
     * Get the system prompt for the bot, from the database when configured.
     */
    public function instructions(): string
    {
        return BotSetting::singleton()->system_prompt ?: self::DEFAULT_INSTRUCTIONS;
    }

    /**
     * Limit the remembered conversation to the configured message depth.
     */
    protected function maxConversationMessages(): int
    {
        return BotSetting::singleton()->max_history_messages;
    }

    /**
     * Reply to a Telegram chat message, persisting conversation memory per chat.
     */
    public function respond(int $chatId, string $text, User $owner): string
    {
        $this->syncer->sync();

        $mapping = TelegramChatConversation::firstOrCreate(
            ['chat_id' => $chatId],
            ['user_id' => $owner->id],
        );

        if ($mapping->conversation_id) {
            $this->continue($mapping->conversation_id, as: $owner);
        } else {
            $this->forUser($owner);
        }

        $response = $this->prompt($text, provider: $this->syncer->chain());

        if ($mapping->conversation_id === null) {
            $mapping->update(['conversation_id' => $this->currentConversation()]);
        }

        return $response->text;
    }
}
