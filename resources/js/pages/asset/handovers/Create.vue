<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import HandoverController from '@/actions/Modules/Asset/Http/Web/Controllers/HandoverController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PickableAsset, StockLine } from '@/types/asset';
import type { UlidOption } from '@/types/master';

const props = defineProps<{
    warehouses: UlidOption[];
    selected: string | null;
    technicians: UlidOption[];
    assets: PickableAsset[];
    stocks: StockLine[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Serah-terima', href: HandoverController.index() },
        ],
    },
});

const warehouseOptions = computed(() =>
    props.warehouses.map((warehouse) => ({
        value: warehouse.ulid,
        label: warehouse.label,
    })),
);
const technicianOptions = computed(() =>
    props.technicians.map((technician) => ({
        value: technician.ulid,
        label: technician.label,
    })),
);

// Picking a warehouse reloads the page so the lists show what that warehouse can give out.
function chooseWarehouse(ulid: string): void {
    router.get(
        HandoverController.create.url(),
        ulid ? { warehouse: ulid } : {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Serah ke teknisi" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Serah ke teknisi
            </h1>
            <p class="text-sm text-muted-foreground">
                Pilih gudang, lalu apa yang diserahkan. Aset baru pindah tangan
                setelah teknisi mengonfirmasi di aplikasi.
            </p>
        </div>

        <div class="grid max-w-xl gap-2">
            <Label for="warehouse_picker">Gudang asal</Label>
            <FormSelect
                id="warehouse_picker"
                :model-value="selected ?? ''"
                :options="warehouseOptions"
                placeholder="Pilih gudang…"
                @update:model-value="chooseWarehouse"
            />
            <p
                v-if="warehouseOptions.length === 0"
                class="text-sm text-muted-foreground"
            >
                Belum ada gudang yang dapat Anda akses.
            </p>
        </div>

        <Form
            v-if="selected"
            v-bind="HandoverController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="warehouse" :value="selected" />
            <InputError :message="errors.warehouse" />

            <div class="grid gap-2">
                <Label for="technician">Teknisi penerima</Label>
                <FormSelect
                    id="technician"
                    name="technician"
                    :options="technicianOptions"
                    placeholder="Pilih teknisi…"
                    required
                />
                <InputError :message="errors.technician" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">
                    Unit ({{ assets.length }} tersedia)
                </legend>
                <p
                    v-if="assets.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Tidak ada unit di gudang ini.
                </p>
                <div
                    v-else
                    class="max-h-72 divide-y overflow-y-auto rounded-md border"
                >
                    <label
                        v-for="asset in assets"
                        :key="asset.ulid"
                        class="flex items-center gap-3 px-3 py-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="assets[]"
                            :value="asset.ulid"
                            class="size-4 accent-primary"
                        />
                        <span class="font-mono">{{ asset.label }}</span>
                        <span class="ml-auto text-muted-foreground">{{
                            asset.vendor
                        }}</span>
                    </label>
                </div>
                <InputError :message="errors.assets" />
            </fieldset>

            <fieldset v-if="stocks.length > 0" class="space-y-2">
                <legend class="text-sm font-medium">Barang habis pakai</legend>
                <div
                    v-for="(line, index) in stocks"
                    :key="`${line.model}:${line.vendor}`"
                    class="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                >
                    <input
                        type="hidden"
                        :name="`stock[${index}][model]`"
                        :value="line.model"
                    />
                    <input
                        type="hidden"
                        :name="`stock[${index}][vendor]`"
                        :value="line.vendor"
                    />
                    <span class="flex-1">{{ line.label }}</span>
                    <span class="text-xs text-muted-foreground"
                        >tersedia {{ line.available }}</span
                    >
                    <Input
                        :name="`stock[${index}][quantity]`"
                        type="number"
                        min="0"
                        :max="line.available"
                        class="w-24"
                        placeholder="0"
                    />
                </div>
                <InputError :message="errors.stock" />
            </fieldset>

            <div class="grid gap-2">
                <Label for="note">Catatan (opsional)</Label>
                <Input id="note" name="note" placeholder="Untuk tugas besok…" />
                <InputError :message="errors.note" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Kirim serah-terima</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="HandoverController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
