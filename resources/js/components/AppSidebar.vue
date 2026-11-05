<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BookOpen,
    Boxes,
    ClipboardList,
    CreditCard,
    FileSpreadsheet,
    FolderGit2,
    KeyRound,
    Landmark,
    ScrollText,
    LayoutGrid,
    MapPinned,
    Package,
    Store,
    Tags,
    Warehouse,
} from '@lucide/vue';
import { computed } from 'vue';
import AssetController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetController';
import AssetModelController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetModelController';
import HandoverController from '@/actions/Modules/Asset/Http/Web/Controllers/HandoverController';
import IntegrationClientController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationClientController';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import TicketReportController from '@/actions/Modules/Report/Http/Web/Controllers/TicketReportController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import StockController from '@/actions/Modules/Asset/Http/Web/Controllers/StockController';
import WarehouseController from '@/actions/Modules/Asset/Http/Web/Controllers/WarehouseController';
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
const operationsNavItems = computed<NavItem[]>(() =>
    [
        { permission: 'ticket.view', title: 'Tiket', href: TicketController.index(), icon: ClipboardList },
        { permission: 'report.view', title: 'Laporan tiket', href: TicketReportController.index(), icon: FileSpreadsheet },
    ]
        .filter((item) => permissions.value.includes(item.permission))
        .map(({ title, href, icon }) => ({ title, href, icon })),
);

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

const assetNavItems = computed<NavItem[]>(() =>
    [
        { permission: 'asset.view', title: 'Aset', href: AssetController.index(), icon: Package },
        { permission: 'asset.view', title: 'Stok', href: StockController.index(), icon: Boxes },
        { permission: 'asset.view', title: 'Serah-terima', href: HandoverController.index(), icon: ArrowLeftRight },
        { permission: 'asset.view', title: 'Gudang', href: WarehouseController.index(), icon: Warehouse },
        { permission: 'asset.view', title: 'Jenis aset', href: AssetModelController.index(), icon: Tags },
    ]
        .filter((item) => permissions.value.includes(item.permission))
        .map(({ title, href, icon }) => ({ title, href, icon })),
);

const integrationNavItems = computed<NavItem[]>(() =>
    [
        { permission: 'integration.client.manage', title: 'Klien integrasi', href: IntegrationClientController.index(), icon: KeyRound },
        { permission: 'integration.log.view', title: 'Log integrasi', href: IntegrationLogController.index(), icon: ScrollText },
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
                v-if="operationsNavItems.length"
                :items="operationsNavItems"
                label="Operasional"
            />
            <NavMain
                v-if="masterNavItems.length"
                :items="masterNavItems"
                label="Master Data"
            />
            <NavMain
                v-if="assetNavItems.length"
                :items="assetNavItems"
                label="Aset"
            />
            <NavMain
                v-if="integrationNavItems.length"
                :items="integrationNavItems"
                label="Integrasi"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
