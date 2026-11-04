<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { Paginator } from '@/types/master';

defineProps<{
    paginator: Paginator<unknown>;
}>();
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 text-sm text-muted-foreground"
    >
        <p v-if="paginator.total > 0">
            {{ paginator.from }}–{{ paginator.to }} dari {{ paginator.total }}
        </p>
        <p v-else>Tidak ada data.</p>

        <div v-if="paginator.last_page > 1" class="flex items-center gap-2">
            <Button
                v-if="paginator.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="paginator.prev_page_url" preserve-scroll
                    >Sebelumnya</Link
                >
            </Button>
            <span
                >Halaman {{ paginator.current_page }} /
                {{ paginator.last_page }}</span
            >
            <Button
                v-if="paginator.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="paginator.next_page_url" preserve-scroll
                    >Berikutnya</Link
                >
            </Button>
        </div>
    </div>
</template>
