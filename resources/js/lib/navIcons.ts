import type { Component } from 'vue';
import {
    BellIcon,
    BoxIcon,
    CalendarIcon,
    FileIcon,
    GearIcon,
    LayoutGridIcon,
    ListIcon,
    MailIcon,
    MapIcon,
    PageIcon,
    PieChartIcon,
    PluginIcon,
    TableIcon,
    TaskIcon,
    UserCircleIcon,
    UsersIcon,
} from '@/icons/layout';

// The icons an administrator may give a menu entry. Keys must match NavigationCatalog::icons() on the server.
export const navIcons: Record<string, Component> = {
    grid: LayoutGridIcon,
    list: ListIcon,
    table: TableIcon,
    box: BoxIcon,
    users: UsersIcon,
    user: UserCircleIcon,
    plug: PluginIcon,
    gear: GearIcon,
    chart: PieChartIcon,
    file: FileIcon,
    map: MapIcon,
    calendar: CalendarIcon,
    task: TaskIcon,
    bell: BellIcon,
    mail: MailIcon,
    page: PageIcon,
};

export const iconFor = (key: string | null | undefined): Component =>
    (key !== null && key !== undefined ? navIcons[key] : undefined) ?? PageIcon;
