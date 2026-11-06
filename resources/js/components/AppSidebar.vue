<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import { ChevronDownIcon, HorizontalDotsIcon } from '@/icons/layout';
import { iconFor } from '@/lib/navIcons';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { NavEntry, NavSection } from '@/types';

const page = usePage();
const { state, isMobile } = useSidebar();
const { isCurrentUrl } = useCurrentUrl();

// The sidebar is decided on the server (roles, permissions, order, names, icons set by an administrator).
const sections = computed<NavSection[]>(
    () => (page.props.navigation ?? []) as NavSection[],
);

// The wide sidebar shows names and groups; the narrow one shows only icons.
const showContent = computed(
    () => state.value === 'expanded' || isMobile.value,
);

const isActive = (href: string | null): boolean =>
    href !== null && isCurrentUrl(href);
const hasActiveChild = (item: NavEntry): boolean =>
    item.children.some((child) => isActive(child.href));

// A group opens by itself when it holds the current page, and can be opened or closed by hand.
const openGroup = ref<string | null>(null);

const syncOpenGroup = (): void => {
    const current = sections.value
        .flatMap((section) => section.items)
        .find((item) => hasActiveChild(item));

    openGroup.value = current?.key ?? null;
};

syncOpenGroup();
watch(() => page.url, syncOpenGroup);

const toggle = (key: string): void => {
    openGroup.value = openGroup.value === key ? null : key;
};

const groupActive = (item: NavEntry): boolean =>
    openGroup.value === item.key || hasActiveChild(item);
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
                            <SidebarMenuItem v-for="item in section.items" :key="item.key">
                                <template v-if="item.children.length > 0">
                                    <button
                                        type="button"
                                        :class="cn('group menu-item cursor-pointer', groupActive(item) ? 'menu-item-active' : 'menu-item-inactive', showContent ? 'lg:justify-start' : 'lg:justify-center')"
                                        @click="toggle(item.key)"
                                    >
                                        <span :class="groupActive(item) ? 'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                            <component :is="iconFor(item.icon)" class="size-6 stroke-[1.5px]" />
                                        </span>
                                        <span v-if="showContent" class="menu-item-text">{{ item.label }}</span>
                                        <ChevronDownIcon
                                            v-if="showContent"
                                            :class="cn('ms-auto h-5 w-5 transition-transform duration-200', openGroup === item.key ? 'rotate-180 text-brand-500' : '')"
                                        />
                                    </button>
                                    <ul v-if="showContent && openGroup === item.key" class="ms-9 mt-2 space-y-1">
                                        <li v-for="child in item.children" :key="child.key">
                                            <Link
                                                v-if="child.href"
                                                :href="child.href"
                                                :class="cn('group menu-dropdown-item', isActive(child.href) ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive')"
                                            >
                                                {{ child.label }}
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
                                        <component :is="iconFor(item.icon)" class="size-6 stroke-[1.5px]" />
                                    </span>
                                    <span v-if="showContent" class="menu-item-text">{{ item.label }}</span>
                                </Link>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                </div>
            </nav>
        </SidebarContent>
    </Sidebar>
</template>
