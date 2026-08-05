<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TimeSeriesService
{
    /**
     * Group a collection of items into daily series for the last N days ending today.
     *
     * @template T of object
     *
     * @param  Collection<int, T>  $items
     * @param  string  $dateField  Attribute holding the item's date (Carbon or parseable string).
     * @param  callable(T): array<string, int>  $extractor  Series increments for one item.
     * @param  int  $days  Window size in days, ending today.
     * @param  array<int, string>  $seriesKeys  Series keys guaranteed in the result, zero-filled when no item maps to them.
     * @return array<string, list<int>|list<string>> Labels (Y-m-d, oldest to today) plus one int series per key.
     */
    public function bucketDaily(Collection $items, string $dateField, callable $extractor, int $days = 14, array $seriesKeys = []): array
    {
        $labels = [];
        $dayIndex = [];
        $today = Carbon::today();

        for ($i = $days - 1; $i >= 0; $i--) {
            $label = $today->copy()->subDays($i)->toDateString();
            $labels[] = $label;
            $dayIndex[$label] = $days - 1 - $i;
        }

        $series = collect($seriesKeys)
            ->mapWithKeys(fn (string $key): array => [$key => array_fill(0, $days, 0)])
            ->all();

        foreach ($items as $item) {
            $date = data_get($item, $dateField);
            $label = $date !== null ? Carbon::parse($date)->toDateString() : null;

            if ($label === null || ! isset($dayIndex[$label])) {
                continue;
            }

            $index = $dayIndex[$label];

            foreach ($extractor($item) as $key => $value) {
                if (! isset($series[$key])) {
                    $series[$key] = array_fill(0, $days, 0);
                }

                $series[$key][$index] += $value;
            }
        }

        return array_merge(['labels' => $labels], $series);
    }
}
