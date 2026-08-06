<?php

use App\Services\Opencode\OpencodeSessionParser;

test('parses blocks with the real opencode-mcp msg id suffix', function () {
    $transcript = "--- Message 1 [user] (msg_fd3b74dbc001qFb3z2xEcZZ4XX) ---\n"
        ."Go.\n\n"
        ."--- Message 2 [assistant] (msg_abc123XYZ) ---\n"
        .'Here is my plan.';

    $blocks = (new OpencodeSessionParser)->conversationBlocks($transcript);

    expect($blocks)->toBe([
        ['role' => 'user', 'text' => 'Go.'],
        ['role' => 'assistant', 'text' => 'Here is my plan.'],
    ]);
});

test('parses blocks with the legacy separator without a msg id suffix', function () {
    $transcript = "--- Message 1 [user] ---\n"
        ."Go.\n\n"
        ."--- Message 2 [assistant] ---\n"
        .'hello';

    $blocks = (new OpencodeSessionParser)->conversationBlocks($transcript);

    expect($blocks)->toBe([
        ['role' => 'user', 'text' => 'Go.'],
        ['role' => 'assistant', 'text' => 'hello'],
    ]);
});

test('strips the trailing telemetry line from block text', function () {
    $transcript = "--- Message 1 [user] ---\n"
        ."Do it.\n\n"
        ."--- Message 2 [assistant] (msg_abc123XYZ) ---\n"
        ."Done.\n"
        .'_cost: $0.0001 | tokens: 107 in, 15 out_';

    $blocks = (new OpencodeSessionParser)->conversationBlocks($transcript);

    expect($blocks)->toHaveCount(2);
    expect($blocks[1]['text'])->toBe('Done.');
    expect($blocks[1]['text'])->not->toContain('_cost:');
});

test('lastAssistantText resolves from a real format transcript', function () {
    $transcript = "--- Message 1 [user] (msg_user1) ---\n"
        ."Go.\n\n"
        ."--- Message 2 [assistant] (msg_asst1) ---\n"
        ."The answer is 42.\n"
        .'_cost: $0.0005 | tokens: 10 in, 5 out_';

    expect((new OpencodeSessionParser)->lastAssistantText($transcript))->toBe('The answer is 42.');
});

test('lastAssistantText skips the (no content) literal and falls back to the previous assistant text', function () {
    $transcript = "--- Message 1 [assistant] (msg_asst1) ---\n"
        ."Implemented the fix and pushed.\n\n"
        ."--- Message 2 [assistant] (msg_asst2) ---\n"
        .'(no content)';

    expect((new OpencodeSessionParser)->lastAssistantText($transcript))->toBe('Implemented the fix and pushed.');
});

test('lastAssistantText skips consecutive (no content) literals to reach an earlier real assistant', function () {
    $transcript = "--- Message 1 [assistant] (msg_asst1) ---\n"
        ."The real summary.\n\n"
        ."--- Message 2 [assistant] (msg_asst2) ---\n"
        ."(no content)\n\n"
        ."--- Message 3 [assistant] (msg_asst3) ---\n"
        .'(no content)';

    expect((new OpencodeSessionParser)->lastAssistantText($transcript))->toBe('The real summary.');
});

test('lastAssistantText falls back to the raw conversation when only (no content) assistant blocks remain', function () {
    $transcript = "--- Message 1 [assistant] (msg_asst1) ---\n"
        ."(no content)\n\n"
        ."--- Message 2 [assistant] (msg_asst2) ---\n"
        .'(no content)';

    expect((new OpencodeSessionParser)->lastAssistantText($transcript))->toBe(trim($transcript));
});

test('lastAssistantText returns the last real assistant text in a normal transcript', function () {
    $transcript = "--- Message 1 [assistant] (msg_asst1) ---\n"
        ."First answer.\n\n"
        ."--- Message 2 [assistant] (msg_asst2) ---\n"
        .'Final answer.';

    expect((new OpencodeSessionParser)->lastAssistantText($transcript))->toBe('Final answer.');
});
