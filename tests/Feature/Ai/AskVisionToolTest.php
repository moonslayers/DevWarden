<?php

use App\Ai\Agents\BotAgent;
use App\Ai\Agents\VisionAgent;
use App\Ai\Context\VisionWorkflowContext;
use App\Ai\Tools\AskVisionTool;
use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Models\AiProvider;
use App\Models\BotSubAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->imagePath = '/tmp/ask-vision-test.png';
});

afterEach(function () {
    VisionWorkflowContext::clear();
});

test('handle asks the vision agent about the image bound to the turn', function () {
    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    VisionWorkflowContext::set($this->imagePath, 123456789);

    VisionAgent::fake(['El diagrama muestra tres servicios.']);

    $result = (new AskVisionTool)->handle(new Request(['question' => '¿Qué servicios aparecen?']));

    expect($result)->toBe('El diagrama muestra tres servicios.');

    VisionAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '¿Qué servicios aparecen?')
            && $prompt->attachments->contains(
                fn ($attachment): bool => $attachment instanceof Image && $attachment->path === $this->imagePath
            );
    });
});

test('handle returns a readable message without calling the vision agent when no image is bound', function () {
    VisionWorkflowContext::clear();

    VisionAgent::fake(['Should not be used.']);

    $result = (new AskVisionTool)->handle(new Request(['question' => '¿Qué hay?']));

    expect($result)->toBe('No hay imagen en este turno.');

    VisionAgent::assertNeverPrompted();
});

test('BotAgent tools include AskVisionTool when an active vision sub-agent exists', function () {
    BotSubAgent::factory()->create([
        'type' => BotSubAgentType::Vision,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $classes = array_map(
        fn ($tool): string => $tool::class,
        iterator_to_array(app(BotAgent::class)->tools()),
    );

    expect($classes)->toContain(AskVisionTool::class);
});

test('BotAgent tools exclude AskVisionTool when no active vision sub-agent exists', function () {
    $classes = array_map(
        fn ($tool): string => $tool::class,
        iterator_to_array(app(BotAgent::class)->tools()),
    );

    expect($classes)->not->toContain(AskVisionTool::class);
});
