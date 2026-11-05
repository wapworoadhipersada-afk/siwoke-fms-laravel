<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    active?: boolean;
    status?: string;
    label?: string;
}>();

// Soft pills as in TailAdmin: green for working, gray for switched off, red for removed.
const tone = computed(() => {
    if (props.status === 'removed') {
        return 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500';
    }

    if (props.status === 'inactive' || props.active === false) {
        return 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80';
    }

    return 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500';
});

const text = computed(
    () => props.label ?? (props.active === false ? 'Nonaktif' : 'Aktif'),
);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap"
        :class="tone"
    >
        {{ text }}
    </span>
</template>
