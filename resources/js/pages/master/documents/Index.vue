<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import MerchantDocumentController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantDocumentController';
import DataTable from '@/components/master/DataTable.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { Paginator } from '@/types/master';

type DocumentRow = {
    ulid: string;
    document_number: string;
    vendor: string;
    received_on: string;
    status: string;
    source: string;
    lines: number;
    imported: number;
    errors: number;
};

const props = defineProps<{
    documents: Paginator<DocumentRow>;
    filters: { q: string; status: string };
    can: { manage: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dokumen', href: MerchantDocumentController.index() }] },
});

const filters = useListFilters(MerchantDocumentController.index.url(), { ...props.filters });
</script>

<template>
    <Head title="Dokumen" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Dokumen</h1>
                <p class="text-sm text-muted-foreground">
                    Dokumen dari pemberi kerja. Satu dokumen berisi merchant beserta TID-nya; setelah diimpor, mereka
                    masuk ke daftar Merchant dan TID.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="MerchantDocumentController.create()">Dokumen baru</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input v-model="filters.q" class="max-w-sm" placeholder="Cari nomor dokumen…" />
            <select v-model="filters.status" class="rounded-lg border bg-transparent px-3 text-sm">
                <option value="">Semua status</option>
                <option value="draft">Draf</option>
                <option value="imported">Sudah diimpor</option>
            </select>
        </div>

        <DataTable
            :columns="['Nomor dokumen', 'Vendor', 'Diterima', 'Baris', 'Masuk', 'Bermasalah', 'Status', '']"
            :empty="documents.data.length === 0"
        >
            <tr v-for="doc in documents.data" :key="doc.ulid">
                <td class="px-4 py-3 font-mono">{{ doc.document_number }}</td>
                <td class="px-4 py-3">{{ doc.vendor }}</td>
                <td class="px-4 py-3">{{ doc.received_on }}</td>
                <td class="px-4 py-3">{{ doc.lines }}</td>
                <td class="px-4 py-3">{{ doc.imported }}</td>
                <td class="px-4 py-3" :class="doc.errors > 0 ? 'font-medium text-destructive' : ''">{{ doc.errors }}</td>
                <td class="px-4 py-3">{{ doc.status === 'imported' ? 'Sudah diimpor' : 'Draf' }}</td>
                <td class="px-4 py-3 text-right">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="MerchantDocumentController.show(doc.ulid)">Buka</Link>
                    </Button>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="documents" />
    </div>
</template>
