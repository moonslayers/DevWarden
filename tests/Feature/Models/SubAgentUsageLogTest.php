<?php

use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('sub_agent_usage_logs table exists', function () {
    expect(Schema::hasTable('sub_agent_usage_logs'))->toBeTrue();
});

test('fillable attributes are mass assignable and cast correctly', function () {
    $subAgent = BotSubAgent::factory()->create();

    $log = SubAgentUsageLog::create([
        'sub_agent_id' => $subAgent->id,
        'chat_id' => 123456789,
        'kind' => 'describe',
        'tokens' => 1500,
    ]);

    expect($log->sub_agent_id)->toBe($subAgent->id);
    expect($log->chat_id)->toBeInt();
    expect($log->chat_id)->toBe(123456789);
    expect($log->kind)->toBe('describe');
    expect($log->tokens)->toBeInt();
    expect($log->tokens)->toBe(1500);
});

test('belongs to a sub-agent', function () {
    $subAgent = BotSubAgent::factory()->create();

    $log = SubAgentUsageLog::create([
        'sub_agent_id' => $subAgent->id,
        'kind' => 'ask',
    ]);

    expect($log->subAgent->is($subAgent))->toBeTrue();
});

test('tokens and chat_id are nullable', function () {
    $subAgent = BotSubAgent::factory()->create();

    $log = SubAgentUsageLog::create([
        'sub_agent_id' => $subAgent->id,
        'kind' => 'describe',
    ]);

    expect($log->tokens)->toBeNull();
    expect($log->chat_id)->toBeNull();
});

test('byKind scope filters logs by kind', function () {
    $subAgent = BotSubAgent::factory()->create();
    SubAgentUsageLog::create(['sub_agent_id' => $subAgent->id, 'kind' => 'describe']);
    SubAgentUsageLog::create(['sub_agent_id' => $subAgent->id, 'kind' => 'ask']);

    $describe = SubAgentUsageLog::query()->byKind('describe')->get();

    expect($describe->pluck('kind')->all())->toBe(['describe']);
});
