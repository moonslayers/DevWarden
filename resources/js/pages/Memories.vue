<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MemoryController from '@/actions/App/Http/Controllers/MemoryController';
import Heading from '@/components/Heading.vue';
import StatChart from '@/components/StatChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useChartPalette } from '@/composables/useChartPalette';
import type { StatDataset } from '@/composables/useChartPalette';
import { index as indexMemories } from '@/routes/memories';

interface BotMemory {
    id: number;
    chat_id: number;
    summary: string;
    content: string;
    category: string | null;
    importance: number;
    access_count: number;
    last_accessed_at: string | null;
    created_at: string;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    search: string | null;
    category: string | null;
    sort: string;
    dir: string;
}

interface Stats {
    total: number;
    per_category: Record<string, number> | null;
    last_7_days: number;
    series_daily: { date: string; count: number }[];
    series_by_category: { category: string; count: number }[];
}

interface CategoryOption {
    value: string;
    label: string;
}

const props = defineProps<{
    memories: Paginator<BotMemory>;
    filters: Filters;
    stats: Stats;
    categories: CategoryOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Memories',
                href: indexMemories(),
            },
        ],
    },
});

const palette = useChartPalette();

const ALL_CATEGORIES = 'none';
const DEFAULT_SORT = 'created_at:desc';

const searchQuery = ref<string>(props.filters.search ?? '');
const categoryValue = ref<string>(props.filters.category ?? ALL_CATEGORIES);
const sortValue = ref<string>(`${props.filters.sort}:${props.filters.dir}`);

const sortOptions = [
    { value: 'created_at:desc', label: 'Newest first' },
    { value: 'created_at:asc', label: 'Oldest first' },
    { value: 'importance:desc', label: 'Most important' },
    { value: 'importance:asc', label: 'Least important' },
    { value: 'access_count:desc', label: 'Most accessed' },
    { value: 'access_count:asc', label: 'Least accessed' },
];

const categoryLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.categories.map((category) => [category.value, category.label]),
    ),
);

const categoryBadgeClasses: Record<string, string> = {
    technical_context:
        'border-transparent bg-blue-500/15 text-blue-600 dark:text-blue-400',
    decision:
        'border-transparent bg-amber-500/15 text-amber-600 dark:text-amber-400',
    user_preference:
        'border-transparent bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    fact: 'border-transparent bg-purple-500/15 text-purple-600 dark:text-purple-400',
};

