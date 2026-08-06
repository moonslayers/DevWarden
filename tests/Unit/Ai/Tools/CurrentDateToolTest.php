<?php

use App\Ai\Tools\CurrentDateTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

uses(TestCase::class);

test('handle returns a string describing the current date and time', function () {
    $before = now()->format('l, F j, Y');

    $result = (new CurrentDateTool)->handle(new Request);

    $after = now()->format('l, F j, Y');

    expect($result)->toBeString()
        ->toStartWith('Today is ')
        ->toContain('The current time is ');

    expect(str_contains($result, $before) || str_contains($result, $after))->toBeTrue();
});

test('handle works without arguments and ignores unexpected payloads', function () {
    $result = (new CurrentDateTool)->handle(new Request(['limit' => 5]));

    expect($result)->toBeString()
        ->toStartWith('Today is ')
        ->not->toBeEmpty();
});
