<script setup lang="ts">
defineProps<{
    columns: string[];
    empty?: boolean;
    emptyText?: string;
}>();
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column"
                        class="px-4 py-3 font-medium whitespace-nowrap"
                    >
                        {{ column }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <slot />
                <tr v-if="empty">
                    <td
                        :colspan="columns.length"
                        class="px-4 py-10 text-center text-muted-foreground"
                    >
                        {{ emptyText ?? 'Belum ada data.' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
