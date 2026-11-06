<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { navIcons } from '@/lib/navIcons';

defineProps<{
    cards: { title: string; description: string; href: string; icon: string }[];
}>();

</script>

<template>
    <Head title="Pengaturan" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Pengaturan</h1>
            <p class="text-sm text-muted-foreground">Pilih bagian yang ingin diatur.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="card in cards"
                :key="card.href"
                :href="card.href"
                class="group flex gap-4 rounded-xl border bg-card p-5 transition hover:border-primary hover:shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                    <component :is="navIcons[card.icon] ?? navIcons.page" class="size-5" />
                </span>
                <span>
                    <span class="block font-medium text-foreground group-hover:text-primary">{{ card.title }}</span>
                    <span class="mt-1 block text-sm text-muted-foreground">{{ card.description }}</span>
                </span>
            </Link>
        </div>

        <p v-if="cards.length === 0" class="text-sm text-muted-foreground">Tidak ada pengaturan yang dapat Anda buka.</p>
    </div>
</template>
