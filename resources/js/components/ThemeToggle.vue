<script setup lang="ts">
import { MoonIcon, SunIcon } from '@/icons/layout';
import { useAppearance } from '@/composables/useAppearance';
import { Button } from '@/components/ui/button';

const { resolvedAppearance, updateAppearance } = useAppearance();

const toggle = (): void => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};
</script>

<template>
    <Button
        variant="outline"
        size="icon-lg"
        class="relative text-gray-500 dark:text-gray-400"
        aria-label="Ganti tema"
        @click="toggle"
    >
        <SunIcon v-if="resolvedAppearance === 'dark'" />
        <MoonIcon v-else />
    </Button>
</template>
