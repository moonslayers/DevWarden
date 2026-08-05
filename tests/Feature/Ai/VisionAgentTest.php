<?php

use App\Ai\Agents\VisionAgent;
use App\Ai\Context\VisionWorkflowContext;
use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Models\AiProvider;
use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->imagePath = tempnam(sys_get_temp_dir(), 'vision_test_').'.png';

    file_put_contents($this->imagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    ));
});

afterEach(function () {
    VisionWorkflowContext::clear();
    SubAgentUsageLog::flushEventListeners();

    if (is_string($this->imagePath) && is_file($this->imagePath)) {
        @unlink($this->imagePath);
    }
});

test('describe uses the dedicated sub-agent provider and model and records usage', function () {
    $provider = AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    $subAgent = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => $provider->id,
        'model' => 'gpt-4o',
        'sort_order' => 0,
    ]);

    VisionWorkflowContext::set($this->imagePath, 123456789);

    VisionAgent::fake(['Una descripción detallada de la imagen.']);

    $text = app(VisionAgent::class)->describe($this->imagePath, 'El usuario pregunta por el diagrama de arquitectura.');

    expect($text)->toBe('Una descripción detallada de la imagen.');

    VisionAgent::assertPrompted(function ($prompt): bool {
        return $prompt->provider()->name() === 'openai'
            && $prompt->model === 'gpt-4o'
            && $prompt->attachments->contains(
                fn ($attachment): bool => $attachment instanceof Image && $attachment->path === $this->imagePath
            )
            && str_contains($prompt->prompt, 'diagrama de arquitectura');
    });

    $log = SubAgentUsageLog::query()->where('sub_agent_id', $subAgent->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->kind)->toBe('describe')
        ->and($log->chat_id)->toBe(123456789);
});

test('ask uses the system provider chain and records usage with kind ask', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    $subAgent = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'ai_provider_id' => null,
        'sort_order' => 0,
    ]);

    VisionAgent::fake(['La respuesta.']);

    $text = app(VisionAgent::class)->ask('¿Qué versión de Laravel aparece en la imagen?', $this->imagePath);

    expect($text)->toBe('La respuesta.');

    VisionAgent::assertPrompted(function ($prompt): bool {
        return $prompt->provider()->name() === 'openai'
            && $prompt->attachments->contains(
                fn ($attachment): bool => $attachment instanceof Image && $attachment->path === $this->imagePath
            )
            && str_contains($prompt->prompt, '¿Qué versión de Laravel aparece en la imagen?');
    });

    $log = SubAgentUsageLog::query()->where('sub_agent_id', $subAgent->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->kind)->toBe('ask')
        ->and($log->chat_id)->toBeNull();
});

test('records the token usage reported by the provider', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    $subAgent = BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    VisionAgent::fake([
        new TextResponse(
            'Una descripción.',
            new Usage(promptTokens: 120, completionTokens: 45),
            new Meta('openai', 'gpt-4o'),
        ),
    ]);

    app(VisionAgent::class)->describe($this->imagePath, '');

    $log = SubAgentUsageLog::query()->where('sub_agent_id', $subAgent->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->tokens)->toBe(165);
});

test('throws a clear exception when no active vision sub-agent is configured', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
    ]);

    VisionAgent::fake(['No debería usarse.']);

    expect(fn () => app(VisionAgent::class)->describe($this->imagePath, 'contexto'))
        ->toThrow(RuntimeException::class, 'vision sub-agent');
});

test('instructions returns the sub-agent system prompt when configured', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'system_prompt' => 'Eres un experto en interfaces de usuario.',
        'sort_order' => 0,
    ]);

    expect(app(VisionAgent::class)->instructions())->toBe('Eres un experto en interfaces de usuario.');
});

test('instructions falls back to the default vision persona', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    expect(app(VisionAgent::class)->instructions())->toBe(VisionAgent::DEFAULT_INSTRUCTIONS);
});

test('a failing usage record is logged and does not break the response', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    SubAgentUsageLog::saving(fn (): never => throw new RuntimeException('Simulated database failure.'));

    $log = Log::spy();

    VisionAgent::fake(['Una descripción.']);

    $text = app(VisionAgent::class)->describe($this->imagePath, '');

    expect($text)->toBe('Una descripción.');

    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to record vision usage'));
});
