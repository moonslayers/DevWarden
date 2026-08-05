<?php

namespace App\Http\Controllers;

use App\Enums\BotMemoryCategory;
use App\Http\Requests\MemoryIndexRequest;
use App\Models\BotMemory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class MemoryController extends Controller
{
    /**
     * Show the bot memories page.
     */
    public function index(MemoryIndexRequest $request): Response
    {
        $validated = $request->validated();

        $filters = [
            'search' => $validated['search'] ?? null,
            'category' => $validated['category'] ?? null,
            'sort' => $validated['sort'] ?? 'created_at',
            'dir' => $validated['dir'] ?? 'desc',
        ];

        $memories = BotMemory::query()
            ->select([
                'id', 'chat_id', 'summary', 'content', 'category',
                'importance', 'access_count', 'last_accessed_at', 'created_at',
            ])
            ->when($filters['search'] !== null, function ($query) use ($filters) {
                $query->where(function ($query) use ($filters) {
                    $query->where('content', 'like', "%{$filters['search']}%")
                        ->orWhere('summary', 'like', "%{$filters['search']}%");
                });
            })
            ->when($filters['category'] !== null, function ($query) use ($filters) {
                $query->where('category', $filters['category']);
            })
            ->orderBy($filters['sort'], $filters['dir'])
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Memories', [
            'memories' => $memories,
            'filters' => $filters,
            'stats' => $this->stats(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * Delete a bot memory.
     */
    public function destroy(BotMemory $memory): RedirectResponse
    {
        $memory->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Memory deleted.')]);

        return back();
    }

    /**
     * The category options passed to the memories UI as value/label pairs.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        return array_map(
            fn (BotMemoryCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            BotMemoryCategory::cases(),
        );
    }

    /**
     * Aggregate memory counts for the memories page.
     *
     * @return array{
     *     total: int,
     *     per_category: array<string, int>,
     *     last_7_days: int,
     *     series_daily: array<int, array{date: string, count: int}>,
     *     series_by_category: array<int, array{category: string, count: int}>
     * }
     */
    private function stats(): array
    {
        $perCategory = BotMemory::query()
            ->selectRaw('category, count(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'total' => (int) BotMemory::count(),
            'per_category' => $perCategory,
            'last_7_days' => (int) BotMemory::where('created_at', '>=', now()->subDays(7))->count(),
            'series_daily' => $this->dailySeries(),
            'series_by_category' => $this->categorySeries($perCategory),
        ];
    }

    /**
     * Daily memory counts for the last 14 days, oldest first, zero-filled.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function dailySeries(): array
    {
        $byDay = [];

        foreach (BotMemory::query()->where('created_at', '>=', Carbon::today()->subDays(13))->get(['created_at']) as $memory) {
            $day = $memory->created_at->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        $series = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }

        return $series;
    }

    /**
     * Category counts for the doughnut chart, fixed category order then extras.
     *
     * @param  array<string, int>  $perCategory
     * @return array<int, array{category: string, count: int}>
     */
    private function categorySeries(array $perCategory): array
    {
        $series = [];

        foreach (BotMemoryCategory::cases() as $category) {
            $count = $perCategory[$category->value] ?? 0;

            if ($count > 0) {
                $series[] = ['category' => $category->value, 'count' => $count];
            }
        }

        foreach ($perCategory as $category => $count) {
            if ($count > 0 && ! in_array($category, array_column($series, 'category'), true)) {
                $series[] = ['category' => $category, 'count' => $count];
            }
        }

        return $series;
    }
}
