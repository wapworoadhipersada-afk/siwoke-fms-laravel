import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';

/**
 * Keeps list filters in the query string. Typing is debounced; the server stays the source of truth.
 */
export function useListFilters<T extends Record<string, string>>(
    url: string,
    initial: T,
) {
    const filters = reactive({ ...initial }) as T;

    const apply = useDebounceFn(() => {
        const query = Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        );

        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);

    watch(filters, () => apply(), { deep: true });

    return filters;
}
