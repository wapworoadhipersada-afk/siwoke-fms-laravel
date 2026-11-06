<script setup lang="ts">
import VendorSwitcher from '@/components/VendorSwitcher.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
        </div>

        <!-- Which vendor every list, total and report is about right now. -->
        <VendorSwitcher />
    </header>
</template>
