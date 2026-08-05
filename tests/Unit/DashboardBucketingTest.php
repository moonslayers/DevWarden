<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return object{created_at: string, role: string}
 */
function dashboardBucketItem(string $date, string $role = 'user'): object
{
    return (object) [
        'created_at' => $date,
        'role' => $role,
    ];
}

test('bucketDaily returns exactly the default window of labels ending today', function () {
    $result = DashboardController::bucketDaily(
        collect([dashboardBucketItem(Carbon::today()->toDateString())]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        14,
        ['user', 'assistant'],
    );

    expect($result['labels'])->toHaveCount(14);
    expect($result['labels'][0])->toBe(Carbon::today()->subDays(13)->toDateString());
    expect($result['labels'][13])->toBe(Carbon::today()->toDateString());
    expect($result['user'])->toHaveCount(14);
    expect($result['assistant'])->toHaveCount(14);
});

test('bucketDaily fills gaps in the window with zeroes', function () {
    $today = Carbon::today()->toDateString();
    $fiveDaysAgo = Carbon::today()->subDays(5)->toDateString();

    $result = DashboardController::bucketDaily(
        collect([
            dashboardBucketItem($today),
            dashboardBucketItem($today),
            dashboardBucketItem($fiveDaysAgo),
        ]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        14,
        ['user', 'assistant'],
    );

    $expectedUser = array_fill(0, 14, 0);
    $expectedUser[13] = 2;
    $expectedUser[8] = 1;

    expect($result['user'])->toBe($expectedUser);
    expect($result['assistant'])->toBe(array_fill(0, 14, 0));
});

test('bucketDaily sums multiple series returned by the extractor', function () {
    $today = Carbon::today()->toDateString();

    $result = DashboardController::bucketDaily(
        collect([
            (object) ['created_at' => $today, 'prompt' => 10, 'completion' => 20],
            (object) ['created_at' => $today, 'prompt' => 1, 'completion' => 2],
        ]),
        'created_at',
        fn (object $item): array => ['prompt' => $item->prompt, 'completion' => $item->completion],
        14,
        ['prompt', 'completion'],
    );

    expect($result['prompt'][13])->toBe(11);
    expect($result['completion'][13])->toBe(22);
});

test('bucketDaily ignores items outside the window', function () {
    $result = DashboardController::bucketDaily(
        collect([
            dashboardBucketItem(Carbon::today()->subDays(30)->toDateString()),
            dashboardBucketItem(Carbon::today()->addDays(5)->toDateString()),
        ]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        14,
        ['user'],
    );

    expect($result['user'])->toBe(array_fill(0, 14, 0));
});

test('bucketDaily honors a custom window size', function () {
    $result = DashboardController::bucketDaily(
        collect([dashboardBucketItem(Carbon::today()->toDateString())]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        7,
        ['user'],
    );

    expect($result['labels'])->toHaveCount(7);
    expect($result['labels'][6])->toBe(Carbon::today()->toDateString());
    expect($result['user'])->toHaveCount(7);
    expect($result['user'][6])->toBe(1);
});

test('bucketDaily keeps items on the exact first day of the window and drops older ones', function () {
    $result = DashboardController::bucketDaily(
        collect([
            dashboardBucketItem(Carbon::today()->subDays(13)->toDateString()),
            dashboardBucketItem(Carbon::today()->subDays(14)->toDateString()),
        ]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        14,
        ['user'],
    );

    $expected = array_fill(0, 14, 0);
    $expected[0] = 1;

    expect($result['user'])->toBe($expected);
});

test('bucketDaily creates series implicitly from extractor keys when seriesKeys is empty', function () {
    $result = DashboardController::bucketDaily(
        collect([
            dashboardBucketItem(Carbon::today()->toDateString(), 'user'),
            dashboardBucketItem(Carbon::today()->subDays(5)->toDateString(), 'assistant'),
        ]),
        'created_at',
        fn (object $item): array => [$item->role => 1],
        14,
        [],
    );

    $expectedUser = array_fill(0, 14, 0);
    $expectedUser[13] = 1;

    $expectedAssistant = array_fill(0, 14, 0);
    $expectedAssistant[8] = 1;

    expect($result['user'])->toBe($expectedUser);
    expect($result['assistant'])->toBe($expectedAssistant);
});
