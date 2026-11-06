import {
    Bell,
    Calendar,
    ClipboardList,
    File,
    FileText,
    LayoutGrid,
    ListChecks,
    Mail,
    Map as MapIcon,
    Package,
    PieChart,
    Plug,
    Settings,
    Table2,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

// The icons an administrator may give a menu entry. Keys must match NavigationCatalog::icons() on the server.
export const navIcons: Record<string, Component> = {
    grid: LayoutGrid,
    list: ClipboardList,
    table: Table2,
    box: Package,
    users: Users,
    user: UserRound,
    plug: Plug,
    gear: Settings,
    chart: PieChart,
    file: FileText,
    map: MapIcon,
    calendar: Calendar,
    task: ListChecks,
    bell: Bell,
    mail: Mail,
    page: File,
};

export const iconFor = (key: string | null | undefined): Component =>
    (key !== null && key !== undefined ? navIcons[key] : undefined) ?? File;
