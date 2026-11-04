<script setup lang="ts">
withDefaults(
    defineProps<{
        name: string;
        label: string;
        defaultChecked?: boolean;
    }>(),
    { defaultChecked: true },
);
</script>

<template>
    <label class="flex items-center gap-2 text-sm font-medium">
        <!-- An unchecked box submits nothing, so a hidden 0 makes "off" explicit. -->
        <input type="hidden" :name="name" value="0" />
        <input
            type="checkbox"
            :name="name"
            value="1"
            :checked="defaultChecked"
            class="size-4 rounded border-input accent-primary"
        />
        {{ label }}
    </label>
</template>
