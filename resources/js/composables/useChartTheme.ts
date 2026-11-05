import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

// Chart colours follow the light or dark theme, using the same greys as the rest of the template.
export function useChartTheme() {
    const { resolvedAppearance } = useAppearance();
    const isDark = computed(() => resolvedAppearance.value === 'dark');

    return {
        isDark,
        axis: computed(() => (isDark.value ? '#94A3B8' : '#6B7280')),
        label: computed(() => (isDark.value ? '#94A3B8' : '#364153')),
        grid: computed(() => (isDark.value ? '#1E2640' : '#F3F4F6')),
        track: computed(() => (isDark.value ? '#1E2640' : '#E4E7EC')),
        text: computed(() => (isDark.value ? '#FFFFFF' : '#1D2939')),
    };
}
