<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/Modules/IAM/Http/Web/Controllers/UserController';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { Paginator } from '@/types/master';
import type { RoleOption, UserRow } from '@/types/iam';

const props = defineProps<{
    users: Paginator<UserRow>;
    filters: { q: string; role: string };
    field: boolean;
    roles: RoleOption[];
    can: { create: boolean; update: boolean };
}>();

const listUrl = props.field ? '/iam/engineers' : UserController.index.url();
const heading = props.field ? 'Engineer / Teknisi' : 'Pengguna';

defineOptions({
    layout: { breadcrumbs: [] },
});

const filters = useListFilters(listUrl, { ...props.filters });
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">{{ heading }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ field ? 'Leader dan teknisi lapangan: kode, area, vendor, dan peran.' : 'Akun kantor: admin, koordinator, managerial. Leader dan teknisi ada di menu Engineer / Teknisi.' }}
                </p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="UserController.create()">{{ field ? 'Tambah engineer' : 'Tambah pengguna' }}</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input v-model="filters.q" class="max-w-sm" placeholder="Cari nama, email, atau NIK…" />
            <select v-model="filters.role" class="rounded-lg border bg-transparent px-3 text-sm">
                <option value="">Semua peran</option>
                <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
            </select>
        </div>

        <DataTable :columns="['Nama', 'Jenis', 'Peran', 'Status', '']" :empty="users.data.length === 0">
            <tr v-for="user in users.data" :key="user.ulid">
                <td class="px-5 py-4">
                    <div class="flex items-center gap-3">
                        <img v-if="user.photo_url" :src="user.photo_url" :alt="user.name" class="size-9 rounded-full border object-cover" />
                        <span v-else class="flex size-9 items-center justify-center rounded-full border bg-muted text-sm font-semibold text-muted-foreground">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                        <div>
                    <p class="font-medium text-foreground">{{ user.name }}<span v-if="user.user_code" class="ms-2 font-mono text-xs text-muted-foreground">{{ user.user_code }}</span></p>
                    <p class="text-xs text-gray-500">{{ user.email }}<template v-if="user.employee_no"> · {{ user.employee_no }}</template></p>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-4">{{ user.user_type_label }}</td>
                <td class="px-5 py-4">
                    <span
                        v-for="role in user.roles"
                        :key="role"
                        class="me-1 inline-flex rounded-full bg-accent px-2.5 py-0.5 text-xs font-medium text-primary "
                    >
                        {{ role }}
                    </span>
                </td>
                <td class="px-5 py-4"><StatusBadge :active="user.is_active" /></td>
                <td class="px-5 py-4 text-right whitespace-nowrap">
                    <Button v-if="can.update" variant="ghost" size="sm" as-child>
                        <Link :href="UserController.edit(user.ulid)">Ubah</Link>
                    </Button>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="users" />
    </div>
</template>
