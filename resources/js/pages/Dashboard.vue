<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import DailyTicketsChart from '@/components/dashboard/DailyTicketsChart.vue';
import MetricCard from '@/components/dashboard/MetricCard.vue';
import SlaTargetCard from '@/components/dashboard/SlaTargetCard.vue';
import StatisticsChart from '@/components/dashboard/StatisticsChart.vue';
import { Button } from '@/components/ui/button';
import { BoxIcon, ListIcon } from '@/icons/layout';
import { dashboard } from '@/routes';
import type { AssetStats, IntegrationStats, TicketStats } from '@/types/report';

const props = defineProps<{
    tickets: TicketStats | null;
    assets: AssetStats | null;
    integration: IntegrationStats | null;
    performance_days: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const statusRows = computed(() =>
    props.tickets
        ? Object.entries(props.tickets.by_status).map(([status, total]) => ({
              status,
              label: props.tickets?.status_labels[status] ?? status,
              total,
          }))
        : [],
);

const maxVendor = computed(() => Math.max(1, ...(props.tickets?.by_vendor.map((row) => row.open) ?? [1])));

// Same colour language as the template's order badges: green is done, amber waits, red is trouble.
const statusTone = (status: string): string => {
    if (status === 'finished') {
        return 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500';
    }

    if (status === 'cancelled' || status === 'done_negative') {
        return 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500';
    }

    if (status === 'pending') {
        return 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400';
    }

    return 'bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400';
};
</script>

<template>
    <Head title="Dashboard" />

    <div
        v-if="!tickets && !assets && !integration"
        class="panel p-6 text-sm text-gray-500 dark:text-gray-400"
    >
        Akun Anda belum memiliki akses ke data operasional. Hubungi administrator.
    </div>

    <div v-else class="grid grid-cols-12 gap-4 md:gap-6">
        <section
            v-if="integration && (integration.dead > 0 || integration.waiting > 0)"
            class="col-span-12 rounded-2xl border border-error-200 bg-error-25 p-4 text-sm dark:border-error-500/30 dark:bg-error-500/10"
        >
            <p class="font-medium text-error-700 dark:text-error-400">Perhatian: callback ke vendor</p>
            <p class="text-gray-600 dark:text-gray-400">
                {{ integration.waiting }} menunggu dikirim, {{ integration.dead }} gagal total.
                <Link :href="IntegrationLogController.index()" class="underline">Lihat log integrasi</Link>
            </p>
        </section>

        <template v-if="tickets">
            <div class="col-span-12 space-y-6 xl:col-span-7">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6">
                    <MetricCard
                        title="Tiket aktif"
                        :value="tickets.open"
                        :change="`${tickets.created_today} hari ini`"
                        direction="up"
                    >
                        <template #icon><ListIcon /></template>
                    </MetricCard>
                    <MetricCard
                        title="Lewat batas SLA"
                        :value="tickets.overdue"
                        :change="tickets.overdue > 0 ? 'perlu ditangani' : 'aman'"
                        :direction="tickets.overdue > 0 ? 'down' : 'up'"
                    >
                        <template #icon><BoxIcon /></template>
                    </MetricCard>
                </div>

                <DailyTicketsChart :trend="tickets.trend" />
            </div>

            <div class="col-span-12 xl:col-span-5">
                <SlaTargetCard
                    :percent="tickets.sla_percent"
                    :days="performance_days"
                    :created-today="tickets.created_today"
                    :closed-today="tickets.closed_today"
                    :avg-hours="tickets.avg_resolution_hours"
                />
            </div>

            <div class="col-span-12">
                <StatisticsChart :trend="tickets.trend" />
            </div>

            <div class="col-span-12 xl:col-span-5">
                <div class="h-full rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/3">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Tiket aktif per vendor</h3>
                    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Beban kerja menurut pemberi kerja</p>

                    <div class="mt-6 space-y-5">
                        <div v-for="row in tickets.by_vendor" :key="row.vendor">
                            <div class="flex items-center justify-between">
                                <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ row.vendor }}</p>
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ row.open }}</p>
                            </div>
                            <div class="mt-2 h-2 w-full rounded-sm bg-gray-200 dark:bg-gray-800">
                                <div class="h-full rounded-sm bg-brand-500" :style="{ width: `${(row.open / maxVendor) * 100}%` }" />
                            </div>
                        </div>
                        <p v-if="tickets.by_vendor.length === 0" class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Tidak ada tiket aktif.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-7">
                <div
                    class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pt-4 pb-3 sm:px-6 dark:border-gray-800 dark:bg-white/3"
                >
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Tiket menurut status</h3>
                        <Button variant="outline" as-child>
                            <Link :href="TicketController.index()">Lihat semua</Link>
                        </Button>
                    </div>

                    <div class="max-w-full overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="border-y border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="py-3 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="py-3 text-end text-xs font-medium text-gray-500 dark:text-gray-400">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <tr v-for="row in statusRows" :key="row.status">
                                    <td class="py-3">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                            :class="statusTone(row.status)"
                                        >
                                            {{ row.label }}
                                        </span>
                                    </td>
                                    <td class="py-3 text-end text-sm text-gray-500 dark:text-gray-400">{{ row.total }}</td>
                                </tr>
                                <tr v-if="statusRows.length === 0">
                                    <td colspan="2" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Belum ada tiket.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="tickets.workload.length > 0" class="col-span-12">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/3">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Beban teknisi</h3>
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        <li v-for="row in tickets.workload" :key="row.technician" class="flex justify-between py-3 text-sm">
                            <span class="text-gray-800 dark:text-white/90">{{ row.technician }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ row.active }} tiket</span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <template v-if="assets">
            <div class="col-span-12 xl:col-span-6">
                <div class="h-full rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/3">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Aset menurut status</h3>
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        <li v-for="row in assets.by_status" :key="row.status" class="flex justify-between py-3 text-sm">
                            <span class="text-gray-800 dark:text-white/90">{{ row.label }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ row.total }}</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-span-12 xl:col-span-6">
                <div class="h-full rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/3">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Stok barang habis pakai</h3>
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        <li v-for="row in assets.stock" :key="row.model" class="flex justify-between py-3 text-sm">
                            <span class="text-gray-800 dark:text-white/90">{{ row.model }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ row.quantity }}</span>
                        </li>
                        <li v-if="assets.stock.length === 0" class="py-3 text-sm text-gray-500 dark:text-gray-400">
                            Belum ada stok.
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
