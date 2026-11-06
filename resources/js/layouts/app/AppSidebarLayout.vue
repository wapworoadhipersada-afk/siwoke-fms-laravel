<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

// The trail comes from the server (BreadcrumbTrail), built from the sidebar and the route name.
const page = usePage();
const trail = computed((): BreadcrumbItem[] => (page.props.breadcrumbs as BreadcrumbItem[] | undefined) ?? []);
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
