<script setup lang="ts">
import { ref, watch } from 'vue';
import { cn } from '@/lib/utils';

type Item = { value: string; label: string };

const props = withDefaults(
    defineProps<{
        name?: string;
        id?: string;
        options: Item[];
        modelValue?: string;
        defaultValue?: string | null;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    { modelValue: undefined, defaultValue: '', placeholder: '' },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const value = ref(props.modelValue ?? props.defaultValue ?? '');

watch(
    () => props.modelValue,
    (next) => {
        if (next !== undefined) {
            value.value = next;
        }
    },
);

watch(value, (next) => emit('update:modelValue', next));
</script>

<template>
    <select
        :id="id"
        v-model="value"
        :name="name"
        :required="required"
        :disabled="disabled"
        :class="
            cn(
                'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none md:text-sm dark:bg-input/30',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                'disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )
        "
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            class="bg-background text-foreground"
        >
            {{ option.label }}
        </option>
    </select>
</template>
