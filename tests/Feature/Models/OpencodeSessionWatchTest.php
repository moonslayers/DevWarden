<?php

use App\Models\OpencodeSessionWatch;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('opencode_session_watches table exists', function () {
    expect(Schema::hasTable('opencode_session_watches'))->toBeTrue();
});

test('factory produces a valid row', function () {
    $watch = OpencodeSessionWatch::factory()->create();

    expect($watch->exists)->toBeTrue();
    expect($watch->session_id)->toStartWith('ses_');
    expect($watch->is_subagent)->toBeFalse();
    expect($watch->project_path)->not->toBeNull();
    expect($watch->title)->not->toBeNull();
    expect($watch->chat_id)->toBeInt();
    expect($watch->last_seen_status)->toBe('running');
    expect($watch->checked_at)->not->toBeNull();
});

test('fillable attributes are mass assignable and cast correctly', function () {
    $checkedAt = now()->subMinutes(2);
    $notifiedAt = now();

    $watch = OpencodeSessionWatch::create([
        'session_id' => 'ses_test_mass_assign_001',
        'is_subagent' => true,
        'project_path' => '/tmp/project',
        'title' => 'Refactor session',
        'chat_id' => 123456789,
        'last_seen_status' => 'idle',
        'last_notified_event' => 'finished',
        'checked_at' => $checkedAt,
        'notified_at' => $notifiedAt,
    ]);

    expect($watch->session_id)->toBe('ses_test_mass_assign_001');
    expect($watch->is_subagent)->toBeTrue();
    expect($watch->is_subagent)->toBeBool();
    expect($watch->chat_id)->toBeInt();
    expect($watch->chat_id)->toBe(123456789);
    expect($watch->last_notified_event)->toBe('finished');
    expect($watch->checked_at)->toBeInstanceOf(CarbonInterface::class);
    expect($watch->notified_at)->toBeInstanceOf(CarbonInterface::class);
    expect($watch->checked_at->toDateTimeString())->toBe($checkedAt->toDateTimeString());
});

test('session_id is unique', function () {
    OpencodeSessionWatch::factory()->create(['session_id' => 'ses_dup_000000000000000000000001']);

    expect(fn () => OpencodeSessionWatch::factory()->create(['session_id' => 'ses_dup_000000000000000000000001']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('nullable fields default to null', function () {
    $watch = OpencodeSessionWatch::factory()->create([
        'project_path' => null,
        'title' => null,
        'chat_id' => null,
        'last_seen_status' => null,
        'last_notified_event' => null,
        'notified_at' => null,
    ]);

    $watch->refresh();

    expect($watch->project_path)->toBeNull();
    expect($watch->title)->toBeNull();
    expect($watch->chat_id)->toBeNull();
    expect($watch->last_seen_status)->toBeNull();
    expect($watch->last_notified_event)->toBeNull();
    expect($watch->notified_at)->toBeNull();
});
