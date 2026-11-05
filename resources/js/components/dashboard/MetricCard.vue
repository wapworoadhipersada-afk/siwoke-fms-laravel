<script setup lang="ts">
import { ArrowDownIcon, ArrowUpIcon } from '@/icons/layout';

withDefaults(
    defineProps<{
        title: string;
        value: string | number;
        change?: string;
        direction?: 'up' | 'down';
    }>(),
    { direction: 'up' },
);
</script>

<template>
    <div
        class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/3"
    >
        <div
            class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-white/90 [&>svg]:size-6"
        >
            <slot name="icon" />
        </div>

        <div class="mt-5 flex items-end justify-between">
            <div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ title }}</span>
                <h4 class="mt-2 text-lg font-bold text-gray-800 dark:text-white/90">{{ value }}</h4>
            </div>

            <span
                v-if="change"
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-sm font-medium"
                :class="
                    direction === 'down'
                        ? 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500'
                        : 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500'
                "
            >
                <ArrowDownIcon v-if="direction === 'down'" class="text-error-500" />
                <ArrowUpIcon v-else />
                {{ change }}
            </span>
        </div>
    </div>
</template>
