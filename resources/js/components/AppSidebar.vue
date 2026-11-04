<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CreditCard,
    FolderGit2,
    Landmark,
    LayoutGrid,
    MapPinned,
    Store,
} from '@lucide/vue';
import { computed } from 'vue';
import LocationController from '@/actions/Modules/Master/Http/Web/Controllers/LocationController';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import TerminalController from '@/actions/Modules/Master/Http/Web/Controllers/TerminalController';
import VendorController from '@/actions/Modules/Master/Http/Web/Controllers/VendorController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const page = usePage();
const permissions = computed(() => page.props.auth.permissions);

// Hiding a link is only a convenience; the server checks every request.
const masterNavItems = computed<NavItem[]>(() =>
    [
        { permission: 'master.vendor.view', title: 'Vendor', href: VendorController.index(), icon: Landmark },
        { permission: 'master.location.view', title: 'Lokasi', href: LocationController.index(), icon: MapPinned },
        { permission: 'master.merchant.view', title: 'Merchant', href: MerchantController.index(), icon: Store },
        { permission: 'master.terminal.view', title: 'TID', href: TerminalController.index(), icon: CreditCard },
    ]
        .filter((item) => permissions.value.includes(item.permission))
        .map(({ title, href, icon }) => ({ title, href, icon })),
);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="masterNavItems.length"
                :items="masterNavItems"
                label="Master Data"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
