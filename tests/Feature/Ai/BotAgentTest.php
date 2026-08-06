<?php

use App\Ai\Agents\BotAgent;
use App\Ai\Agents\VisionAgent;
use App\Ai\Context\VisionWorkflowContext;
use App\Ai\Tools\Opencode\AbortSessionTool;
use App\Ai\Tools\Opencode\MarkSessionDoneTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowContext;
use App\Ai\Tools\Opencode\ReactivateSessionTool;
use App\Ai\Tools\Opencode\SearchSessionsTool;
use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\BotSkill;
use App\Models\BotSubAgent;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Models\SubAgentUsageLog;
use App\Models\TelegramChatConversation;
use App\Models\User;
use App\Services\AiConfigSyncer;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\LocalEmbeddingService;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function botAgentSessionState(string $lastTurnTool): array
{
    return [
        'title' => null,
        'directory' => null,
        'time_updated' => null,
        'has_running_part' => true,
        'has_error_part' => false,
        'has_any_part' => true,
        'last_turn_tool' => $lastTurnTool,
    ];
}

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            return [[1.0, 0.0, 0.0, 0.0]];
        }
    });

    $this->imagePath = tempnam(sys_get_temp_dir(), 'bot_agent_vision_').'.png';

    file_put_contents($this->imagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    ));
});

afterEach(function () {
    OpencodeWorkflowContext::clear();
    VisionWorkflowContext::clear();

    if (is_string($this->imagePath) && is_file($this->imagePath)) {
        @unlink($this->imagePath);
    }
});

test('tools include the opencode session lifecycle tools', function () {
    $classes = array_map(
        fn ($tool): string => $tool::class,
        iterator_to_array(app(BotAgent::class)->tools()),
    );

    expect($classes)->toContain(MarkSessionDoneTool::class)
        ->and($classes)->toContain(ReactivateSessionTool::class)
        ->and($classes)->toContain(AbortSessionTool::class)
        ->and($classes)->toContain(SearchSessionsTool::class);
});

test('respond populates the opencode workflow chat context during the prompt and clears it afterwards', function () {
    $seen = null;

    BotAgent::fake(function ($prompt) use (&$seen) {
        $seen = [OpencodeWorkflowContext::chatId(), OpencodeWorkflowContext::userId()];

        return 'Reply from the bot.';
    });

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Reply from the bot.')
        ->and($seen)->toBe([123456789, $this->owner->id])
        ->and(OpencodeWorkflowContext::chatId())->toBeNull()
        ->and(OpencodeWorkflowContext::userId())->toBeNull();
});

test('respond on a new chat persists a TelegramChatConversation with the conversation id', function () {
    BotAgent::fake(['Hello from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hi there', $this->owner);

    expect($reply)->toBe('Hello from the bot.');

    $mapping = TelegramChatConversation::query()->where('chat_id', 123456789)->first();

    expect($mapping)->not->toBeNull()
        ->and($mapping->user_id)->toBe($this->owner->id)
        ->and($mapping->conversation_id)->not->toBeNull();

    expect(Conversation::query()->where('id', $mapping->conversation_id)->exists())->toBeTrue();
});

test('respond on an existing chat resumes the same conversation without creating a new row', function () {
    $existing = TelegramChatConversation::factory()->create([
        'chat_id' => 555555555,
        'user_id' => $this->owner->id,
    ]);

    BotAgent::fake(['Continuing the chat.']);

    $reply = app(BotAgent::class)->respond(555555555, 'Tell me more', $this->owner);

    expect($reply)->toBe('Continuing the chat.')
        ->and(TelegramChatConversation::count())->toBe(1)
        ->and($existing->fresh()->conversation_id)->toBe($existing->conversation_id);

    $stored = ConversationMessage::query()->where('conversation_id', $existing->conversation_id)->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('role')->all())->toBe(['user', 'assistant']);
});

