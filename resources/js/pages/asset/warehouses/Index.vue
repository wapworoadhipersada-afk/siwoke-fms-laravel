<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import WarehouseController from '@/actions/Modules/Asset/Http/Web/Controllers/WarehouseController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { WarehouseRow } from '@/types/asset';
import type { Paginator } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Gudang', href: WarehouseController.index() }],
    },
});

const props = defineProps<{
    warehouses: Paginator<WarehouseRow>;
    filters: { q: string };
    can: { manage: boolean };
}>();

const filters = useListFilters(WarehouseController.index.url(), {
    q: props.filters.q,
});
</script>

<template>
    <Head title="Gudang" />

    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Gudang</h1>
                <p class="text-sm text-muted-foreground">
                    Tempat aset disimpan sebelum diserahkan ke teknisi.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="WarehouseController.create()">Tambah gudang</Link>
            </Button>
        </div>

        <Input
            v-model="filters.q"
            class="max-w-sm"
            placeholder="Cari kode atau nama…"
        />

        <DataTable
            :columns="['Kode', 'Nama', 'Lokasi', 'PIC', 'Status', '']"
            :empty="warehouses.data.length === 0"
        >
            <tr v-for="warehouse in warehouses.data" :key="warehouse.ulid">
                <td class="px-4 py-3 font-mono">{{ warehouse.code }}</td>
                <td class="px-4 py-3 font-medium">{{ warehouse.name }}</td>
                <td class="px-4 py-3">{{ warehouse.location }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ warehouse.pic ?? '—' }}
                </td>
                <td class="px-4 py-3">
                    <StatusBadge :active="warehouse.is_active" />
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <template v-if="can.manage">
                        <Button variant="ghost" size="sm" as-child>
                            <Link
                                :href="WarehouseController.edit(warehouse.ulid)"
                                >Ubah</Link
                            >
                        </Button>
                        <ConfirmDelete
                            :url="
                                WarehouseController.destroy.url(warehouse.ulid)
                            "
                            :title="`Hapus gudang ${warehouse.name}?`"
                            description="Gudang yang masih menyimpan aset, stok, atau serah-terima yang belum selesai tidak dapat dihapus."
                        />
                    </template>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="warehouses" />
    </div>
</template>
