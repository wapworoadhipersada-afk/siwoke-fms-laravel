<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import NavigationController from '@/actions/Modules/IAM/Http/Web/Controllers/NavigationController';
import AddMenuDialog from '@/components/iam/AddMenuDialog.vue';
import MenuEditDialog from '@/components/iam/MenuEditDialog.vue';
import { Button } from '@/components/ui/button';
import { ArrowDown as ArrowDownIcon, ArrowUp as ArrowUpIcon } from '@lucide/vue';
import type { AuditEntry, MenuEntry } from '@/types/iam';

const props = defineProps<{
    menus: MenuEntry[];
    sections: string[];
    pages: { value: string; label: string }[];
    icons: { value: string; label: string }[];
    audits: AuditEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pengaturan', href: '/iam/settings' },
            { title: 'Pengaturan Menu', href: NavigationController.index() },
        ],
    },
});

const groups = computed(() => props.menus.filter((menu) => menu.is_group));

const destination = (menu: MenuEntry): string => {
    if (menu.is_group) {
        return 'Group';
    }

    return menu.url ?? menu.route_name ?? '-';
};

const eventLabel = (event: string): string =>
    ({
        'navigation.role_synced': 'Menu per peran disimpan',
        'navigation.menu_updated': 'Menu diubah',
        'navigation.menu_moved': 'Urutan menu diubah',
        'navigation.entry_created': 'Group atau menu dibuat',
        'navigation.entry_deleted': 'Group atau menu dihapus',
    })[event] ?? event;

const formatDate = (value: string | null): string => (value ? new Date(value).toLocaleString('id-ID') : '-');
</script>

<template>
    <Head title="Pengaturan Menu" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Pengaturan Menu</h1>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    Susunan menu untuk semua orang: group, nama, alamat tujuan, dan urutannya. Siapa yang melihat
                    menu apa diatur terpisah di
                    <Link :href="NavigationController.access()" class="underline">Menu per Peran</Link>.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <AddMenuDialog mode="group" :groups="groups" :sections="sections" :pages="pages" :icons="icons" />
                <AddMenuDialog mode="menu" :groups="groups" :sections="sections" :pages="pages" :icons="icons" />
                <Form v-bind="NavigationController.sync.form()" :options="{ preserveScroll: true }">
                    <Button type="submit" variant="outline">Tambahkan halaman baru ke menu</Button>
                </Form>
            </div>
        </div>

        <section class="overflow-hidden rounded-xl border bg-card">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b bg-muted/40">
                        <th class="px-5 py-3 text-xs font-medium text-muted-foreground">Nama</th>
                        <th class="px-5 py-3 text-xs font-medium text-muted-foreground">Bagian</th>
                        <th class="px-5 py-3 text-xs font-medium text-muted-foreground">Tujuan</th>
                        <th class="px-5 py-3 text-xs font-medium text-muted-foreground">Urutan</th>
                        <th />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="menu in menus" :key="menu.id" :class="!menu.is_active ? 'opacity-50' : ''">
                        <td class="px-5 py-3" :class="menu.parent_id !== null ? 'ps-10' : 'font-medium'">
                            {{ menu.label }}
                            <span v-if="menu.is_custom" class="ms-1 rounded-full bg-accent px-2 py-0.5 text-xs font-normal text-primary">buatan sendiri</span>
                            <span v-if="!menu.is_active" class="ms-1 text-xs font-normal text-muted-foreground">(dimatikan)</span>
                        </td>
                        <td class="px-5 py-3 text-muted-foreground">{{ menu.section }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-muted-foreground">{{ destination(menu) }}</td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            <Form v-bind="NavigationController.move.form(menu.id)" :options="{ preserveScroll: true }" class="inline">
                                <input type="hidden" name="direction" value="up" />
                                <button type="submit" class="rounded p-1 text-muted-foreground hover:bg-accent" aria-label="Naikkan"><ArrowUpIcon /></button>
                            </Form>
                            <Form v-bind="NavigationController.move.form(menu.id)" :options="{ preserveScroll: true }" class="inline">
                                <input type="hidden" name="direction" value="down" />
                                <button type="submit" class="rounded p-1 text-muted-foreground hover:bg-accent" aria-label="Turunkan"><ArrowDownIcon /></button>
                            </Form>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <MenuEditDialog :menu="menu" :groups="groups" :icons="icons" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="rounded-xl border bg-card p-5">
            <h2 class="mb-3 font-medium text-foreground">Perubahan terakhir</h2>
            <ul class="divide-y text-sm">
                <li v-for="audit in audits" :key="audit.id" class="flex justify-between gap-3 py-2">
                    <span>{{ eventLabel(audit.event) }} · {{ audit.actor }}</span>
                    <span class="text-muted-foreground">{{ formatDate(audit.at) }}</span>
                </li>
                <li v-if="audits.length === 0" class="py-2 text-muted-foreground">Belum ada perubahan.</li>
            </ul>
        </section>
    </div>
</template>