test('respond passes the failover chain from the syncer to the prompt', function () {
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create([
        'api_key' => 'sk-anthropic',
        'failover_order' => 1,
    ]);

    BotAgent::fake(['Fake reply']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Fake reply')
        ->and(app(AiConfigSyncer::class)->chain())->toBe(['openai', 'anthropic']);

    BotAgent::assertPrompted(fn ($prompt): bool => $prompt->provider()->name() === 'openai');
});

test('instructions returns the BotSetting system prompt when configured', function () {
    BotSetting::query()->update(['system_prompt' => 'You are a specialized Telegram development assistant.']);

    expect(app(BotAgent::class)->instructions())->toBe('You are a specialized Telegram development assistant.');
});

test('instructions falls back to the default prompt when BotSetting system prompt is empty', function () {
    BotSetting::query()->update(['system_prompt' => null]);

    $instructions = app(BotAgent::class)->instructions();

    expect($instructions)->toBe(BotAgent::DEFAULT_INSTRUCTIONS)
        ->and($instructions)->not->toBeEmpty();
});

test('respond prepends the skill block when the text matches its trigger keywords', function () {
    BotSkill::factory()->create([
        'name' => 'Opencode Session Orchestration',
        'content' => 'You are the orchestration skill for opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
        'sort_order' => 1,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'usa opencode en s2c para X', $this->owner);

    BotAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '<skill name="Opencode Session Orchestration">')
        && str_contains($prompt->prompt, 'You are the orchestration skill for opencode sessions.')
        && str_contains($prompt->prompt, 'usa opencode en s2c para X'));
});

test('respond does not inject a skill when the text does not match and there is no active workflow', function () {
    BotSkill::factory()->create([
        'name' => 'Opencode Session Orchestration',
        'content' => 'You are the orchestration skill for opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'hola', $this->owner);

    BotAgent::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, '<skill'));
});

test('respond injects a skill when the chat has an active opencode workflow', function (string $status) {
    BotSkill::factory()->create([
        'name' => 'Opencode Session Orchestration',
        'content' => 'You are the orchestration skill for opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    OpencodeWorkflow::factory()->create([
        'chat_id' => 123456789,
        'status' => $status,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'hola', $this->owner);

    BotAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '<skill name="Opencode Session Orchestration">')
        && str_contains($prompt->prompt, 'You are the orchestration skill for opencode sessions.'));
})->with([
    'running' => 'running',
    'waiting confirmation' => 'waiting_confirmation',
]);

test('respond does not inject an inactive skill even when the text matches its keywords', function () {
    BotSkill::factory()->create([
        'name' => 'Disabled Skill',
        'content' => 'You should not see this.',
        'trigger_keywords' => ['opencode'],
        'active' => false,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'usa opencode en s2c para X', $this->owner);

    BotAgent::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, '<skill'));
});

test('respond injects the active opencode sessions block for open TUI sessions', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_a', 'title' => 'Refactor auth', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
        ['id' => 'ses_b', 'title' => 'Fix dashboard', 'directory' => '/home/junior/Projects/s2c', 'time_updated' => now()->subMinutes(5)->getTimestampMs(), 'parent_id' => null],
    ]);
    app()->instance(OpencodeSessionStore::class, $store);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '<active_opencode_sessions>')
            && str_contains($prompt->prompt, 'Refactor auth')
            && str_contains($prompt->prompt, 'Fix dashboard')
            && str_contains($prompt->prompt, 'Hello');
    });
});

test('respond excludes dismissed opencode sessions from the active sessions block', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_active', 'title' => 'Active task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
        ['id' => 'ses_done', 'title' => 'Done task', 'directory' => '/home/junior/Projects/s2c', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
    ]);
    app()->instance(OpencodeSessionStore::class, $store);

    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_done']);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '<active_opencode_sessions>')
            && str_contains($prompt->prompt, 'Active task')
            && ! str_contains($prompt->prompt, 'Done task');
    });
});

test('respond does not inject a block when only sub-agent sessions are active', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_sub', 'title' => 'Sub task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => 'ses_tui'],
    ]);
    app()->instance(OpencodeSessionStore::class, $store);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, '<active_opencode_sessions>'));
});

