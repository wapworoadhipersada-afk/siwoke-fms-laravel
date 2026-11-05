<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HandoverController from '@/actions/Modules/Asset/Http/Web/Controllers/HandoverController';
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import type { HandoverRow } from '@/types/asset';
import type { Option, Paginator } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Serah-terima', href: HandoverController.index() },
        ],
    },
});

const props = defineProps<{
    handovers: Paginator<HandoverRow>;
    filters: { status: string };
    statuses: Option[];
    can: { issue: boolean };
}>();

const filters = useListFilters(HandoverController.index.url(), {
    status: props.filters.status,
});

const rejecting = ref<HandoverRow | null>(null);

function accept(handover: HandoverRow): void {
    router.post(
        HandoverController.accept.url(handover.ulid),
        {},
        { preserveScroll: true },
    );
}

function cancel(handover: HandoverRow): void {
    router.post(
        HandoverController.cancel.url(handover.ulid),
        {},
        { preserveScroll: true },
    );
}

function statusVariant(status: string) {
    if (status === 'rejected' || status === 'cancelled') {
        return 'destructive';
    }

    return status === 'pending' ? 'secondary' : 'default';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="Serah-terima" />

    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                    Serah-terima
                </h1>
                <p class="text-sm text-muted-foreground">
                    Aset baru berpindah tangan setelah penerima
                    mengonfirmasinya.
                </p>
            </div>
            <Button v-if="can.issue" as-child>
                <Link :href="HandoverController.create()"
                    >Serah ke teknisi</Link
                >
            </Button>
        </div>

        <FormSelect
            v-model="filters.status"
            :options="statuses"
            placeholder="Semua status"
            class="max-w-48"
        />

        <DataTable
            :columns="['Jenis', 'Dari → Ke', 'Isi', 'Diminta', 'Status', '']"
            :empty="handovers.data.length === 0"
            empty-text="Tidak ada serah-terima."
        >
            <tr v-for="handover in handovers.data" :key="handover.ulid">
                <td class="px-4 py-3 whitespace-nowrap">
                    {{ handover.type_label }}
                </td>
                <td class="px-4 py-3">
                    {{ handover.from }}
                    <span class="text-muted-foreground">→</span>
                    {{ handover.to }}
                </td>
                <td class="px-4 py-3">
                    <ul class="space-y-0.5 text-sm">
                        <li v-for="item in handover.items" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </td>
                <td class="px-4 py-3 text-sm text-muted-foreground">
                    {{ handover.requested_by }}<br />
                    {{ formatDate(handover.requested_at) }}
                </td>
                <td class="px-4 py-3">
                    <Badge :variant="statusVariant(handover.status)">{{
                        handover.status_label
                    }}</Badge>
                    <p
                        v-if="handover.resolution_note"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ handover.resolution_note }}
                    </p>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <template v-if="handover.can_resolve">
                        <Button size="sm" @click="accept(handover)"
                            >Terima</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-destructive"
                            @click="rejecting = handover"
                            >Tolak</Button
                        >
                    </template>
                    <Button
                        v-if="handover.can_cancel"
                        size="sm"
                        variant="ghost"
                        @click="cancel(handover)"
                        >Batalkan</Button
                    >
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="handovers" />

        <Dialog :open="rejecting !== null" @update:open="rejecting = null">
            <DialogContent v-if="rejecting">
                <Form
                    v-bind="HandoverController.reject.form(rejecting.ulid)"
                    :options="{ preserveScroll: true }"
                    class="space-y-4"
                    :on-success="() => (rejecting = null)"
                    v-slot="{ errors, processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Tolak serah-terima?</DialogTitle>
                        <DialogDescription
                            >Aset kembali ke pengirim dan tidak ada perpindahan
                            yang tercatat. Jelaskan alasannya.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="reason">Alasan</Label>
                        <Input id="reason" name="reason" required />
                        <InputError :message="errors.reason" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="rejecting = null"
                            >Batal</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                            >Tolak</Button
                        >
                    </div>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
