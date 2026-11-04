<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    active?: boolean;
    status?: string;
    label?: string;
}>();

const variant = computed(() => {
    if (props.status === 'removed') {
        return 'destructive';
    }

    if (props.status === 'inactive' || props.active === false) {
        return 'secondary';
    }

    return 'default';
});

const text = computed(
    () => props.label ?? (props.active === false ? 'Nonaktif' : 'Aktif'),
);
</script>

<template>
    <Badge :variant="variant">{{ text }}</Badge>
</template>
