import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

export interface StatDataset {
    label: string;
    data: number[];
    backgroundColor?: string | string[];
    borderColor?: string | string[];
}

export interface ChartThemeColors {
    foreground: string;
    background: string;
    mutedForeground: string;
    grid: string;
}

function cssVar(name: string): string {
    if (typeof document === 'undefined') {
        return 'hsl(0 0% 45%)';
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();

    return value || 'hsl(0 0% 45%)';
}

export function useChartPalette(): ComputedRef<string[]> {
    const { resolvedAppearance } = useAppearance();

    return computed(() => {
        void resolvedAppearance.value;

        return [
            cssVar('--chart-1'),
            cssVar('--chart-2'),
            cssVar('--chart-3'),
            cssVar('--chart-4'),
            cssVar('--chart-5'),
        ];
    });
}

export function useChartTheme(): ComputedRef<ChartThemeColors> {
    const { resolvedAppearance } = useAppearance();

    return computed(() => {
        void resolvedAppearance.value;

        return {
            foreground: cssVar('--foreground'),
            background: cssVar('--background'),
            mutedForeground: cssVar('--muted-foreground'),
            grid: cssVar('--border'),
        };
    });
}
