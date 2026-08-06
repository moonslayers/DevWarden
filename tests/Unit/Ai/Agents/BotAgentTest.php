<?php

use App\Ai\Agents\BotAgent;
use App\Ai\Tools\CurrentDateTool;
use App\Ai\Tools\DownloadImageTool;
use App\Ai\Tools\DuckDuckGoImageSearchTool;
use App\Ai\Tools\DuckDuckGoSearchTool;
use App\Ai\Tools\FetchWebPageTool;
use App\Ai\Tools\Opencode\AbortSessionTool;
use App\Ai\Tools\Opencode\MarkSessionDoneTool;
use App\Ai\Tools\Opencode\OpencodeAdvanceWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeAskTool;
use App\Ai\Tools\Opencode\OpencodeStartWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeStopWorkflowTool;
use App\Ai\Tools\Opencode\OpencodeWorkflowStatusTool;
use App\Ai\Tools\Opencode\ReactivateSessionTool;
use App\Ai\Tools\Opencode\SearchSessionsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('BotAgent implements the HasTools contract', function () {
    expect(BotAgent::class)->toImplement(HasTools::class);
});

test('tools returns the fourteen AI tools available to the agent', function () {
    $tools = iterator_to_array(app(BotAgent::class)->tools());

    expect($tools)->toHaveCount(14)
        ->and(array_map(fn ($tool): string => $tool::class, $tools))->toBe([
            CurrentDateTool::class,
            DuckDuckGoSearchTool::class,
            FetchWebPageTool::class,
            DuckDuckGoImageSearchTool::class,
            DownloadImageTool::class,
            OpencodeStartWorkflowTool::class,
            OpencodeAdvanceWorkflowTool::class,
            OpencodeWorkflowStatusTool::class,
            OpencodeStopWorkflowTool::class,
            OpencodeAskTool::class,
            MarkSessionDoneTool::class,
            ReactivateSessionTool::class,
            AbortSessionTool::class,
            SearchSessionsTool::class,
        ]);
});

test('every tool exposed by tools implements the Tool contract', function () {
    foreach (iterator_to_array(app(BotAgent::class)->tools()) as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});