test('respond does not inject a block when no opencode session is active', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([]);
    app()->instance(OpencodeSessionStore::class, $store);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, '<active_opencode_sessions>'));
});

test('respond marks a session as working when its watch reports it as working', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_work', 'title' => 'Building feature', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
        ['id' => 'ses_idle', 'title' => 'Idle task', 'directory' => '/home/junior/Projects/s2c', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
    ]);
    app()->instance(OpencodeSessionStore::class, $store);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'last_seen_status' => 'working',
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $workingLine = preg_match('/- "Building feature" — .*\(last activity .*, working\)/', $prompt->prompt) === 1;
        $idleLine = preg_match('/- "Idle task" — .*\(last activity .*, idle\)/', $prompt->prompt) === 1;

        return $workingLine && $idleLine;
    });
});

test('respond marks a session as awaiting a question when its last live tool is the question tool', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_question', 'title' => 'Session waiting', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
        ['id' => 'ses_work', 'title' => 'Building feature', 'directory' => '/home/junior/Projects/s2c', 'time_updated' => now()->subMinutes(5)->getTimestampMs(), 'parent_id' => null],
        ['id' => 'ses_idle', 'title' => 'Idle task', 'directory' => '/home/junior/Projects/other', 'time_updated' => now()->subMinutes(10)->getTimestampMs(), 'parent_id' => null],
    ]);
    $store->shouldReceive('sessionState')->andReturnUsing(
        fn (string $id): array => botAgentSessionState($id === 'ses_question' ? 'question' : 'bash'),
    );
    app()->instance(OpencodeSessionStore::class, $store);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'last_seen_status' => 'working',
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return preg_match('/- "Session waiting" — .*\(last activity .*, esperando tu respuesta \(tiene preguntas\)\)/', $prompt->prompt) === 1
            && preg_match('/- "Building feature" — .*\(last activity .*, working\)/', $prompt->prompt) === 1
            && preg_match('/- "Idle task" — .*\(last activity .*, idle\)/', $prompt->prompt) === 1;
    });
});

test('respond keeps the block working when sessionState fails for a session', function () {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_work', 'title' => 'Building feature', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->getTimestampMs(), 'parent_id' => null],
    ]);
    $store->shouldReceive('sessionState')->andThrow(new RuntimeException('Store unavailable.'));
    app()->instance(OpencodeSessionStore::class, $store);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'last_seen_status' => 'working',
    ]);

    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Reply from the bot.');

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '<active_opencode_sessions>')
            && preg_match('/- "Building feature" — .*\(last activity .*, working\)/', $prompt->prompt) === 1
            && ! str_contains($prompt->prompt, 'tiene preguntas');
    });
});

test('respond propagates an exception when every provider in the chain fails', function () {
    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app(BotAgent::class)->respond(123456789, 'Hello', $this->owner))
        ->toThrow(RuntimeException::class, 'All providers are down.');
});

test('respond fills in a missing conversation id on an existing mapping without creating a new row', function () {
    $mapping = TelegramChatConversation::factory()->create([
        'chat_id' => 777777777,
        'user_id' => $this->owner->id,
        'conversation_id' => null,
    ]);

    BotAgent::fake(['Filled conversation.']);

    $reply = app(BotAgent::class)->respond(777777777, 'Hello', $this->owner);

    expect($reply)->toBe('Filled conversation.')
        ->and(TelegramChatConversation::count())->toBe(1)
        ->and($mapping->fresh()->conversation_id)->not->toBeNull();

    expect(Conversation::query()->where('id', $mapping->fresh()->conversation_id)->exists())->toBeTrue();
});

test('respond fails over to the next provider when the first one is rate limited', function () {
    AiProvider::factory()->forType(AiProviderType::Anthropic)->create([
        'api_key' => 'sk-anthropic',
        'model_text' => 'claude-3-5-haiku-latest',
        'failover_order' => 1,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit exceeded.']], 429),
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-3-5-haiku-latest',
            'content' => [['type' => 'text', 'text' => 'Reply from the fallback provider.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ]),
    ]);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Reply from the fallback provider.');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.openai.com/v1/responses'));
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.anthropic.com/v1/messages'));
});

