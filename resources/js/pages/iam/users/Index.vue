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
    roles: RoleOption[];
    can: { create: boolean; update: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }] },
});

const filters = useListFilters(UserController.index.url(), { ...props.filters });
</script>

<template>
    <Head title="Pengguna" />

    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Pengguna</h1>
                <p class="text-sm text-muted-foreground">Staf kantor dan teknisi, beserta peran yang mereka pegang.</p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="UserController.create()">Tambah pengguna</Link>
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
                    <p class="font-medium text-foreground">{{ user.name }}</p>
                    <p class="text-xs text-gray-500">{{ user.email }}<template v-if="user.employee_no"> · {{ user.employee_no }}</template></p>
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
