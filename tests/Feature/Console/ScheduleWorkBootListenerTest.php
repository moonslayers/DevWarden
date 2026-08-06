<?php

use App\Models\OpencodeSetting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * Build a CommandStarting event with the given command name. CommandStarting is
 * not auto-dispatched by the console kernel while running unit tests (the
 * Symfony event rerouting is skipped in that mode), so the listener is tested
 * by dispatching the event directly.
 */
function scheduleWorkBootEvent(string $command): CommandStarting
{
    return new CommandStarting($command, new ArrayInput([]), new BufferedOutput);
}

test('stamps the boot anchor and re-arms the boot summary marker when schedule:work starts', function () {
    OpencodeSetting::singleton()->update([
        'schedule_booted_at' => null,
        'session_watch_boot_reported_at' => now(),
    ]);

    Event::dispatch(scheduleWorkBootEvent('schedule:work'));

    $settings = OpencodeSetting::singleton();

    expect($settings->schedule_booted_at)->not->toBeNull()
        ->and($settings->session_watch_boot_reported_at)->toBeNull();
});

test('does not touch the boot anchor or the boot summary marker for other console commands', function (string $command) {
    $anchor = now()->subMinutes(5)->startOfSecond();
    OpencodeSetting::singleton()->update([
        'schedule_booted_at' => $anchor,
        'session_watch_boot_reported_at' => now(),
    ]);

    Event::dispatch(scheduleWorkBootEvent($command));

    $settings = OpencodeSetting::singleton();

    expect($settings->schedule_booted_at->equalTo($anchor))->toBeTrue()
        ->and($settings->session_watch_boot_reported_at)->not->toBeNull();
})->with([
    'opencode:monitor',
    'telegram:poll',
    'queue:work',
]);

test('never breaks the console command when the settings row cannot be written', function () {
    Schema::drop('opencode_settings');

    Event::dispatch(scheduleWorkBootEvent('schedule:work'));

    expect(true)->toBeTrue();
});
