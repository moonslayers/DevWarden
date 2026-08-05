<?php

use App\Ai\Tools\Opencode\OpencodeWorkflowContext;

test('context starts unbound and returns null values', function () {
    expect(OpencodeWorkflowContext::chatId())->toBeNull()
        ->and(OpencodeWorkflowContext::userId())->toBeNull();
});

test('set binds the chat and user id until clear is called', function () {
    OpencodeWorkflowContext::set(111, 222);

    expect(OpencodeWorkflowContext::chatId())->toBe(111)
        ->and(OpencodeWorkflowContext::userId())->toBe(222);

    OpencodeWorkflowContext::clear();

    expect(OpencodeWorkflowContext::chatId())->toBeNull()
        ->and(OpencodeWorkflowContext::userId())->toBeNull();
});
