<script setup lang="ts">
defineProps<{
    columns: string[];
    empty?: boolean;
    emptyText?: string;
}>();
</script>

<template>
    <div class="panel overflow-hidden">
        <div class="max-w-full overflow-x-auto">
            <table class="w-full text-left text-theme-sm">
                <thead>
                    <tr
                        class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]"
                    >
                        <th
                            v-for="column in columns"
                            :key="column"
                            class="px-5 py-3 text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300 [&_td]:px-5 [&_td]:py-4"
                >
                    <slot />
                    <tr v-if="empty">
                        <td
                            :colspan="columns.length"
                            class="!py-10 text-center text-gray-500 dark:text-gray-400"
                        >
                            {{ emptyText ?? 'Belum ada data.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
