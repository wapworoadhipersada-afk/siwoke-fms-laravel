<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AssetController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetController';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { AssetRow, ModelOption } from '@/types/asset';
import type { Option, Paginator, VendorOption } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Aset', href: AssetController.index() }],
    },
});

const props = defineProps<{
    assets: Paginator<AssetRow>;
    filters: { q: string; status: string; vendor: string; model: string };
    statuses: Option[];
    vendors: VendorOption[];
    models: ModelOption[];
    can: { receive: boolean };
}>();

const filters = useListFilters(AssetController.index.url(), {
    q: props.filters.q,
    status: props.filters.status,
    vendor: props.filters.vendor,
    model: props.filters.model,
});

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
const modelOptions = computed(() =>
    props.models.map((model) => ({ value: model.ulid, label: model.label })),
);

function statusVariant(status: string) {
    if (status === 'lost' || status === 'retired') {
        return 'destructive';
    }

    if (status === 'in_transit' || status === 'in_repair') {
        return 'secondary';
    }

    return 'default';
}
</script>

<template>
    <Head title="Aset" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Aset</h1>
                <p class="text-sm text-muted-foreground">
                    Mesin EDC, SIM, dan SAM. Posisi dan pemegangnya selalu
                    terkini.
                </p>
            </div>
            <Button v-if="can.receive" as-child>
                <Link :href="AssetController.create()">Terima aset baru</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-xs"
                placeholder="Cari nomor seri atau tag…"
            />
            <FormSelect
                v-model="filters.status"
                :options="statuses"
                placeholder="Semua status"
                class="max-w-48"
            />
            <FormSelect
                v-model="filters.vendor"
                :options="vendorOptions"
                placeholder="Semua vendor"
                class="max-w-52"
            />
            <FormSelect
                v-model="filters.model"
                :options="modelOptions"
                placeholder="Semua jenis"
                class="max-w-64"
            />
        </div>

        <DataTable
            :columns="[
                'Nomor seri',
                'Jenis',
                'Vendor',
                'Status',
                'Pemegang',
                'Kondisi',
                '',
            ]"
            :empty="assets.data.length === 0"
        >
            <tr v-for="asset in assets.data" :key="asset.ulid">
                <td class="px-4 py-3 font-mono font-medium">
                    {{ asset.serial_number }}
                    <span
                        v-if="asset.asset_tag"
                        class="ml-1 text-xs text-muted-foreground"
                        >({{ asset.asset_tag }})</span
                    >
                </td>
                <td class="px-4 py-3">{{ asset.model }}</td>
                <td class="px-4 py-3">{{ asset.vendor }}</td>
                <td class="px-4 py-3">
                    <Badge :variant="statusVariant(asset.status)">{{
                        asset.status_label
                    }}</Badge>
                </td>
                <td class="px-4 py-3">{{ asset.holder }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ asset.condition_label }}
                </td>
                <td class="px-4 py-3 text-right">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="AssetController.show(asset.ulid)"
                            >Detail</Link
                        >
                    </Button>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="assets" />
    </div>
</template>
