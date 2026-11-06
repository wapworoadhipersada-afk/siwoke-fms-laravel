<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import type { AssetStats, IntegrationStats, TicketStats } from '@/types/report';

const props = defineProps<{
    tickets: TicketStats | null;
    assets: AssetStats | null;
    integration: IntegrationStats | null;
    performance_days: number;
}>();

const maxTrend = computed(() =>
    Math.max(1, ...(props.tickets?.trend.flatMap((day) => [day.created, day.closed]) ?? [1])),
);

const barHeight = (value: number): string => `${Math.max(value > 0 ? 6 : 2, (value / maxTrend.value) * 100)}%`;

const shortDate = (value: string): string => value.slice(8, 10) + '/' + value.slice(5, 7);

const statusRows = computed(() =>
    props.tickets
        ? Object.entries(props.tickets.by_status).map(([status, total]) => ({
              status,
              label: props.tickets?.status_labels[status] ?? status,
              total,
          }))
        : [],
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div v-if="!tickets && !assets && !integration" class="rounded-lg border p-6 text-sm text-muted-foreground">
            Akun Anda belum memiliki akses ke data operasional. Hubungi administrator.
        </div>

        <section v-if="integration && (integration.dead > 0 || integration.waiting > 0)" class="rounded-lg border border-destructive/40 p-4 text-sm">
            <p class="font-medium">Perhatian: callback ke vendor</p>
            <p class="text-muted-foreground">
                {{ integration.waiting }} menunggu dikirim, {{ integration.dead }} gagal total.
                <Link :href="IntegrationLogController.index()" class="underline">Lihat log integrasi</Link>
            </p>
        </section>

        <template v-if="tickets">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Tiket aktif</p>
                    <p class="text-3xl font-semibold">{{ tickets.open }}</p>
                </div>
                <div class="rounded-lg border p-4" :class="tickets.overdue > 0 ? 'border-destructive/50' : ''">
                    <p class="text-sm text-muted-foreground">Lewat batas SLA</p>
                    <p class="text-3xl font-semibold" :class="tickets.overdue > 0 ? 'text-destructive' : ''">{{ tickets.overdue }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Masuk / selesai hari ini</p>
                    <p class="text-3xl font-semibold">{{ tickets.created_today }} / {{ tickets.closed_today }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">SLA tercapai ({{ performance_days }} hari)</p>
                    <p class="text-3xl font-semibold">{{ tickets.sla_percent !== null ? `${tickets.sla_percent}%` : '—' }}</p>
                    <p v-if="tickets.avg_resolution_hours !== null" class="text-xs text-muted-foreground">
                        Rata-rata selesai {{ tickets.avg_resolution_hours }} jam
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-lg border p-4 lg:col-span-2">
                    <h2 class="mb-3 font-medium">Tiket masuk dan selesai</h2>
                    <div class="flex h-40 items-end gap-1" role="img" aria-label="Grafik tiket masuk dan selesai per hari">
                        <div v-for="day in tickets.trend" :key="day.date" class="flex h-full flex-1 flex-col justify-end gap-1">
                            <div class="flex flex-1 items-end justify-center gap-0.5">
                                <div class="w-full rounded-sm bg-primary" :style="{ height: barHeight(day.created) }" :title="`${day.date}: ${day.created} masuk`" />
                                <div class="w-full rounded-sm bg-primary/35" :style="{ height: barHeight(day.closed) }" :title="`${day.date}: ${day.closed} selesai`" />
                            </div>
                            <p class="text-center text-[10px] text-muted-foreground">{{ shortDate(day.date) }}</p>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        <span class="mr-1 inline-block size-2 rounded-sm bg-primary" /> Masuk
                        <span class="mr-1 ml-3 inline-block size-2 rounded-sm bg-primary/35" /> Selesai
                    </p>
                </section>

                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">Menurut status</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="row in statusRows" :key="row.status" class="flex justify-between">
                            <span>{{ row.label }}</span>
                            <span class="font-medium">{{ row.total }}</span>
                        </li>
                        <li v-if="statusRows.length === 0" class="text-muted-foreground">Belum ada tiket.</li>
                    </ul>
                    <Link :href="TicketController.index()" class="mt-3 inline-block text-sm underline">Buka daftar tiket</Link>
                </section>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">Tiket aktif per vendor</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="row in tickets.by_vendor" :key="row.vendor" class="flex justify-between">
                            <span>{{ row.vendor }}</span>
                            <span class="font-medium">{{ row.open }}</span>
                        </li>
                        <li v-if="tickets.by_vendor.length === 0" class="text-muted-foreground">Tidak ada tiket aktif.</li>
                    </ul>
                </section>

                <section v-if="tickets.workload.length > 0" class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">Beban teknisi</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="row in tickets.workload" :key="row.technician" class="flex justify-between">
                            <span>{{ row.technician }}</span>
                            <span class="font-medium">{{ row.active }} tiket</span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <div v-if="assets" class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border p-4">
                <h2 class="mb-3 font-medium">Aset menurut status</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="row in assets.by_status" :key="row.status" class="flex justify-between">
                        <span>{{ row.label }}</span>
                        <span class="font-medium">{{ row.total }}</span>
                    </li>
                </ul>
            </section>
            <section class="rounded-lg border p-4">
                <h2 class="mb-3 font-medium">Stok barang habis pakai</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="row in assets.stock" :key="row.model" class="flex justify-between">
                        <span>{{ row.model }}</span>
                        <span class="font-medium">{{ row.quantity }}</span>
                    </li>
                    <li v-if="assets.stock.length === 0" class="text-muted-foreground">Belum ada stok.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
