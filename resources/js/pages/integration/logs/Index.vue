<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { IntegrationLogRow, OutboundSummary } from '@/types/integration';
import type { Paginator } from '@/types/master';

const props = defineProps<{
    logs: Paginator<IntegrationLogRow>;
    filters: { direction: string; outcome: string; q: string };
    outbound: OutboundSummary;
    can: { retry: boolean };
}>();

const filters = useListFilters(IntegrationLogController.index.url(), {
    direction: props.filters.direction,
    outcome: props.filters.outcome,
    q: props.filters.q,
});

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString('id-ID') : '-';

const ok = (status: number | null): boolean => status !== null && status >= 200 && status < 300;
</script>

<template>
    <Head title="Log integrasi" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Log integrasi</h1>
            <p class="text-sm text-muted-foreground">
                Setiap panggilan dari dan ke sistem vendor. Log tidak dapat diubah atau dihapus.
            </p>
        </div>

        <section v-if="outbound.waiting > 0 || outbound.dead.length > 0" class="space-y-3 rounded-lg border p-4">
            <h2 class="font-medium">Callback ke vendor</h2>
            <p class="text-sm text-muted-foreground">
                {{ outbound.waiting }} menunggu dikirim atau dicoba ulang, {{ outbound.dead.length }} gagal total.
            </p>
            <ul v-if="outbound.dead.length > 0" class="divide-y text-sm">
                <li v-for="event in outbound.dead" :key="event.ulid" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div>
                        <p class="font-medium">{{ event.client }} · {{ event.attempts }} percobaan</p>
                        <p class="text-xs text-muted-foreground">{{ event.last_error }}</p>
                    </div>
                    <Form v-if="can.retry" v-bind="IntegrationLogController.retry.form(event.ulid)" :options="{ preserveScroll: true }">
                        <Button type="submit" variant="outline" size="sm">Kirim ulang</Button>
                    </Form>
                </li>
            </ul>
        </section>

        <div class="flex flex-wrap gap-3">
            <Input v-model="filters.q" class="max-w-sm" placeholder="Cari alamat, isi, atau correlation id…" />
            <select v-model="filters.direction" class="rounded-md border bg-transparent px-3 text-sm">
                <option value="">Semua arah</option>
                <option value="in">Masuk</option>
                <option value="out">Keluar</option>
            </select>
            <select v-model="filters.outcome" class="rounded-md border bg-transparent px-3 text-sm">
                <option value="">Semua hasil</option>
                <option value="ok">Berhasil</option>
                <option value="failed">Gagal</option>
            </select>
        </div>

        <DataTable :columns="['Waktu', 'Arah', 'Permintaan', 'Hasil', 'Durasi']" :empty="logs.data.length === 0">
            <tr v-for="log in logs.data" :key="log.id" class="align-top">
                <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(log.created_at) }}</td>
                <td class="px-4 py-3">
                    <Badge variant="outline">{{ log.direction === 'in' ? 'Masuk' : 'Keluar' }}</Badge>
                </td>
                <td class="px-4 py-3">
                    <p class="font-mono text-xs">{{ log.method }} {{ log.path }}</p>
                    <details class="mt-1 text-xs">
                        <summary class="cursor-pointer text-muted-foreground">Rincian</summary>
                        <p class="mt-1 text-muted-foreground">Correlation: {{ log.correlation_id }}</p>
                        <p v-if="log.error" class="mt-1 text-destructive">{{ log.error }}</p>
                        <pre class="mt-1 max-h-48 overflow-auto rounded bg-muted p-2 whitespace-pre-wrap">{{ log.request_body }}</pre>
                        <pre class="mt-1 max-h-48 overflow-auto rounded bg-muted p-2 whitespace-pre-wrap">{{ log.response_body }}</pre>
                    </details>
                </td>
                <td class="px-4 py-3">
                    <Badge :variant="ok(log.status_code) ? 'default' : 'destructive'">{{ log.status_code ?? 'tanpa jawaban' }}</Badge>
                </td>
                <td class="px-4 py-3">{{ log.duration_ms !== null ? `${log.duration_ms} ms` : '-' }}</td>
            </tr>
        </DataTable>

        <Pagination :paginator="logs" />
    </div>
</template>
