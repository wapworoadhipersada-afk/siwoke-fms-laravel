<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();

// What the last part of an address says about the page: a form to add, a form to change, a record's detail.
const currentPageLabel = computed((): string | null => {
    const name = page.component.split('/').pop() ?? '';
    const path = page.url.split('?')[0];

    if (name === 'Index' || name === 'Dashboard') {
        return null;
    }

    if (path.endsWith('/create')) {
        return 'Tambah';
    }

    if (path.endsWith('/edit')) {
        return 'Ubah';
    }

    return (
        {
            Show: 'Detail',
            Receive: 'Terima aset',
            Credentials: 'Kunci rahasia',
            Form: 'Form',
            Edit: 'Ubah',
            Create: 'Tambah',
        }[name] ?? name
    );
});

// Home, then the section the page declares, then the page itself, so anyone can see where they are and
// go back up a level without the browser's back button.
const trail = computed((): BreadcrumbItem[] => {
    if (page.component === 'Dashboard') {
        return [];
    }

    const items: BreadcrumbItem[] = [
        { title: 'Beranda', href: dashboard() },
        ...props.breadcrumbs,
    ];

    // A page whose section name depends on the data (one list serving two menus) names it itself.
    const section = page.props.sectionTitle;

    if (props.breadcrumbs.length === 0 && typeof section === 'string') {
        items.push({ title: section, href: page.url.split('?')[0] });
    }

    if (currentPageLabel.value !== null) {
        items.push({ title: currentPageLabel.value, href: page.url });
    }

    return items;
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader />
            <div v-if="trail.length > 1" class="px-4 pt-4 md:px-6">
                <Breadcrumbs :breadcrumbs="trail" />
            </div>
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
