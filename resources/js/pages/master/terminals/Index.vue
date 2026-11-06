<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import TerminalController from '@/actions/Modules/Master/Http/Web/Controllers/TerminalController';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type {
    Option,
    Paginator,
    TerminalRow,
    VendorOption,
} from '@/types/master';

const props = defineProps<{
    terminals: Paginator<TerminalRow>;
    filters: { q: string; vendor: string; status: string };
    vendors: VendorOption[];
    statuses: Option[];
    can: { manage: boolean };
}>();

const filters = useListFilters(TerminalController.index.url(), {
    q: props.filters.q,
    vendor: props.filters.vendor,
    status: props.filters.status,
});

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
</script>

<template>
    <Head title="TID" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">TID</h1>
            <p class="text-sm text-muted-foreground">
                Terminal ID per vendor. Untuk mendaftarkan TID baru, buka
                merchant lalu pilih site-nya.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-xs"
                placeholder="Cari TID…"
            />
            <FormSelect
                v-model="filters.vendor"
                :options="vendorOptions"
                placeholder="Semua vendor"
                class="max-w-56"
            />
            <FormSelect
                v-model="filters.status"
                :options="statuses"
                placeholder="Semua status"
                class="max-w-44"
            />
        </div>

        <DataTable
            :columns="[
                'TID',
                'Vendor',
                'Merchant',
                'Alamat',
                'Area / Service Point',
                'Status',
                '',
            ]"
            :empty="terminals.data.length === 0"
        >
            <tr v-for="terminal in terminals.data" :key="terminal.ulid">
                <td class="px-4 py-3 font-mono font-medium">
                    {{ terminal.tid }}
                </td>
                <td class="px-4 py-3">{{ terminal.vendor }}</td>
                <td class="px-4 py-3">
                    <Link
                        :href="MerchantController.show(terminal.merchant_ulid)"
                        class="underline-offset-4 hover:underline"
                    >
                        {{ terminal.merchant }}
                    </Link>
                </td>
                <td class="max-w-64 truncate px-4 py-3 text-muted-foreground">
                    {{ terminal.address }}
                </td>
                <td class="px-4 py-3">
                    {{ terminal.area }}
                    <span class="block text-xs text-muted-foreground">{{ terminal.service_point }}<template v-if="terminal.province"> · {{ terminal.province }}</template></span>
                </td>
                <td class="px-4 py-3">
                    <StatusBadge
                        :status="terminal.status"
                        :label="terminal.status_label"
                    />
                </td>
                <td class="px-4 py-3 text-right">
                    <Button v-if="can.manage" variant="ghost" size="sm" as-child>
                        <Link :href="TerminalController.edit(terminal.ulid)"
                            >Ubah</Link
                        >
                    </Button>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="terminals" />
    </div>
</template>
