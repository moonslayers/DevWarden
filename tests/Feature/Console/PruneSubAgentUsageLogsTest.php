<?php

use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

/**
 * Create a usage log with the given creation date, since created_at is not
 * mass assignable.
 */
function subAgentUsageLogWithCreatedAt(int $subAgentId, string $kind, Carbon $createdAt): SubAgentUsageLog
{
    $log = new SubAgentUsageLog([
        'sub_agent_id' => $subAgentId,
        'kind' => $kind,
        'tokens' => 10,
    ]);
    $log->created_at = $createdAt;
    $log->save();

    return $log;
}

test('deletes usage logs older than the default 90 days and keeps the rest', function () {
    $subAgent = BotSubAgent::factory()->create();

    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(91));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'ask', Carbon::now()->subDays(120));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(90));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'ask', Carbon::now()->subDays(1));

    artisan('subagents:prune-usage')
        ->expectsOutputToContain('Deleted 2 sub-agent usage log(s) older than 90 day(s).')
        ->assertSuccessful();

    expect(SubAgentUsageLog::query()->count())->toBe(2);
    expect(SubAgentUsageLog::query()->pluck('created_at')->map->toDateString()->all())->toContain(Carbon::now()->subDays(90)->toDateString());
    expect(SubAgentUsageLog::query()->pluck('created_at')->map->toDateString()->all())->toContain(Carbon::now()->subDays(1)->toDateString());
});

test('respects the --days override', function () {
    $subAgent = BotSubAgent::factory()->create();

    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(31));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'ask', Carbon::now()->subDays(30));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(5));

    artisan('subagents:prune-usage', ['--days' => 30])
        ->expectsOutputToContain('Deleted 1 sub-agent usage log(s) older than 30 day(s).')
        ->assertSuccessful();

    expect(SubAgentUsageLog::query()->count())->toBe(2);
});

test('keeps logs created exactly on the cutoff', function () {
    $subAgent = BotSubAgent::factory()->create();

    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(90));
    subAgentUsageLogWithCreatedAt($subAgent->id, 'ask', Carbon::now()->subDays(91));

    artisan('subagents:prune-usage')
        ->assertSuccessful();

    expect(SubAgentUsageLog::query()->count())->toBe(1);
    expect(SubAgentUsageLog::query()->first()->kind)->toBe('describe');
});

test('reports zero deletions when nothing is old enough', function () {
    $subAgent = BotSubAgent::factory()->create();

    subAgentUsageLogWithCreatedAt($subAgent->id, 'describe', Carbon::now()->subDays(10));

    artisan('subagents:prune-usage')
        ->expectsOutputToContain('Deleted 0 sub-agent usage log(s) older than 90 day(s).')
        ->assertSuccessful();

    expect(SubAgentUsageLog::query()->count())->toBe(1);
});
