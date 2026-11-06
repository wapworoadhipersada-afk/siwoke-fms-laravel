<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import RoleController from '@/actions/Modules/IAM/Http/Web/Controllers/RoleController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import { Button } from '@/components/ui/button';
import { routeArg } from '@/lib/routeArg';
import type { RoleRow } from '@/types/iam';

defineProps<{ roles: RoleRow[] }>();

</script>

<template>
    <Head title="Peran" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Peran</h1>
                <p class="text-sm text-muted-foreground">
                    Peran menentukan apa yang boleh dilakukan seseorang. Menu yang tampil mengikuti peran dan izinnya.
                </p>
            </div>
            <Button as-child>
                <Link :href="RoleController.create()">Tambah peran</Link>
            </Button>
        </div>

        <DataTable :columns="['Peran', 'Deskripsi', 'Izin', 'Pengguna', '']" :empty="roles.length === 0">
            <tr v-for="role in roles" :key="role.id">
                <td class="px-5 py-4">
                    <p class="font-medium text-foreground">{{ role.label }}</p>
                    <p class="font-mono text-xs text-gray-500">{{ role.name }}</p>
                    <span
                        v-if="role.is_system"
                        class="mt-1 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-white/5 dark:text-white/80"
                    >
                        Bawaan
                    </span>
                </td>
                <td class="px-5 py-4 text-gray-500 dark:text-gray-400">{{ role.description ?? '-' }}</td>
                <td class="px-5 py-4">{{ role.locked ? 'Semua' : role.permissions_count }}</td>
                <td class="px-5 py-4">{{ role.users_count }}</td>
                <td class="px-5 py-4 text-right whitespace-nowrap">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="RoleController.edit(routeArg(role.id))">{{ role.locked ? 'Lihat' : 'Ubah' }}</Link>
                    </Button>
                    <ConfirmDelete
                        v-if="!role.is_system"
                        :url="RoleController.destroy.url(routeArg(role.id))"
                        :title="`Hapus peran ${role.label}?`"
                        description="Peran yang masih dipegang pengguna tidak dapat dihapus."
                    />
                </td>
            </tr>
        </DataTable>
    </div>
</template>
