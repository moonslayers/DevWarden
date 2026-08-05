<?php

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard exposes health, activity and usage props without leaking secrets', function () {
    $user = User::factory()->create();

    $apiKey = 'sk-secret-provider-dashboard-test-key';
    $botToken = '123456789:ABCdef_XYZ-secret-dashboard-token';

    AiProvider::factory()->create([
        'api_key' => $apiKey,
        'model_text' => 'gpt-4o-mini',
        'failover_order' => 0,
    ]);

    TelegramSetting::factory()->create([
        'id' => 1,
        'bot_token' => $botToken,
        'polling_enabled' => true,
    ]);

    BotSetting::factory()->create([
        'id' => 1,
        'owner_user_id' => $user->id,
    ]);

    TelegramChatConversation::factory()->create([
        'user_id' => $user->id,
    ]);

    $conversation = Conversation::create([
        'id' => (string) Str::uuid(),
        'participant_type' => 'user',
        'participant_id' => $user->id,
        'title' => 'Dashboard test conversation',
    ]);

    ConversationMessage::create([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversation->id,
        'participant_type' => 'user',
        'participant_id' => $user->id,
        'agent' => 'bot',
        'role' => 'user',
        'content' => 'Hello',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    ConversationMessage::create([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversation->id,
        'participant_type' => 'user',
        'participant_id' => $user->id,
        'agent' => 'bot',
        'role' => 'assistant',
        'content' => 'Hi there',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'reasoning_tokens' => 5],
        'meta' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
    ]);

    ConversationMessage::create([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversation->id,
        'participant_type' => 'user',
        'participant_id' => $user->id,
        'agent' => 'bot',
        'role' => 'assistant',
        'content' => 'Three days ago',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'reasoning_tokens' => 0],
        'meta' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        'created_at' => now()->subDays(3),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('health.providers.0.provider', 'openai')
            ->where('health.providers.0.has_credentials', true)
            ->missing('health.providers.0.api_key')
            ->where('health.telegram.bot_configured', true)
            ->where('health.owner.name', $user->name)
            ->where('activity.total_conversations', 1)
            ->where('activity.total_messages', 3)
            ->where('activity.linked_chats', 1)
            ->where('activity.user_messages', 1)
            ->where('activity.assistant_messages', 2)
            ->has('activity.messages_by_day.labels', 14)
            ->has('activity.messages_by_day.user', 14)
            ->has('activity.messages_by_day.assistant', 14)
            ->where('activity.messages_by_day.user.13', 1)
            ->where('activity.messages_by_day.assistant.13', 1)
            ->where('activity.messages_by_day.assistant.10', 1)
            ->where('activity.messages_by_day.user.0', 0)
            ->where('usage.total_tokens.prompt', 11)
            ->where('usage.total_tokens.completion', 22)
            ->where('usage.total_tokens.reasoning', 5)
            ->has('usage.tokens_by_day.labels', 14)
            ->has('usage.tokens_by_day.prompt', 14)
            ->has('usage.tokens_by_day.completion', 14)
            ->where('usage.tokens_by_day.prompt.13', 10)
            ->where('usage.tokens_by_day.completion.13', 20)
            ->where('usage.tokens_by_day.prompt.10', 1)
            ->where('usage.tokens_by_day.completion.10', 2)
            ->has('usage.by_provider', 1)
            ->where('usage.by_provider.0.provider', 'openai')
            ->where('usage.by_provider.0.prompt_tokens', 11)
            ->where('usage.by_provider.0.completion_tokens', 22)
            ->where('usage.by_provider.0.messages', 2)
            ->has('usage.by_model', 1)
            ->where('usage.by_model.0.model', 'gpt-4o-mini')
            ->where('usage.by_model.0.prompt_tokens', 11)
            ->where('usage.by_model.0.completion_tokens', 22)
            ->where('usage.by_model.0.messages', 2),
        );

    expect($response->getContent())->not->toContain($apiKey);
    expect($response->getContent())->not->toContain($botToken);
});

test('dashboard reports a null owner when no owner user is configured', function () {
    $user = User::factory()->create();

    BotSetting::factory()->create([
        'id' => 1,
        'owner_user_id' => null,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.owner', null)
        );
});

test('dashboard flags providers with empty credentials as not having them', function () {
    $user = User::factory()->create();

    AiProvider::factory()->create([
        'provider' => AiProviderType::OpenAI,
        'api_key' => null,
        'failover_order' => 0,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.providers.0.provider', 'openai')
            ->where('health.providers.0.has_credentials', false)
            ->missing('health.providers.0.api_key')
        );

    expect($response->getContent())->not->toContain('api_key');
});

test('dashboard includes disabled providers flagged as disabled without leaking credentials', function () {
    $user = User::factory()->create();
    $apiKey = 'sk-secret-disabled-provider-dashboard-test-key';

    AiProvider::factory()->create([
        'provider' => AiProviderType::OpenAI,
        'is_enabled' => false,
        'api_key' => $apiKey,
        'failover_order' => 0,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.providers.0.provider', 'openai')
            ->where('health.providers.0.is_enabled', false)
            ->where('health.providers.0.has_credentials', true)
            ->missing('health.providers.0.api_key')
        );

    expect($response->getContent())->not->toContain($apiKey);
});

test('dashboard handles an empty dataset with zero-filled series', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.providers', [])
            ->where('health.owner', null)
            ->where('activity.total_conversations', 0)
            ->where('activity.total_messages', 0)
            ->where('activity.linked_chats', 0)
            ->where('activity.user_messages', 0)
            ->where('activity.assistant_messages', 0)
            ->has('activity.messages_by_day.labels', 14)
            ->where('activity.messages_by_day.labels.13', Carbon::today()->toDateString())
            ->where('activity.messages_by_day.user', array_fill(0, 14, 0))
            ->where('activity.messages_by_day.assistant', array_fill(0, 14, 0))
            ->where('usage.total_tokens.prompt', 0)
            ->where('usage.total_tokens.completion', 0)
            ->where('usage.total_tokens.reasoning', 0)
            ->has('usage.tokens_by_day.labels', 14)
            ->where('usage.tokens_by_day.labels.13', Carbon::today()->toDateString())
            ->where('usage.tokens_by_day.prompt', array_fill(0, 14, 0))
            ->where('usage.tokens_by_day.completion', array_fill(0, 14, 0))
            ->where('usage.by_provider', [])
            ->where('usage.by_model', [])
        );
});
