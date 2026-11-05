<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import type { NavItem as NavItemType } from '@/types';
import { computed, ref, watch } from 'vue';
import AssetController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetController';
import AssetModelController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetModelController';
import HandoverController from '@/actions/Modules/Asset/Http/Web/Controllers/HandoverController';
import StockController from '@/actions/Modules/Asset/Http/Web/Controllers/StockController';
import WarehouseController from '@/actions/Modules/Asset/Http/Web/Controllers/WarehouseController';
import IntegrationClientController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationClientController';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import LocationController from '@/actions/Modules/Master/Http/Web/Controllers/LocationController';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import TerminalController from '@/actions/Modules/Master/Http/Web/Controllers/TerminalController';
import VendorController from '@/actions/Modules/Master/Http/Web/Controllers/VendorController';
import TicketReportController from '@/actions/Modules/Report/Http/Web/Controllers/TicketReportController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import {
    BoxIcon,
    ChevronDownIcon,
    HorizontalDotsIcon,
    LayoutGridIcon,
    ListIcon,
    PluginIcon,
    TableIcon,
} from '@/icons/layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type Href = NavItemType['href'];
type SubItem = { name: string; href: Href; permission: string };
type NavItem = {
    name: string;
    icon: Component;
    href?: Href;
    subItems?: SubItem[];
};
type Section = { label: string; items: NavItem[] };

const page = usePage();
const permissions = computed(() => page.props.auth.permissions);
const { state, isMobile } = useSidebar();
const { isCurrentUrl } = useCurrentUrl();

// The wide sidebar shows names and groups; the narrow one shows only icons.
const showContent = computed(
    () => state.value === 'expanded' || isMobile.value,
);

// Hiding a link is only a convenience; the server checks every request.
const sections = computed<Section[]>(() => {
    const raw: Section[] = [
        {
            label: 'Menu',
            items: [
                { name: 'Dashboard', icon: LayoutGridIcon, href: dashboard() },
                {
                    name: 'Operasional',
                    icon: ListIcon,
                    subItems: [
                        { name: 'Tiket', href: TicketController.index(), permission: 'ticket.view' },
                        { name: 'Laporan tiket', href: TicketReportController.index(), permission: 'report.view' },
                    ],
                },
                {
                    name: 'Master Data',
                    icon: TableIcon,
                    subItems: [
                        { name: 'Vendor', href: VendorController.index(), permission: 'master.vendor.view' },
                        { name: 'Lokasi', href: LocationController.index(), permission: 'master.location.view' },
                        { name: 'Merchant', href: MerchantController.index(), permission: 'master.merchant.view' },
                        { name: 'TID', href: TerminalController.index(), permission: 'master.terminal.view' },
                    ],
                },
                {
                    name: 'Aset',
                    icon: BoxIcon,
                    subItems: [
                        { name: 'Aset', href: AssetController.index(), permission: 'asset.view' },
                        { name: 'Stok', href: StockController.index(), permission: 'asset.view' },
                        { name: 'Serah-terima', href: HandoverController.index(), permission: 'asset.view' },
                        { name: 'Gudang', href: WarehouseController.index(), permission: 'asset.view' },
                        { name: 'Jenis aset', href: AssetModelController.index(), permission: 'asset.view' },
                    ],
                },
            ],
        },
        {
            label: 'Integrasi',
            items: [
                {
                    name: 'Vendor API',
                    icon: PluginIcon,
                    subItems: [
                        { name: 'Klien integrasi', href: IntegrationClientController.index(), permission: 'integration.client.manage' },
                        { name: 'Log integrasi', href: IntegrationLogController.index(), permission: 'integration.log.view' },
                    ],
                },
            ],
        },
    ];

    return raw
        .map((section) => ({
            ...section,
            items: section.items
                .map((item) => ({
                    ...item,
                    subItems: item.subItems?.filter((sub) =>
                        permissions.value.includes(sub.permission),
                    ),
                }))
                .filter((item) => !item.subItems || item.subItems.length > 0),
        }))
        .filter((section) => section.items.length > 0);
});