test('respond prepends the vision description block when an image arrives with an active vision sub-agent', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    VisionAgent::fake(['La imagen muestra un diagrama de arquitectura.']);
    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, '¿Qué se ve en la imagen?', $this->owner, $this->imagePath);

    expect($reply)->toBe('Reply from the bot.');

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '<image_description>')
            && str_contains($prompt->prompt, 'La imagen muestra un diagrama de arquitectura.')
            && $prompt->attachments->isEmpty();
    });
});

test('respond attaches the image when no active vision sub-agent is configured', function () {
    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, '¿Qué se ve en la imagen?', $this->owner, $this->imagePath);

    expect($reply)->toBe('Reply from the bot.');

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->attachments->count() === 1
            && ! str_contains($prompt->prompt, '<image_description>');
    });
});

test('respond does not touch the vision pipeline when no image is sent', function () {
    VisionAgent::fake(['Should not be used.']);
    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'Hello', $this->owner);

    expect($reply)->toBe('Reply from the bot.');

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->attachments->isEmpty()
            && ! str_contains($prompt->prompt, '<image_description>');
    });

    VisionAgent::assertNeverPrompted();
});

test('respond clears the vision context even when the prompt throws', function () {
    VisionWorkflowContext::set($this->imagePath, 123456789);

    BotAgent::fake(fn (): never => throw new RuntimeException('All providers are down.'));

    expect(fn () => app(BotAgent::class)->respond(123456789, 'Hello', $this->owner, $this->imagePath))
        ->toThrow(RuntimeException::class, 'All providers are down.');

    expect(VisionWorkflowContext::hasImage())->toBeFalse()
        ->and(VisionWorkflowContext::imagePath())->toBeNull()
        ->and(VisionWorkflowContext::chatId())->toBeNull();
});

test('respond degrades gracefully when the vision description fails', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $log = Log::spy();

    VisionAgent::fake(fn (): never => throw new RuntimeException('Vision service unavailable.'));
    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, '¿Qué se ve en la imagen?', $this->owner, $this->imagePath);

    expect($reply)->toBe('Reply from the bot.')
        ->and(VisionWorkflowContext::hasImage())->toBeFalse();

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return ! str_contains($prompt->prompt, '<image_description>')
            && $prompt->attachments->count() === 1;
    });

    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to describe image'));
});

test('respond records the vision describe usage with the correct chat id', function () {
    $vision = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    VisionAgent::fake(['La imagen muestra un diagrama de arquitectura.']);
    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, '¿Qué se ve en la imagen?', $this->owner, $this->imagePath);

    expect($reply)->toBe('Reply from the bot.');

    $log = SubAgentUsageLog::query()->where('kind', 'describe')->first();

    expect($log)->not->toBeNull()
        ->and($log->sub_agent_id)->toBe($vision->id)
        ->and($log->chat_id)->toBe(123456789);

    expect(VisionWorkflowContext::chatId())->toBeNull();
});

test('respond attaches the raw image when the vision description fails', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    Log::spy();

    VisionAgent::fake(fn (): never => throw new RuntimeException('Vision service unavailable.'));
    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, '¿Qué se ve en la imagen?', $this->owner, $this->imagePath);

    BotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->attachments->count() === 1
            && ! str_contains($prompt->prompt, '<image_description>');
    });
});

/**
 * Regression test: guards the beforeEach EmbeddingService stub.
 *
 * Without the stub, the container resolves the real LocalEmbeddingService,
 * which loads the 138 MB ONNX model via onnxruntime FFI on every respond()
 * (~260-280 MB native memory per test) and spikes the suite to ~5 GB of RAM.
 * If someone removes the stub from beforeEach, this test fails.
 */
test('resolves a stubbed EmbeddingService instead of the real LocalEmbeddingService', function () {
    expect(app(EmbeddingService::class))
        ->not->toBeInstanceOf(LocalEmbeddingService::class);
});
