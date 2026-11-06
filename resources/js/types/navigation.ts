import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    // Absent for a heading (a menu group) and for the page being shown.
    href?: NonNullable<InertiaLinkProps['href']> | null;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

// The sidebar as the server builds it for the signed-in person.
export type NavLeaf = {
    id: number;
    key: string;
    label: string;
    icon: string | null;
    href: string | null;
};

export type NavEntry = NavLeaf & { children: NavLeaf[] };

export type NavSection = { label: string; items: NavEntry[] };
