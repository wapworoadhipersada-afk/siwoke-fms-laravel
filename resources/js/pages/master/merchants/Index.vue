<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { MerchantRow, Paginator, VendorOption } from '@/types/master';

const props = defineProps<{
    merchants: Paginator<MerchantRow>;
    filters: { q: string; vendor: string };
    vendors: VendorOption[];
    can: { manage: boolean };
}>();

const filters = useListFilters(MerchantController.index.url(), {
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
    <Head title="Merchant" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Merchant</h1>
                <p class="text-sm text-muted-foreground">
                    Merchant beserta site dan TID-nya.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="MerchantController.create()"
                    >Tambah merchant</Link
                >
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-sm"
                placeholder="Cari MID atau nama…"
            />
            <FormSelect
                v-model="filters.vendor"
                :options="vendorOptions"
                placeholder="Semua vendor"
                class="max-w-56"
            />
        </div>

        <DataTable
            :columns="['MID', 'Nama', 'Vendor', 'Kategori', 'Site', '']"
            :empty="merchants.data.length === 0"
        >
            <tr v-for="merchant in merchants.data" :key="merchant.ulid">
                <td class="px-4 py-3 font-mono">
                    {{ merchant.merchant_code }}
                </td>
                <td class="px-4 py-3 font-medium">{{ merchant.name }}</td>
                <td class="px-4 py-3">{{ merchant.vendor }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ merchant.category ?? '—' }}
                </td>
                <td class="px-4 py-3">{{ merchant.sites_count }}</td>
                <td class="px-4 py-3 text-right">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="MerchantController.show(merchant.ulid)"
                            >Detail</Link
                        >
                    </Button>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="merchants" />
    </div>
</template>
