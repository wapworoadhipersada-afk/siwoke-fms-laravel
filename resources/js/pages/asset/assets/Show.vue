<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AssetController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetController';
import HandoverController from '@/actions/Modules/Asset/Http/Web/Controllers/HandoverController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AssetDetail, HistoryEntry } from '@/types/asset';
import type { Option, UlidOption } from '@/types/master';

const props = defineProps<{
    asset: AssetDetail;
    history: HistoryEntry[];
    conditions: Option[];
    warehouses: UlidOption[];
    can: { update: boolean; approve: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Aset', href: AssetController.index() }],
    },
});

const action = ref('');

// Only moves that make sense for where the asset is now. The server enforces the same rules.
const actions = computed<Option[]>(() => {
    const options: Option[] = [];
    const status = props.asset.status;

    if (props.can.update && status === 'in_stock') {
        options.push({ value: 'send_to_repair', label: 'Kirim ke perbaikan' });
    }

    if (props.can.update && status === 'in_repair') {
        options.push({
            value: 'return_from_repair',
            label: 'Kembali dari perbaikan',
        });
    }

    if (
        props.can.approve &&
        ['in_stock', 'with_technician', 'installed', 'in_repair'].includes(
            status,
        )
    ) {
        options.push({ value: 'mark_lost', label: 'Nyatakan hilang' });
    }

    if (props.can.approve && ['in_stock', 'in_repair'].includes(status)) {
        options.push({ value: 'retire', label: 'Pensiunkan' });
    }

    return options;
});

const warehouseOptions = computed(() =>
    props.warehouses.map((warehouse) => ({
        value: warehouse.ulid,
        label: warehouse.label,
    })),
);

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head :title="asset.serial_number" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1
                    class="flex items-center gap-3 font-mono text-xl font-semibold tracking-tight"
                >
                    {{ asset.serial_number }}
                    <Badge>{{ asset.status_label }}</Badge>
                </h1>
                <dl
                    class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm"
                >
                    <dt class="text-muted-foreground">Jenis</dt>
                    <dd>{{ asset.category }}: {{ asset.model }}</dd>
                    <dt class="text-muted-foreground">Vendor pemilik</dt>
                    <dd>{{ asset.vendor }}</dd>
                    <dt class="text-muted-foreground">Sekarang di</dt>
                    <dd class="font-medium">{{ asset.holder }}</dd>
                    <dt class="text-muted-foreground">Kondisi</dt>
                    <dd>{{ asset.condition_label }}</dd>
                    <dt class="text-muted-foreground">Tag</dt>
                    <dd>{{ asset.asset_tag ?? '—' }}</dd>
                </dl>
                <p
                    v-if="asset.pending_handover"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    Menunggu konfirmasi serah-terima.
                    <Link
                        :href="HandoverController.index()"
                        class="underline underline-offset-4"
                        >Lihat serah-terima</Link
                    >
                </p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card v-if="can.update">
                <CardHeader>
                    <CardTitle>Kondisi dan tag</CardTitle>
                    <CardDescription
                        >Lokasi dan pemegang hanya berubah lewat perpindahan
                        yang tercatat.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="AssetController.update.form(asset.ulid)"
                        class="space-y-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="condition">Kondisi</Label>
                            <FormSelect
                                id="condition"
                                name="condition"
                                :options="conditions"
                                :default-value="asset.condition"
                            />
                            <InputError :message="errors.condition" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="asset_tag">Tag aset</Label>
                            <Input
                                id="asset_tag"
                                name="asset_tag"
                                :default-value="asset.asset_tag ?? ''"
                            />
                            <InputError :message="errors.asset_tag" />
                        </div>
                        <Button type="submit" :disabled="processing"
                            >Simpan</Button
                        >
                    </Form>
                </CardContent>
            </Card>

            <Card v-if="actions.length > 0">
                <CardHeader>
                    <CardTitle>Catat perpindahan</CardTitle>
                    <CardDescription
                        >Perbaikan, kehilangan, dan pensiun. Serah ke teknisi
                        lewat menu Serah-terima.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="AssetController.move.form(asset.ulid)"
                        class="space-y-4"
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="type">Tindakan</Label>
                            <FormSelect
                                id="type"
                                v-model="action"
                                name="type"
                                :options="actions"
                                placeholder="Pilih tindakan…"
                                required
                            />
                            <InputError :message="errors.type" />
                        </div>

                        <div
                            v-if="action === 'return_from_repair'"
                            class="grid gap-2"
                        >
                            <Label for="warehouse">Gudang tujuan</Label>
                            <FormSelect
                                id="warehouse"
                                name="warehouse"
                                :options="warehouseOptions"
                                placeholder="Pilih gudang…"
                                required
                            />
                            <InputError :message="errors.warehouse" />
                        </div>

                        <div
                            v-if="action === 'mark_lost' || action === 'retire'"
                            class="grid gap-2"
                        >
                            <Label for="notes">Alasan</Label>
                            <Input id="notes" name="notes" required />
                            <InputError :message="errors.notes" />
                        </div>

                        <Button
                            type="submit"
                            :disabled="processing || !action"
                            variant="secondary"
                            >Catat</Button
                        >
                    </Form>
                </CardContent>
            </Card>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Riwayat perpindahan</h2>
            <ol class="space-y-3">
                <li
                    v-for="entry in history"
                    :key="entry.movement_no"
                    class="rounded-lg border p-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">{{ entry.type_label }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ entry.movement_no }} ·
                            {{ formatDate(entry.occurred_at) }}
                        </p>
                    </div>
                    <p class="mt-1 text-sm">
                        {{ entry.from }}
                        <span class="text-muted-foreground">→</span>
                        {{ entry.to }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        oleh {{ entry.actor }}
                        <template v-if="entry.notes"> · {{ entry.notes }}</template>
                    </p>
                </li>
            </ol>
        </section>
    </div>
</template>
