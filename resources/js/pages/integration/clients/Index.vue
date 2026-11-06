<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import IntegrationClientController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationClientController';
import DataTable from '@/components/master/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { IntegrationClientRow } from '@/types/integration';

defineProps<{ clients: IntegrationClientRow[] }>();

// Replacing a secret cuts off whoever still uses the old one, so ask first.
const confirmRotate = (event: Event): void => {
    if (!window.confirm('Ganti kunci rahasia? Kunci lama langsung berhenti berlaku.')) {
        event.preventDefault();
    }
};

const formatDate =(value: string | null): string =>
    value ? new Date(value).toLocaleString('id-ID') : 'Belum pernah';
</script>

<template>
    <Head title="Klien integrasi" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Klien integrasi</h1>
                <p class="text-sm text-muted-foreground">
                    Sistem vendor yang boleh mengirim tiket ke kita dan menerima kabar status. Kunci rahasia hanya
                    ditampilkan sekali saat dibuat atau diganti.
                </p>
            </div>
            <Button as-child>
                <Link :href="IntegrationClientController.create()">Tambah klien</Link>
            </Button>
        </div>

        <DataTable
            :columns="['Nama', 'Vendor', 'Key ID', 'Callback', 'Terakhir dipakai', 'Status', '']"
            :empty="clients.length === 0"
        >
            <tr v-for="client in clients" :key="client.ulid">
                <td class="px-4 py-3 font-medium">{{ client.name }}</td>
                <td class="px-4 py-3">{{ client.vendor }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ client.key_id }}</td>
                <td class="px-4 py-3 text-xs">{{ client.webhook_url ?? '—' }}</td>
                <td class="px-4 py-3">{{ formatDate(client.last_used_at) }}</td>
                <td class="px-4 py-3">
                    <Badge :variant="client.is_active ? 'default' : 'outline'">
                        {{ client.is_active ? 'Aktif' : 'Nonaktif' }}
                    </Badge>
                </td>
                <td class="flex justify-end gap-2 px-4 py-3 whitespace-nowrap">
                    <Form v-bind="IntegrationClientController.toggle.form(client.ulid)" :options="{ preserveScroll: true }">
                        <Button type="submit" variant="outline" size="sm">
                            {{ client.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                        </Button>
                    </Form>
                    <Form
                        v-bind="IntegrationClientController.rotate.form(client.ulid)"
                        @submit="confirmRotate"
                    >
                        <Button type="submit" variant="outline" size="sm">Ganti kunci</Button>
                    </Form>
                </td>
            </tr>
        </DataTable>
    </div>
</template>
