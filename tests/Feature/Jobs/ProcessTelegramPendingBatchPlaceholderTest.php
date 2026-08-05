<?php

use App\Ai\Agents\BotAgent;
use App\Enums\AiProviderType;
use App\Jobs\ProcessTelegramPendingBatch;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramPendingMessage;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\ThinkingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['id' => 1, 'owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    app()->instance(TelegramClient::class, mock(TelegramClient::class));
});

test('sends the thinking placeholder before the AI generation', function () {
    Queue::fake();

    TelegramPendingMessage::factory()->create([
        'chat_id' => 123456789,
        'message_id' => 1,
        'text' => 'Hola bot',
        'update_id' => 101,
    ]);

    $order = [];

    $indicator = mock(ThinkingIndicator::class);
    $indicator->shouldReceive('sendPlaceholder')
        ->once()
        ->withArgs(fn ($telegram, $chatId): bool => $chatId === 123456789)
        ->andReturnUsing(function () use (&$order): int {
            $order[] = 'placeholder';

            return 77;
        });
    app()->instance(ThinkingIndicator::class, $indicator);

    BotAgent::fake(function ($prompt) use (&$order): string {
        $order[] = 'prompt';

        return 'Hola desde el bot';
    });

    app()->call([new ProcessTelegramPendingBatch(123456789), 'handle']);

    expect($order)->toBe(['placeholder', 'prompt']);
});
