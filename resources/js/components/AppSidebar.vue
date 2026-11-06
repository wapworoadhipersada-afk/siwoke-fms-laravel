<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { iconFor } from '@/lib/navIcons';
import { dashboard } from '@/routes';
import type { NavEntry, NavSection } from '@/types';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();

// The sidebar is decided on the server: roles, permissions, order, names and icons set by an administrator.
const sections = computed<NavSection[]>(
    () => (page.props.navigation ?? []) as NavSection[],
);

const isActive = (href: string | null): boolean =>
    href !== null && isCurrentUrl(href);
const hasActiveChild = (item: NavEntry): boolean =>
    item.children.some((child) => isActive(child.href));
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
            <SidebarGroup
                v-for="section in sections"
                :key="section.label"
                class="px-2 py-0"
            >
                <SidebarGroupLabel>{{ section.label }}</SidebarGroupLabel>
                <SidebarMenu>
                    <template v-for="item in section.items" :key="item.key">
                        <Collapsible
                            v-if="item.children.length > 0"
                            as-child
                            :default-open="hasActiveChild(item)"
                            class="group/collapsible"
                        >
                            <SidebarMenuItem>
                                <CollapsibleTrigger as-child>
                                    <SidebarMenuButton :tooltip="item.label">
                                        <component :is="iconFor(item.icon)" />
                                        <span>{{ item.label }}</span>
                                        <ChevronRight
                                            class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                        />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <SidebarMenuSub>
                                        <SidebarMenuSubItem
                                            v-for="child in item.children"
                                            :key="child.key"
                                        >
                                            <SidebarMenuSubButton
                                                as-child
                                                :is-active="isActive(child.href)"
                                            >
                                                <Link
                                                    v-if="child.href"
                                                    :href="child.href"
                                                >
                                                    <span>{{ child.label }}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>

                        <SidebarMenuItem v-else-if="item.href">
                            <SidebarMenuButton
                                as-child
                                :is-active="isActive(item.href)"
                                :tooltip="item.label"
                            >
                                <Link :href="item.href">
                                    <component :is="iconFor(item.icon)" />
                                    <span>{{ item.label }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </template>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
