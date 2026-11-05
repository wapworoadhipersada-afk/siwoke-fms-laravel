<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import StockController from '@/actions/Modules/Asset/Http/Web/Controllers/StockController';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { StockRow } from '@/types/asset';
import type { Paginator, VendorOption } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stok', href: StockController.index() }],
    },
});

const props = defineProps<{
    stocks: Paginator<StockRow>;
    filters: { q: string; vendor: string };
    vendors: VendorOption[];
}>();

const filters = useListFilters(StockController.index.url(), {
    q: props.filters.q,
    vendor: props.filters.vendor,
});

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
</script>

<template>
    <Head title="Stok" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Stok</h1>
            <p class="text-sm text-muted-foreground">
                Saldo barang habis pakai (thermal paper, charger) per gudang dan
                per teknisi, dipisah per vendor.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-xs"
                placeholder="Cari barang…"
            />
            <FormSelect
                v-model="filters.vendor"
                :options="vendorOptions"
                placeholder="Semua vendor"
                class="max-w-56"
            />
        </div>

        <DataTable
            :columns="['Barang', 'Vendor', 'Dipegang oleh', 'Jenis pemegang', 'Jumlah']"
            :empty="stocks.data.length === 0"
            empty-text="Belum ada stok."
        >
            <tr v-for="stock in stocks.data" :key="stock.id">
                <td class="px-4 py-3 font-medium">{{ stock.model }}</td>
                <td class="px-4 py-3">{{ stock.vendor }}</td>
                <td class="px-4 py-3">{{ stock.holder }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ stock.holder_type }}
                </td>
                <td class="px-4 py-3 font-mono">{{ stock.quantity }}</td>
            </tr>
        </DataTable>

        <Pagination :paginator="stocks" />
    </div>
</template>
