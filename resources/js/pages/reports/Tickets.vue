<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TicketReportController from '@/actions/Modules/Report/Http/Web/Controllers/TicketReportController';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { Paginator, UlidOption } from '@/types/master';
import type { ReportFilters, ReportRow } from '@/types/report';

const props = defineProps<{
    columns: string[];
    tickets: Paginator<ReportRow>;
    filters: ReportFilters;
    vendors: UlidOption[];
    types: { code: string; label: string }[];
    statuses: { value: string; label: string }[];
    can: { export: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Laporan tiket', href: TicketReportController.index() }],
    },
});

const filters = useListFilters(TicketReportController.index.url(), { ...props.filters });

// The export carries the same filters, so the file matches what is on screen.
const exportUrl = (): string => {
    const query = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => typeof value === 'string' && value !== '') as [string, string][],
    ).toString();

    return TicketReportController.export.url() + (query ? `?${query}` : '');
};
</script>

<template>
    <Head title="Laporan tiket" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Laporan tiket</h1>
                <p class="text-sm text-muted-foreground">Hanya tiket di area Anda. Ekspor memakai filter yang sama.</p>
            </div>
            <Button v-if="can.export" as-child variant="outline">
                <a :href="exportUrl()">Unduh CSV</a>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input v-model="filters.q" class="max-w-xs" placeholder="Cari no. tiket, TID, merchant…" />
            <Input v-model="filters.from" type="date" class="w-40" aria-label="Dari tanggal" />
            <Input v-model="filters.to" type="date" class="w-40" aria-label="Sampai tanggal" />
            <select v-model="filters.vendor" class="rounded-md border bg-transparent px-3 text-sm">
                <option value="">Semua vendor</option>
                <option v-for="vendor in vendors" :key="vendor.ulid" :value="vendor.ulid">{{ vendor.label }}</option>
            </select>
            <select v-model="filters.type" class="rounded-md border bg-transparent px-3 text-sm">
                <option value="">Semua jenis</option>
                <option v-for="type in types" :key="type.code" :value="type.code">{{ type.label }}</option>
            </select>
            <select v-model="filters.status" class="rounded-md border bg-transparent px-3 text-sm">
                <option value="">Semua status</option>
                <option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
            </select>
        </div>

        <DataTable :columns="columns" :empty="tickets.data.length === 0">
            <tr v-for="row in tickets.data" :key="row.id">
                <td v-for="(cell, index) in row.cells" :key="index" class="px-4 py-3 whitespace-nowrap">{{ cell }}</td>
            </tr>
        </DataTable>

        <Pagination :paginator="tickets" />
    </div>
</template>
