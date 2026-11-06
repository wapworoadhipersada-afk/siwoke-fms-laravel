<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import NavigationController from '@/actions/Modules/IAM/Http/Web/Controllers/NavigationController';
import MenuEditDialog from '@/components/iam/MenuEditDialog.vue';
import { Button } from '@/components/ui/button';
import { ArrowDownIcon, ArrowUpIcon } from '@/icons/layout';
import type { NavSection } from '@/types';
import type { AuditEntry, MenuEntry, MenuSetting } from '@/types/iam';

const props = defineProps<{
    roles: { id: number; label: string }[];
    selectedRole: number | null;
    settings: MenuSetting[];
    preview: NavSection[];
    menus: MenuEntry[];
    icons: { value: string; label: string }[];
    audits: AuditEntry[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Pengaturan menu', href: NavigationController.index() }] },
});

// What is edited for the chosen role lives here until it is saved.
const local = ref<MenuSetting[]>(props.settings.map((item) => ({ ...item })));
const saving = ref(false);

watch(
    () => props.settings,
    (next) => (local.value = next.map((item) => ({ ...item }))),
);

const groups = computed(() => props.menus.filter((menu) => menu.route_name === null));

const roleChoice = computed({
    get: () => props.selectedRole,
    set: (value: number | null) => router.get(NavigationController.index.url(), value === null ? {} : { role: value }, { preserveScroll: true }),
});

const sameLevel = (item: MenuSetting): MenuSetting[] =>
    local.value
        .filter((other) => other.parent_id === item.parent_id && (item.parent_id !== null || other.section === item.section))
        .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

// The editor shows a group first and its pages right below it, indented.
const rows = computed(() => {
    const ordered: (MenuSetting & { depth: number })[] = [];
    const tops = local.value.filter((item) => item.parent_id === null);
    const sectionOrder = [...new Set(tops.slice().sort((a, b) => a.sort_order - b.sort_order).map((item) => item.section))];

    for (const section of sectionOrder) {
        for (const top of tops.filter((item) => item.section === section).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)) {
            ordered.push({ ...top, depth: 0 });
            for (const child of local.value.filter((item) => item.parent_id === top.id).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)) {
                ordered.push({ ...child, depth: 1 });
            }
        }
    }

    return ordered;
});

const move = (item: MenuSetting, direction: -1 | 1): void => {
    const siblings = sameLevel(item);
    const index = siblings.findIndex((other) => other.id === item.id);
    const target = index + direction;

    if (target < 0 || target >= siblings.length) {
        return;
    }

    [siblings[index], siblings[target]] = [siblings[target], siblings[index]];
    siblings.forEach((sibling, position) => {
        const row = local.value.find((entry) => entry.id === sibling.id);

        if (row) {
            row.sort_order = (position + 1) * 10;
        }
    });
};

const setVisible = (id: number, visible: boolean): void => {
    const row = local.value.find((entry) => entry.id === id);

    if (row) {
        row.visible = visible;
    }
};

const dirty = computed(() => JSON.stringify(local.value) !== JSON.stringify(props.settings));

const save = (): void => {
    if (props.selectedRole === null) {
        return;
    }

    router.put(
        NavigationController.syncRole.url(String(props.selectedRole)),
        { items: local.value.map((item) => ({ menu_id: item.id, is_visible: item.visible, sort_order: item.sort_order })) },
        { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) },
    );
};

const eventLabel = (event: string): string =>
    ({
        'navigation.role_synced': 'Pengaturan menu peran disimpan',
        'navigation.menu_updated': 'Menu diubah',
        'navigation.menu_moved': 'Urutan menu diubah',
    })[event] ?? event;

const formatDate = (value: string | null): string => (value ? new Date(value).toLocaleString('id-ID') : '-');
</script>

