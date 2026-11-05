<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import VendorController from '@/actions/Modules/Master/Http/Web/Controllers/VendorController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { Paginator, Vendor } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vendor', href: VendorController.index() }],
    },
});

const props = defineProps<{
    vendors: Paginator<Vendor>;
    filters: { q: string };
    can: { manage: boolean };
}>();

const filters = useListFilters(VendorController.index.url(), {
    q: props.filters.q,
});
</script>

<template>
    <Head title="Vendor" />

    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Vendor</h1>
                <p class="text-sm text-muted-foreground">
                    Bank atau pemberi kerja. Setiap merchant, TID, dan aset
                    dimiliki satu vendor.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="VendorController.create()">Tambah vendor</Link>
            </Button>
        </div>

        <Input
            v-model="filters.q"
            class="max-w-sm"
            placeholder="Cari kode atau nama…"
        />

        <DataTable
            :columns="['Kode', 'Nama', 'Merchant', 'TID', 'Status', '']"
            :empty="vendors.data.length === 0"
        >
            <tr v-for="vendor in vendors.data" :key="vendor.ulid">
                <td class="px-4 py-3 font-mono font-medium">
                    {{ vendor.code }}
                </td>
                <td class="px-4 py-3">{{ vendor.name }}</td>
                <td class="px-4 py-3">{{ vendor.merchants_count }}</td>
                <td class="px-4 py-3">{{ vendor.terminals_count }}</td>
                <td class="px-4 py-3">
                    <StatusBadge :active="vendor.is_active" />
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <template v-if="can.manage">
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="VendorController.edit(vendor.ulid)"
                                >Ubah</Link
                            >
                        </Button>
                        <ConfirmDelete
                            :url="VendorController.destroy.url(vendor.ulid)"
                            :title="`Hapus vendor ${vendor.code}?`"
                            description="Vendor yang masih memiliki merchant atau TID tidak dapat dihapus. Nonaktifkan vendor sebagai gantinya."
                        />
                    </template>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="vendors" />
    </div>
</template>
