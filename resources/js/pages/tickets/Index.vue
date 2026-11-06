<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { Option, Paginator, UlidOption, VendorOption } from '@/types/master';
import type { TicketRow, TicketTypeOption } from '@/types/ticket';

const props = defineProps<{
    tickets: Paginator<TicketRow>;
    filters: {
        q: string;
        status: string;
        type: string;
        vendor: string;
        technician: string;
        overdue: string;
    };
    statuses: Option[];
    types: TicketTypeOption[];
    vendors: VendorOption[];
    technicians: UlidOption[];
    can: { create: boolean };
}>();

const filters = useListFilters(TicketController.index.url(), {
    q: props.filters.q,
    status: props.filters.status,
    type: props.filters.type,
    vendor: props.filters.vendor,
    technician: props.filters.technician,
    overdue: props.filters.overdue,
});

const typeOptions = computed(() =>
    props.types.map((type) => ({ value: type.ulid, label: type.name })),
);
const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
const technicianOptions = computed(() =>
    props.technicians.map((technician) => ({
        value: technician.ulid,
        label: technician.label,
    })),
);

function statusVariant(status: string) {
    if (status === 'finished') {
        return 'default';
    }

    if (status === 'done_negative' || status === 'cancelled') {
        return 'destructive';
    }

    return 'secondary';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="Tiket" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Tiket</h1>
                <p class="text-sm text-muted-foreground">
                    Pekerjaan lapangan untuk mesin EDC: pemasangan, perawatan,
                    penarikan, dan pergantian.
                </p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="TicketController.create()">Buat tiket</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-xs"
                placeholder="Cari no. tiket, TID, merchant…"
            />
            <FormSelect
                v-model="filters.status"
                :options="statuses"
                placeholder="Semua status"
                class="max-w-48"
            />
            <FormSelect
                v-model="filters.type"
                :options="typeOptions"
                placeholder="Semua jenis"
                class="max-w-48"
            />
            <FormSelect
                v-model="filters.vendor"
                :options="vendorOptions"
                placeholder="Semua vendor"
                class="max-w-52"
            />
            <FormSelect
                v-if="technicians.length > 0"
                v-model="filters.technician"
                :options="technicianOptions"
                placeholder="Semua teknisi"
                class="max-w-48"
            />
            <FormSelect
                v-model="filters.overdue"
                :options="[{ value: '1', label: 'Terlambat SLA' }]"
                placeholder="Semua SLA"
                class="max-w-40"
            />
        </div>

        <DataTable
            :columns="[
                'No. tiket',
                'Jenis',
                'Vendor',
                'TID',
                'Merchant',
                'Status',
                'Teknisi',
                'Batas SLA',
            ]"
            :empty="tickets.data.length === 0"
            empty-text="Tidak ada tiket."
        >
            <tr v-for="ticket in tickets.data" :key="ticket.ulid">
                <td class="px-4 py-3">
                    <Link
                        :href="TicketController.show(ticket.ulid)"
                        class="font-mono font-medium underline-offset-4 hover:underline"
                        >{{ ticket.ticket_no }}</Link
                    >
                    <p
                        v-if="ticket.vendor_ticket_no"
                        class="text-xs text-muted-foreground"
                    >
                        {{ ticket.vendor_ticket_no }}
                    </p>
                </td>
                <td class="px-4 py-3">
                    {{ ticket.type }}
                    <Badge
                        v-if="ticket.priority === 'urgent' || ticket.priority === 'high'"
                        variant="destructive"
                        class="ml-1"
                        >{{ ticket.priority_label }}</Badge
                    >
                </td>
                <td class="px-4 py-3">{{ ticket.vendor }}</td>
                <td class="px-4 py-3 font-mono">{{ ticket.tid }}</td>
                <td class="px-4 py-3">{{ ticket.merchant }}</td>
                <td class="px-4 py-3">
                    <Badge :variant="statusVariant(ticket.status)">{{
                        ticket.status_label
                    }}</Badge>
                </td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ ticket.technician ?? '—' }}
                </td>
                <td
                    class="px-4 py-3 text-sm whitespace-nowrap"
                    :class="ticket.is_overdue ? 'font-medium text-destructive' : ''"
                >
                    {{ formatDate(ticket.sla_due_at) }}
                    <span v-if="ticket.is_overdue"> · terlambat</span>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="tickets" />
    </div>
</template>