<template>
    <Head title="Pengaturan menu" />

    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Pengaturan menu</h1>
                <p class="text-sm text-muted-foreground">
                    Atur menu apa yang tampil dan urutannya untuk tiap peran. Menyembunyikan menu hanya merapikan
                    tampilan; hak akses tetap diatur di halaman Peran.
                </p>
            </div>
            <Form v-bind="NavigationController.sync.form()" :options="{ preserveScroll: true }">
                <Button type="submit" variant="outline">Tambahkan halaman baru ke menu</Button>
            </Form>
        </div>

        <section class="panel p-5 md:p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <label for="role" class="text-sm font-medium text-gray-700 dark:text-gray-300">Menu untuk peran</label>
                    <select id="role" v-model="roleChoice" class="h-10 rounded-lg border border-input bg-transparent px-3 text-sm">
                        <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
                    </select>
                </div>
                <Button :disabled="!dirty || saving" @click="save">Simpan untuk peran ini</Button>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="xl:col-span-2">
                    <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        <li
                            v-for="row in rows"
                            :key="row.id"
                            class="flex items-center gap-3 px-4 py-2.5 text-sm"
                            :class="!row.is_active ? 'opacity-50' : ''"
                            :style="{ paddingLeft: `${16 + row.depth * 28}px` }"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-gray-300 accent-brand-500"
                                :checked="row.visible"
                                :aria-label="`Tampilkan ${row.label}`"
                                @change="setVisible(row.id, ($event.target as HTMLInputElement).checked)"
                            />
                            <span class="flex-1" :class="row.depth === 0 ? 'font-medium text-gray-800 dark:text-white/90' : 'text-gray-700 dark:text-gray-300'">
                                {{ row.label }}
                                <span v-if="!row.is_active" class="text-xs text-gray-500">(dimatikan untuk semua)</span>
                            </span>
                            <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Naikkan" @click="move(row, -1)">
                                <ArrowUpIcon />
                            </button>
                            <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Turunkan" @click="move(row, 1)">
                                <ArrowDownIcon />
                            </button>
                        </li>
                    </ul>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Centang berarti tampil. Menu yang izinnya tidak dimiliki peran ini tetap tidak muncul, apa pun
                        centangnya.
                    </p>
                </div>

                <aside>
                    <h2 class="mb-2 text-sm font-medium text-gray-800 dark:text-white/90">Pratinjau menu peran ini</h2>
                    <p class="mb-2 text-xs text-muted-foreground">Sesuai pengaturan yang sudah tersimpan.</p>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div v-for="section in preview" :key="section.label" class="mb-4 last:mb-0">
                            <p class="mb-2 text-xs text-gray-400 uppercase">{{ section.label }}</p>
                            <ul class="space-y-1 text-sm">
                                <li v-for="item in section.items" :key="item.key">
                                    <p class="font-medium text-gray-700 dark:text-gray-300">{{ item.label }}</p>
                                    <ul v-if="item.children.length > 0" class="ms-4 space-y-0.5 text-gray-500 dark:text-gray-400">
                                        <li v-for="child in item.children" :key="child.key">{{ child.label }}</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                        <p v-if="preview.length === 0" class="text-sm text-muted-foreground">Menu kosong untuk peran ini.</p>
                    </div>
                </aside>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h2 class="font-medium text-gray-800 dark:text-white/90">Semua menu (berlaku untuk semua peran)</h2>
                <p class="text-xs text-muted-foreground">Ubah nama, ikon, grup, atau matikan sebuah menu untuk semua orang.</p>
            </div>
            <table class="w-full text-left text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]">
                        <th class="px-5 py-3 text-theme-xs font-medium text-gray-500">Nama</th>
                        <th class="px-5 py-3 text-theme-xs font-medium text-gray-500">Bagian</th>
                        <th class="px-5 py-3 text-theme-xs font-medium text-gray-500">Izin</th>
                        <th class="px-5 py-3 text-theme-xs font-medium text-gray-500">Urutan</th>
                        <th />
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr v-for="menu in menus" :key="menu.id" :class="!menu.is_active ? 'opacity-50' : ''">
                        <td class="px-5 py-3" :class="menu.parent_id !== null ? 'ps-10' : 'font-medium'">{{ menu.label }}</td>
                        <td class="px-5 py-3 text-gray-500">{{ menu.section }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ menu.permission_name ?? '-' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            <Form v-bind="NavigationController.move.form(menu.id)" :options="{ preserveScroll: true }" class="inline">
                                <input type="hidden" name="direction" value="up" />
                                <input v-if="selectedRole" type="hidden" name="role" :value="selectedRole" />
                                <button type="submit" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Naikkan"><ArrowUpIcon /></button>
                            </Form>
                            <Form v-bind="NavigationController.move.form(menu.id)" :options="{ preserveScroll: true }" class="inline">
                                <input type="hidden" name="direction" value="down" />
                                <input v-if="selectedRole" type="hidden" name="role" :value="selectedRole" />
                                <button type="submit" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Turunkan"><ArrowDownIcon /></button>
                            </Form>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <MenuEditDialog :menu="menu" :groups="groups" :icons="icons" :role-id="selectedRole" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="panel p-5">
            <h2 class="mb-3 font-medium text-gray-800 dark:text-white/90">Perubahan terakhir</h2>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                <li v-for="audit in audits" :key="audit.id" class="flex justify-between gap-3 py-2">
                    <span class="text-gray-700 dark:text-gray-300">{{ eventLabel(audit.event) }} · {{ audit.actor }}</span>
                    <span class="text-gray-500">{{ formatDate(audit.at) }}</span>
                </li>
                <li v-if="audits.length === 0" class="py-2 text-muted-foreground">Belum ada perubahan.</li>
            </ul>
        </section>
    </div>
</template>
