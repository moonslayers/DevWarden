<?php

use App\Ai\Agents\MemoryExtractionAgent;
use Tests\TestCase;

uses(TestCase::class);

test('normalize passes a valid memory through unchanged', function () {
    $agent = new MemoryExtractionAgent;

    $normalized = $agent->normalize([
        'summary' => 'El usuario prefiere Laravel.',
        'category' => 'user_preference',
        'importance' => 8,
    ]);

    expect($normalized)->toBe([
        'summary' => 'El usuario prefiere Laravel.',
        'category' => 'user_preference',
        'importance' => 8,
    ]);
});

test('normalize trims the summary but keeps its content', function () {
    $normalized = (new MemoryExtractionAgent)->normalize([
        'summary' => '  Prefiere PostgreSQL.  ',
        'category' => 'technical_context',
        'importance' => 5,
    ]);

    expect($normalized['summary'])->toBe('Prefiere PostgreSQL.');
});

test('normalize drops a memory with an empty or non-string summary', function () {
    $agent = new MemoryExtractionAgent;

    expect($agent->normalize(['summary' => '', 'category' => 'fact', 'importance' => 5]))->toBeNull()
        ->and($agent->normalize(['summary' => '   ', 'category' => 'fact', 'importance' => 5]))->toBeNull()
        ->and($agent->normalize(['summary' => 123, 'category' => 'fact', 'importance' => 5]))->toBeNull();
});

test('normalize defaults an invalid category to fact', function () {
    $agent = new MemoryExtractionAgent;

    expect($agent->normalize([
        'summary' => 'Recuerdo válido.',
        'category' => 'malicious_instruction',
        'importance' => 5,
    ])['category'])->toBe('fact')
        ->and($agent->normalize([
            'summary' => 'Sin categoría.',
            'importance' => 5,
        ])['category'])->toBe('fact');
});

test('normalize keeps a known category value unchanged', function () {
    expect((new MemoryExtractionAgent)->normalize([
        'summary' => 'Decisión tomada.',
        'category' => 'decision',
        'importance' => 7,
    ])['category'])->toBe('decision');
});

test('normalize clamps importance above ten to ten', function () {
    expect((new MemoryExtractionAgent)->normalize([
        'summary' => 'Importante.',
        'category' => 'fact',
        'importance' => 42,
    ])['importance'])->toBe(10);
});

test('normalize clamps importance below one to one', function () {
    expect((new MemoryExtractionAgent)->normalize([
        'summary' => 'Poco importante.',
        'category' => 'fact',
        'importance' => -3,
    ])['importance'])->toBe(1);
});

test('normalize falls back to importance five when missing or non-numeric', function () {
    $agent = new MemoryExtractionAgent;

    $missing = $agent->normalize(['summary' => 'Sin importancia.']);
    $nonNumeric = $agent->normalize(['summary' => 'Texto.', 'category' => 'fact', 'importance' => 'alto']);

    expect($missing['importance'])->toBe(5)
        ->and($nonNumeric['importance'])->toBe(5);
});

test('normalize ignores non-array candidates', function () {
    $agent = new MemoryExtractionAgent;

    expect($agent->normalize('summary only'))->toBeNull()
        ->and($agent->normalize(42))->toBeNull()
        ->and($agent->normalize(null))->toBeNull();
});

test('extract truncates more than three memories to three', function () {
    MemoryExtractionAgent::fake([[
        'memories' => collect(range(1, 5))->map(fn (int $i): array => [
            'summary' => "Memoria {$i}.",
            'category' => 'fact',
            'importance' => 5,
        ])->all(),
    ]]);

    $memories = (new MemoryExtractionAgent)->extract('Un intercambio.', ['openai']);

    expect($memories)->toHaveCount(3)
        ->and(array_column($memories, 'summary'))->toBe(['Memoria 1.', 'Memoria 2.', 'Memoria 3.']);
});

test('extract caps the raw candidates at three and normalizes the survivors', function () {
    MemoryExtractionAgent::fake([[
        'memories' => [
            ['summary' => 'Valida 1.', 'category' => 'fact', 'importance' => 5],
            ['summary' => 'Valida 2.', 'category' => 'unknown', 'importance' => 99],
            ['summary' => 'Valida 3.', 'category' => 'decision', 'importance' => 1],
            ['summary' => 'Truncada 4.', 'category' => 'fact', 'importance' => 5],
            ['summary' => 'Truncada 5.', 'category' => 'fact', 'importance' => 5],
        ],
    ]]);

    $memories = (new MemoryExtractionAgent)->extract('Un intercambio.', ['openai']);

    expect($memories)->toHaveCount(3)
        ->and($memories[0]['summary'])->toBe('Valida 1.')
        ->and($memories[1]['category'])->toBe('fact')
        ->and($memories[1]['importance'])->toBe(10)
        ->and($memories[2]['summary'])->toBe('Valida 3.');
});

test('extract returns an empty list when the provider returns no memories array', function () {
    MemoryExtractionAgent::fake([[]]);

    expect((new MemoryExtractionAgent)->extract('Un intercambio.', ['openai']))->toBe([]);
});

test('providerOptions forces json_object for openai-compatible providers', function () {
    $options = (new MemoryExtractionAgent)->providerOptions('openai-compatible');

    expect($options)->toHaveKey('response_format')
        ->and($options['response_format']['type'])->toBe('json_object');
});

test('providerOptions returns no overrides for other providers', function () {
    expect((new MemoryExtractionAgent)->providerOptions('deepseek'))->toBe([]);
});

test('instructions describe the expected JSON shape', function () {
    $instructions = (new MemoryExtractionAgent)->instructions();

    expect($instructions)
        ->toContain('memories')
        ->toContain('technical_context')
        ->toContain('decision')
        ->toContain('user_preference')
        ->toContain('fact');
});

test('maxTokens leaves headroom for the reasoning model', function () {
    expect((new MemoryExtractionAgent)->maxTokens())->toBeGreaterThanOrEqual(800);
});

test('extract parses memories from a plain text JSON response', function () {
    MemoryExtractionAgent::fake(['{"memories":[{"summary":"Del texto plano.","category":"fact","importance":6}]}']);

    $memories = (new MemoryExtractionAgent)->extract('Un intercambio.', ['openai']);

    expect($memories)->toBe([
        ['summary' => 'Del texto plano.', 'category' => 'fact', 'importance' => 6],
    ]);
});

test('extract degrades to an empty list on prose instead of a JSON object', function () {
    MemoryExtractionAgent::fake(['Lo siento, no pude extraer nada.']);

    expect((new MemoryExtractionAgent)->extract('Un intercambio.', ['openai']))->toBe([]);
});
