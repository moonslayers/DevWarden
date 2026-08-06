<?php

use App\Ai\Agents\BotAgent;
use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\BotSkill;
use App\Models\OpencodeWorkflow;
use App\Models\SkillUsageLog;
use App\Models\User;
use App\Services\Embedding\EmbeddingService;
use App\Services\Opencode\OpencodeSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();

    BotSetting::factory()->create(['owner_user_id' => $this->owner->id]);

    AiProvider::factory()->forType(AiProviderType::OpenAI)->create([
        'api_key' => 'sk-openai',
        'model_text' => 'gpt-4o-mini',
    ]);

    app()->instance(EmbeddingService::class, new class implements EmbeddingService
    {
        public function embed(string|array $texts): array
        {
            return [[1.0, 0.0, 0.0, 0.0]];
        }
    });

    app()->instance(OpencodeSessionStore::class, new class extends OpencodeSessionStore
    {
        public function activeSessions(?int $sinceEpochMs = null): array
        {
            return [];
        }
    });
});

test('respond persists one skill usage log per matched skill with the correct ids', function () {
    $deploy = BotSkill::factory()->create([
        'name' => 'Deployment',
        'content' => 'You handle deployments.',
        'trigger_keywords' => ['deploy'],
        'active' => true,
    ]);

    $opencode = BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'content' => 'You orchestrate opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'usa opencode y deploy', $this->owner);

    expect($reply)->toBe('Reply from the bot.');

    $logs = SkillUsageLog::query()->get();

    $expectedIds = [$deploy->id, $opencode->id];
    sort($expectedIds);

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('skill_id')->sort()->values()->all())->toBe($expectedIds)
        ->and($logs->pluck('chat_id')->all())->toBe([123456789, 123456789])
        ->and($logs->every(fn (SkillUsageLog $log): bool => $log->created_at !== null))->toBeTrue();
});

test('respond persists no skill usage log when no skill matches the text', function () {
    BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'content' => 'You orchestrate opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'hola', $this->owner);

    expect($reply)->toBe('Reply from the bot.')
        ->and(SkillUsageLog::count())->toBe(0);
});

test('respond does not log usage for an inactive skill even when the text matches', function () {
    BotSkill::factory()->create([
        'name' => 'Disabled Skill',
        'content' => 'You should not be logged.',
        'trigger_keywords' => ['opencode'],
        'active' => false,
    ]);

    BotAgent::fake(['Reply from the bot.']);

    $reply = app(BotAgent::class)->respond(123456789, 'usa opencode en s2c', $this->owner);

    expect($reply)->toBe('Reply from the bot.')
        ->and(SkillUsageLog::count())->toBe(0);
});

test('respond logs skill usage when the chat has an active opencode workflow', function () {
    $skill = BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'content' => 'You orchestrate opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    OpencodeWorkflow::factory()->create([
        'chat_id' => 123456789,
        'status' => 'running',
    ]);

    BotAgent::fake(['Reply from the bot.']);

    app(BotAgent::class)->respond(123456789, 'hola', $this->owner);

    $log = SkillUsageLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->skill_id)->toBe($skill->id)
        ->and($log->chat_id)->toBe(123456789);
});

test('respond builds a byte-identical prompt with the skill blocks while logging usage', function () {
    $orchestration = BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'content' => 'You orchestrate opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
        'sort_order' => 0,
    ]);

    $deployment = BotSkill::factory()->create([
        'name' => 'Deployment',
        'content' => 'You handle deployments.',
        'trigger_keywords' => ['deploy'],
        'active' => true,
        'sort_order' => 1,
    ]);

    $promptSeen = null;

    BotAgent::fake(function ($prompt) use (&$promptSeen) {
        $promptSeen = $prompt;

        return 'Reply from the bot.';
    });

    $reply = app(BotAgent::class)->respond(123456789, 'usa opencode y deploy', $this->owner);

    $expectedPrompt = '<skill name="Opencode Orchestration">'.PHP_EOL
        .'You orchestrate opencode sessions.'.PHP_EOL
        .'</skill>'.PHP_EOL
        .'<skill name="Deployment">'.PHP_EOL
        .'You handle deployments.'.PHP_EOL
        .'</skill>'.PHP_EOL.PHP_EOL
        .'usa opencode y deploy';

    expect($reply)->toBe('Reply from the bot.')
        ->and($promptSeen)->toBe($expectedPrompt)
        ->and(SkillUsageLog::query()->count())->toBe(2)
        ->and(SkillUsageLog::query()->pluck('skill_id')->sort()->values()->all())
        ->toBe([$orchestration->id, $deployment->id]);
});

test('respond still returns the exact prompt when recording skill usage fails', function () {
    $skill = BotSkill::factory()->create([
        'name' => 'Opencode Orchestration',
        'content' => 'You orchestrate opencode sessions.',
        'trigger_keywords' => ['opencode'],
        'active' => true,
    ]);

    DB::statement(
        'CREATE TRIGGER force_skill_usage_insert_failure '
        .'BEFORE INSERT ON skill_usage_logs '
        .'BEGIN SELECT RAISE(ABORT, \'forced skill usage failure\'); END',
    );

    $log = Log::spy();

    $promptSeen = null;

    BotAgent::fake(function ($prompt) use (&$promptSeen) {
        $promptSeen = $prompt;

        return 'Reply from the bot.';
    });

    $reply = app(BotAgent::class)->respond(123456789, 'usa opencode', $this->owner);

    $expectedPrompt = '<skill name="Opencode Orchestration">'.PHP_EOL
        .'You orchestrate opencode sessions.'.PHP_EOL
        .'</skill>'.PHP_EOL.PHP_EOL
        .'usa opencode';

    expect($reply)->toBe('Reply from the bot.')
        ->and($promptSeen)->toBe($expectedPrompt)
        ->and(SkillUsageLog::query()->count())->toBe(0);

    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to record skill usage'));

    DB::statement('DROP TRIGGER IF EXISTS force_skill_usage_insert_failure;');
});
