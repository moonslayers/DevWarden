<?php

use App\Models\OpencodeSessionDismissal;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('opencode_session_dismissals table exists', function () {
    expect(Schema::hasTable('opencode_session_dismissals'))->toBeTrue();
});

test('factory produces a valid row', function () {
    $dismissal = OpencodeSessionDismissal::factory()->create();

    expect($dismissal->exists)->toBeTrue();
    expect($dismissal->session_id)->toStartWith('ses_');
    expect($dismissal->dismissed_at)->not->toBeNull();
});

test('session_id is the primary key and rows are found by it', function () {
    OpencodeSessionDismissal::factory()->create([
        'session_id' => 'ses_test_pk_00000000000000000000001',
    ]);

    $dismissal = OpencodeSessionDismissal::find('ses_test_pk_00000000000000000000001');

    expect($dismissal)->not->toBeNull()
        ->and($dismissal->session_id)->toBe('ses_test_pk_00000000000000000000001');

    $dismissal->delete();

    expect(OpencodeSessionDismissal::whereKey('ses_test_pk_00000000000000000000001')->exists())->toBeFalse();
});

test('dismissed_at is mass assignable and cast to a datetime', function () {
    $dismissedAt = now()->subMinutes(5);

    $dismissal = OpencodeSessionDismissal::create([
        'session_id' => 'ses_test_cast_00000000000000000000001',
        'dismissed_at' => $dismissedAt,
    ]);

    expect($dismissal->dismissed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($dismissal->dismissed_at->toDateTimeString())->toBe($dismissedAt->toDateTimeString());
});

test('dismissed_at defaults to null', function () {
    $dismissal = OpencodeSessionDismissal::factory()->create(['dismissed_at' => null]);

    expect($dismissal->dismissed_at)->toBeNull();
});
