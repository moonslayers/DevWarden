<?php

use App\Ai\Agents\BotAgent;
use App\Ai\Tools\CurrentDateTool;
use App\Ai\Tools\DownloadImageTool;
use App\Ai\Tools\DuckDuckGoImageSearchTool;
use App\Ai\Tools\DuckDuckGoSearchTool;
use App\Ai\Tools\FetchWebPageTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Tests\TestCase;

uses(TestCase::class);

test('BotAgent implements the HasTools contract', function () {
    expect(BotAgent::class)->toImplement(HasTools::class);
});

test('tools returns the five AI tools available to the agent', function () {
    $tools = iterator_to_array(app(BotAgent::class)->tools());

    expect($tools)->toHaveCount(5)
        ->and(array_map(fn ($tool): string => $tool::class, $tools))->toBe([
            CurrentDateTool::class,
            DuckDuckGoSearchTool::class,
            FetchWebPageTool::class,
            DuckDuckGoImageSearchTool::class,
            DownloadImageTool::class,
        ]);
});

test('every tool exposed by tools implements the Tool contract', function () {
    foreach (iterator_to_array(app(BotAgent::class)->tools()) as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});
