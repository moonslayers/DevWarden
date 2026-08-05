<script setup lang="ts">
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import { useChartPalette, useChartTheme } from '@/composables/useChartPalette';
import type { StatDataset } from '@/composables/useChartPalette';

ChartJS.register(
    ArcElement,
    BarElement,
    CategoryScale,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
);

type StatChartType = 'line' | 'bar' | 'doughnut';

const props = withDefaults(
    defineProps<{
        type: StatChartType;
        labels: string[];
        datasets: StatDataset[];
        height?: number;
    }>(),
    {
        height: 300,
    },
);

const palette = useChartPalette();
const themeColors = useChartTheme();

const baseData = computed(() => ({
    labels: props.labels,
    datasets: props.datasets.map((dataset, index) => {
        const color = palette.value[index % palette.value.length];

        if (dataset.backgroundColor) {
            return { ...dataset, borderColor: dataset.borderColor ?? color };
        }

        if (props.type === 'doughnut') {
            return {
                ...dataset,
                backgroundColor: dataset.data.map(
                    (_, sliceIndex) =>
                        palette.value[sliceIndex % palette.value.length],
                ),
            };
        }

        if (props.type === 'line') {
            return {
                ...dataset,
                borderColor: color,
                backgroundColor: 'transparent',
                fill: false,
            };
        }

        return { ...dataset, backgroundColor: color };
    }),
}));

const lineData = computed<ChartData<'line'>>(
    () => baseData.value as unknown as ChartData<'line'>,
);
const barData = computed<ChartData<'bar'>>(
    () => baseData.value as unknown as ChartData<'bar'>,
);
const doughnutData = computed<ChartData<'doughnut'>>(
    () => baseData.value as unknown as ChartData<'doughnut'>,
);

function buildOptions(): ChartOptions<'line'> {
    const options: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: themeColors.value.foreground,
                    boxWidth: 12,
                },
            },
            tooltip: {
                backgroundColor: themeColors.value.foreground,
                titleColor: themeColors.value.background,
                bodyColor: themeColors.value.background,
                borderColor: themeColors.value.grid,
            },
        },
    };

    if (props.type === 'line' || props.type === 'bar') {
        options.scales = {
            x: {
                grid: { color: themeColors.value.grid },
                ticks: { color: themeColors.value.mutedForeground },
            },
            y: {
                beginAtZero: true,
                grid: { color: themeColors.value.grid },
                ticks: { color: themeColors.value.mutedForeground },
            },
        };
    }

    return options;
}

const lineOptions = computed<ChartOptions<'line'>>(() => buildOptions());
const barOptions = computed<ChartOptions<'bar'>>(
    () => buildOptions() as unknown as ChartOptions<'bar'>,
);
const doughnutOptions = computed<ChartOptions<'doughnut'>>(
    () => buildOptions() as unknown as ChartOptions<'doughnut'>,
);
</script>

<template>
    <div class="relative w-full" :style="{ height: `${height}px` }">
        <Line v-if="type === 'line'" :data="lineData" :options="lineOptions" />
        <Bar v-else-if="type === 'bar'" :data="barData" :options="barOptions" />
        <Doughnut v-else :data="doughnutData" :options="doughnutOptions" />
    </div>
</template>
