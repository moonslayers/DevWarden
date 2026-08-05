<?php

use App\Ai\Context\VisionWorkflowContext;

beforeEach(function () {
    VisionWorkflowContext::clear();
});

afterEach(function () {
    VisionWorkflowContext::clear();
});

test('a fresh context starts empty', function () {
    expect(VisionWorkflowContext::imagePath())->toBeNull()
        ->and(VisionWorkflowContext::chatId())->toBeNull()
        ->and(VisionWorkflowContext::hasImage())->toBeFalse();
});

test('set binds the image path and chat id', function () {
    VisionWorkflowContext::set('/tmp/photo.png', 123456789);

    expect(VisionWorkflowContext::imagePath())->toBe('/tmp/photo.png')
        ->and(VisionWorkflowContext::chatId())->toBe(123456789)
        ->and(VisionWorkflowContext::hasImage())->toBeTrue();
});

test('set defaults the chat id to null', function () {
    VisionWorkflowContext::set('/tmp/photo.png');

    expect(VisionWorkflowContext::chatId())->toBeNull()
        ->and(VisionWorkflowContext::hasImage())->toBeTrue();
});

test('set with a null image path clears the image binding', function () {
    VisionWorkflowContext::set('/tmp/photo.png', 123);

    VisionWorkflowContext::set(null, 456);

    expect(VisionWorkflowContext::imagePath())->toBeNull()
        ->and(VisionWorkflowContext::hasImage())->toBeFalse()
        ->and(VisionWorkflowContext::chatId())->toBe(456);
});

test('clear resets the context so values do not leak between messages', function () {
    VisionWorkflowContext::set('/tmp/photo.png', 123);

    VisionWorkflowContext::clear();

    expect(VisionWorkflowContext::imagePath())->toBeNull()
        ->and(VisionWorkflowContext::chatId())->toBeNull()
        ->and(VisionWorkflowContext::hasImage())->toBeFalse();
});
