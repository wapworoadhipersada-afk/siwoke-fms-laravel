<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white"
    >
        <AppLogoIcon class="size-6 fill-current text-white" />
    </div>
    <span
        class="truncate text-lg font-semibold tracking-tight text-gray-800 group-data-[collapsible=icon]:hidden dark:text-white/90"
    >
        {{ name }}
    </span>
</template>
