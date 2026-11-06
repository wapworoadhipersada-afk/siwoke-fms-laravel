<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import MerchantDocumentController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantDocumentController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import { Button } from '@/components/ui/button';

type Line = {
    line_no: number;
    merchant_name: string;
    tid: string;
    mid: string | null;
    address: string | null;
    province: string | null;
    service_point: string | null;
    area: string | null;
    tid_note: string | null;
    status: 'pending' | 'error' | 'imported';
    error: string | null;
};

defineProps<{
    document: {
        ulid: string;
        document_number: string;
        vendor: string;
        received_on: string;
        notes: string | null;
        status: string;
        source: string;
        source_name: string | null;
        created_by: string | null;
        imported_at: string | null;
    };
    lines: Line[];
    counts: { pending: number; error: number; imported: number };
    can: { manage: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dokumen', href: MerchantDocumentController.index() }] },
});

const statusLabel: Record<Line['status'], string> = {
    pending: 'Siap diimpor',
    error: 'Bermasalah',
    imported: 'Sudah masuk',
};
</script>

<template>
    <Head :title="`Dokumen ${document.document_number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-mono text-xl font-semibold">{{ document.document_number }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ document.vendor }} · diterima {{ document.received_on }} ·
                    {{ document.source === 'upload' ? `file ${document.source_name}` : 'diketik manual' }}
                    <template v-if="document.created_by"> · dicatat {{ document.created_by }}</template>
                </p>
                <p v-if="document.notes" class="mt-1 text-sm">{{ document.notes }}</p>
            </div>

            <div v-if="can.manage" class="flex flex-wrap gap-2">
                <Form v-if="counts.pending > 0" v-bind="MerchantDocumentController.import.form(document.ulid)" v-slot="{ processing }">
                    <Button type="submit" :disabled="processing">Impor {{ counts.pending }} baris</Button>
                </Form>
                <Form v-if="counts.error > 0" v-bind="MerchantDocumentController.recheck.form(document.ulid)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" :disabled="processing">Periksa ulang baris bermasalah</Button>
                </Form>
                <ConfirmDelete
                    v-if="counts.imported === 0"
                    :url="MerchantDocumentController.destroy.url(document.ulid)"
                    :title="`Hapus draf ${document.document_number}?`"
                    description="Draf dan semua barisnya dihapus. Belum ada TID yang masuk dari dokumen ini."
                />
            </div>
        </div>

        <div class="flex flex-wrap gap-4 text-sm">
            <span class="rounded-lg border px-3 py-2">Siap diimpor: <strong>{{ counts.pending }}</strong></span>
            <span class="rounded-lg border px-3 py-2" :class="counts.error > 0 ? 'border-destructive text-destructive' : ''">
                Bermasalah: <strong>{{ counts.error }}</strong>
            </span>
            <span class="rounded-lg border px-3 py-2">Sudah masuk: <strong>{{ counts.imported }}</strong></span>
        </div>

        <DataTable
            :columns="['#', 'Merchant', 'TID', 'MID', 'Alamat', 'Provinsi / SP / Area', 'Keterangan', 'Status']"
            :empty="lines.length === 0"
        >
            <tr v-for="line in lines" :key="line.line_no" :class="line.status === 'error' ? 'bg-destructive/5' : ''">
                <td class="px-4 py-3 text-muted-foreground">{{ line.line_no }}</td>
                <td class="px-4 py-3 font-medium">{{ line.merchant_name }}</td>
                <td class="px-4 py-3 font-mono">{{ line.tid }}</td>
                <td class="px-4 py-3 font-mono">{{ line.mid ?? '—' }}</td>
                <td class="px-4 py-3">{{ line.address ?? '—' }}</td>
                <td class="px-4 py-3 text-xs">{{ [line.province, line.service_point, line.area].filter(Boolean).join(' / ') || '—' }}</td>
                <td class="px-4 py-3 text-xs">{{ line.tid_note ?? '' }}</td>
                <td class="px-4 py-3">
                    <p :class="line.status === 'error' ? 'font-medium text-destructive' : ''">{{ statusLabel[line.status] }}</p>
                    <p v-if="line.error" class="text-xs text-destructive">{{ line.error }}</p>
                </td>
            </tr>
        </DataTable>
    </div>
</template>