function categoryLabel(category: string | null): string {
    return category
        ? (categoryLabels.value[category] ?? category)
        : 'Uncategorized';
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatShortDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function dotColor(index: number): string {
    return palette.value[index % palette.value.length];
}

const topCategory = computed<{ label: string; count: number } | null>(() => {
    const perCategory = props.stats.per_category;

    if (!perCategory) {
        return null;
    }

    const entries = Object.entries(perCategory);

    if (entries.length === 0) {
        return null;
    }

    const [category, count] = entries.sort((a, b) => b[1] - a[1])[0];

    return { label: categoryLabel(category), count };
});

const hasSearchFilters = computed(
    () =>
        searchQuery.value.trim() !== '' ||
        categoryValue.value !== ALL_CATEGORIES,
);

const seriesDailyLabels = computed(() =>
    props.stats.series_daily.map((point) => formatShortDate(point.date)),
);

const seriesDailyDatasets = computed<StatDataset[]>(() => [
    {
        label: 'Memories',
        data: props.stats.series_daily.map((point) => point.count),
    },
]);

const hasDailySeries = computed(() =>
    props.stats.series_daily.some((point) => point.count > 0),
);

const seriesByCategoryLabels = computed(() =>
    props.stats.series_by_category.map((row) => categoryLabel(row.category)),
);

const seriesByCategoryDatasets = computed<StatDataset[]>(() => [
    {
        label: 'Memories',
        data: props.stats.series_by_category.map((row) => row.count),
    },
]);

const hasCategorySeries = computed(
    () => props.stats.series_by_category.length > 0,
);

function applyFilters(): void {
    router.get(
        indexMemories().url,
        {
            ...(searchQuery.value.trim() !== ''
                ? { search: searchQuery.value.trim() }
                : {}),
            ...(categoryValue.value !== ALL_CATEGORIES
                ? { category: categoryValue.value }
                : {}),
            sort: sortValue.value.split(':')[0],
            dir: sortValue.value.split(':')[1],
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function resetFilters(): void {
    searchQuery.value = '';
    categoryValue.value = ALL_CATEGORIES;
    sortValue.value = DEFAULT_SORT;
    applyFilters();
}

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Memories" />

    <h1 class="sr-only">Memories</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Memories"
            description="Long-term memories the bot retrieves automatically to remember context across conversations"
        />

        <div
            class="grid gap-4 lg:grid-cols-2"
            data-test="memories-chart-row"
        >
            <Card data-test="memories-category-chart-card">
                <CardHeader>
                    <CardTitle>Memories by category</CardTitle>
                    <CardDescription>
                        Distribution across categories
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <template v-if="hasCategorySeries">
                        <StatChart
                            type="doughnut"
                            :labels="seriesByCategoryLabels"
                            :datasets="seriesByCategoryDatasets"
                            :height="220"
                        />
                        <ul class="mt-4 space-y-2 text-sm">
                            <li
                                v-for="(row, index) in stats.series_by_category"
                                :key="row.category"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :style="{
                                            backgroundColor: dotColor(index),
                                        }"
                                    />
                                    {{ categoryLabel(row.category) }}
                                </span>
                                <span class="text-muted-foreground">
                                    {{ formatNumber(row.count) }}
                                    {{
                                        row.count === 1 ? 'memory' : 'memories'
                                    }}
                                </span>
                            </li>
                        </ul>
                    </template>
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No categorized memories yet.
                    </p>
                </CardContent>
            </Card>

            <Card data-test="memories-daily-chart-card">
                <CardHeader>
                    <CardTitle>Memories per day</CardTitle>
                    <CardDescription>
                        Memories created over the last 14 days
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StatChart
                        v-if="hasDailySeries"
                        type="line"
                        :labels="seriesDailyLabels"
                        :datasets="seriesDailyDatasets"
                        :height="220"
                    />
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No memories created in the last 14 days.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card data-test="memories-total-card">
                <CardContent class="space-y-1">
                    <p class="text-sm text-muted-foreground">Total memories</p>
                    <p class="text-2xl font-semibold tracking-tight">
                        {{ formatNumber(stats.total) }}
                    </p>
                </CardContent>
            </Card>

            <Card data-test="memories-week-card">
                <CardContent class="space-y-1">
                    <p class="text-sm text-muted-foreground">
                        Created last 7 days
                    </p>
                    <p class="text-2xl font-semibold tracking-tight">
                        {{ formatNumber(stats.last_7_days) }}
                    </p>
                </CardContent>
            </Card>

            <Card data-test="memories-top-category-card">
                <CardContent class="space-y-1">
                    <p class="text-sm text-muted-foreground">Top category</p>
                    <p class="text-2xl font-semibold tracking-tight">
                        {{ topCategory ? topCategory.label : '—' }}
                    </p>
                    <p v-if="topCategory" class="text-sm text-muted-foreground">
                        {{ topCategory.count }}
                        {{
                            topCategory.count === 1 ? 'memory' : 'memories'
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <form class="flex-1" @submit.prevent="applyFilters">
                <Label for="memories-search">Search</Label>
                <div class="mt-1 flex gap-2">
                    <Input
                        id="memories-search"
                        v-model="searchQuery"
                        type="search"
                        placeholder="Search summary or content"
                        data-test="memories-search-input"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        data-test="memories-search-button"
                    >
                        Search
                    </Button>
                </div>
            </form>

            <div class="grid gap-2">
                <Label>Category</Label>
                <Select
                    v-model="categoryValue"
                    @update:model-value="applyFilters"
                >
                    <SelectTrigger
                        class="w-full sm:w-48"
                        data-test="memories-category-select"
                    >
                        <SelectValue placeholder="All categories" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_CATEGORIES">
                            All categories
                        </SelectItem>
                        <SelectItem
                            v-for="category in categories"
                            :key="category.value"
                            :value="category.value"
                        >
                            {{ category.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <Label>Sort by</Label>
                <Select v-model="sortValue" @update:model-value="applyFilters">
                    <SelectTrigger
                        class="w-full sm:w-48"
                        data-test="memories-sort-select"
                    >
                        <SelectValue placeholder="Sort" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in sortOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Button
                type="button"
                variant="ghost"
                @click="resetFilters"
                data-test="memories-reset-button"
            >
                Reset
            </Button>
        </div>

        <div
            v-if="memories.data.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            data-test="memories-empty-state"
        >
            {{
                hasSearchFilters
                    ? 'No memories match your current filters.'
                    : 'No memories yet — the bot creates them automatically from conversations.'
            }}
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="memory in memories.data"
                :key="memory.id"
                class="space-y-3 rounded-lg border p-4"
                data-test="memory-row"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    memory.category ? 'outline' : 'secondary'
                                "
                                :class="
                                    memory.category
                                        ? categoryBadgeClasses[memory.category]
                                        : ''
                                "
                                data-test="memory-category-badge"
                            >
                                {{ categoryLabel(memory.category) }}
                            </Badge>
                            <p class="font-medium">{{ memory.summary }}</p>
                        </div>
                        <p class="line-clamp-2 text-sm text-muted-foreground">
                            {{ memory.content }}
                        </p>
                    </div>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-test="delete-memory-button"
                            >
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="
                                    MemoryController.destroy.form(memory.id)
                                "
                                :options="{ preserveScroll: true }"
                                class="space-y-6"
                                v-slot="{ processing }"
                            >
                                <DialogHeader class="space-y-3">
                                    <DialogTitle
                                        >Delete this memory?</DialogTitle
                                    >
                                    <DialogDescription>
                                        This memory will be permanently removed
                                        and can no longer be retrieved by the
                                        bot.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter class="gap-2">
                                    <DialogClose as-child>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        :disabled="processing"
                                        data-test="confirm-delete-memory-button"
                                    >
                                        Delete memory
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                >
                    <span>★ {{ memory.importance }}/10</span>
                    <span>
                        {{ memory.access_count }}
                        {{
                            memory.access_count === 1 ? 'access' : 'accesses'
                        }}
                    </span>
                    <span>{{ formatDate(memory.created_at) }}</span>
                </div>
            </div>

            <div
                v-if="memories.last_page > 1"
                class="flex items-center justify-between gap-2"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!memories.prev_page_url"
                    @click="goToPage(memories.prev_page_url)"
                    data-test="memories-prev-page"
                >
                    Previous
                </Button>
                <span class="text-sm text-muted-foreground">
                    Page {{ memories.current_page }} of {{ memories.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!memories.next_page_url"
                    @click="goToPage(memories.next_page_url)"
                    data-test="memories-next-page"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