const isActive = (href: NonNullable<Href>): boolean => isCurrentUrl(href);
const hasActiveChild = (item: NavItem): boolean =>
    Boolean(item.subItems?.some((sub) => isActive(sub.href)));

// A group opens by itself when it holds the current page, and can be opened or closed by hand.
const openGroup = ref<string | null>(null);

const syncOpenGroup = (): void => {
    const current = sections.value
        .flatMap((section) => section.items)
        .find((item) => hasActiveChild(item));

    openGroup.value = current?.name ?? null;
};

syncOpenGroup();
watch(() => page.url, syncOpenGroup);

const toggle = (name: string): void => {
    openGroup.value = openGroup.value === name ? null : name;
};

const groupActive = (item: NavItem): boolean =>
    openGroup.value === item.name || hasActiveChild(item);
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader class="px-5 py-8">
            <Link :href="dashboard()">
                <template v-if="showContent">
                    <img class="dark:hidden" src="/images/logo/logo.svg" alt="Logo" width="150" height="40" :style="{ width: 'auto', height: 'auto' }" />
                    <img class="hidden dark:block" src="/images/logo/logo-dark.svg" alt="Logo" width="150" height="40" :style="{ width: 'auto', height: 'auto' }" />
                </template>
                <img v-else src="/images/logo/logo-icon.svg" alt="Logo" width="32" height="32" :style="{ width: 'auto', height: 'auto' }" />
            </Link>
        </SidebarHeader>

        <SidebarContent class="custom-scrollbar overflow-y-auto px-5 duration-300 ease-linear">
            <nav class="mb-6">
                <div class="flex flex-col gap-4">
                    <SidebarGroup v-for="section in sections" :key="section.label" class="p-0">
                        <h2
                            :class="cn('mb-4 flex h-auto p-0 text-xs leading-5 text-gray-400 uppercase', showContent ? 'justify-start' : 'xl:justify-center')"
                        >
                            <template v-if="showContent">{{ section.label }}</template>
                            <HorizontalDotsIcon v-else />
                        </h2>

                        <SidebarMenu class="gap-1">
                            <SidebarMenuItem v-for="item in section.items" :key="item.name">
                                <template v-if="item.subItems">
                                    <button
                                        type="button"
                                        :class="cn('group menu-item cursor-pointer', groupActive(item) ? 'menu-item-active' : 'menu-item-inactive', showContent ? 'lg:justify-start' : 'lg:justify-center')"
                                        @click="toggle(item.name)"
                                    >
                                        <span :class="groupActive(item) ? 'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                            <component :is="item.icon" class="size-6 stroke-[1.5px]" />
                                        </span>
                                        <span v-if="showContent" class="menu-item-text">{{ item.name }}</span>
                                        <ChevronDownIcon
                                            v-if="showContent"
                                            :class="cn('ms-auto h-5 w-5 transition-transform duration-200', openGroup === item.name ? 'rotate-180 text-brand-500' : '')"
                                        />
                                    </button>
                                    <ul v-if="showContent && openGroup === item.name" class="ms-9 mt-2 space-y-1">
                                        <li v-for="sub in item.subItems" :key="sub.name">
                                            <Link
                                                :href="sub.href"
                                                :class="cn('group menu-dropdown-item', isActive(sub.href) ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive')"
                                            >
                                                {{ sub.name }}
                                            </Link>
                                        </li>
                                    </ul>
                                </template>

                                <Link
                                    v-else-if="item.href"
                                    :href="item.href"
                                    :class="cn('group menu-item', isActive(item.href) ? 'menu-item-active' : 'menu-item-inactive', showContent ? '' : 'lg:justify-center')"
                                >
                                    <span :class="isActive(item.href) ? 'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                        <component :is="item.icon" class="size-6 stroke-[1.5px]" />
                                    </span>
                                    <span v-if="showContent" class="menu-item-text">{{ item.name }}</span>
                                </Link>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                </div>
            </nav>
        </SidebarContent>
    </Sidebar>
</template>
