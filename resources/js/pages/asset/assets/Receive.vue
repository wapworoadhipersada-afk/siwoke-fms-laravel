<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AssetController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ModelOption } from '@/types/asset';
import type { Option, UlidOption, VendorOption } from '@/types/master';

const props = defineProps<{
    models: ModelOption[];
    vendors: VendorOption[];
    warehouses: UlidOption[];
    conditions: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Aset', href: AssetController.index() }],
    },
});

const selectedModel = ref('');
const tracking = computed(
    () => props.models.find((model) => model.ulid === selectedModel.value)?.tracking,
);

const modelOptions = computed(() =>
    props.models.map((model) => ({ value: model.ulid, label: model.label })),
);
const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
const warehouseOptions = computed(() =>
    props.warehouses.map((warehouse) => ({
        value: warehouse.ulid,
        label: warehouse.label,
    })),
);
</script>

<template>
    <Head title="Terima aset" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Terima aset</h1>
            <p class="text-sm text-muted-foreground">
                Mencatat barang yang masuk ke gudang. Setiap aset dimiliki satu
                vendor.
            </p>
        </div>

        <Form
            v-bind="AssetController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="asset_model">Jenis aset</Label>
                <FormSelect
                    id="asset_model"
                    v-model="selectedModel"
                    name="asset_model"
                    :options="modelOptions"
                    placeholder="Pilih jenis…"
                    required
                />
                <InputError :message="errors.asset_model" />
            </div>

            <div class="grid gap-2">
                <Label for="vendor">Vendor pemilik</Label>
                <FormSelect
                    id="vendor"
                    name="vendor"
                    :options="vendorOptions"
                    placeholder="Pilih vendor…"
                    required
                />
                <InputError :message="errors.vendor" />
            </div>

            <div class="grid gap-2">
                <Label for="warehouse">Gudang tujuan</Label>
                <FormSelect
                    id="warehouse"
                    name="warehouse"
                    :options="warehouseOptions"
                    placeholder="Pilih gudang…"
                    required
                />
                <p
                    v-if="warehouseOptions.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada gudang yang dapat Anda akses.
                </p>
                <InputError :message="errors.warehouse" />
            </div>

            <div v-if="tracking === 'serialized'" class="grid gap-2">
                <Label for="serials">Nomor seri</Label>
                <textarea
                    id="serials"
                    name="serials"
                    rows="8"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="Satu nomor seri per baris"
                />
                <p class="text-xs text-muted-foreground">
                    Satu per baris atau dipisah koma. Maksimal 500 unit
                    sekaligus. Seluruhnya ditolak bila ada yang sudah
                    terdaftar.
                </p>
                <InputError :message="errors.serials" />
            </div>

            <div v-else-if="tracking === 'quantity'" class="grid gap-2">
                <Label for="quantity">Jumlah</Label>
                <Input
                    id="quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    required
                />
                <InputError :message="errors.quantity" />
            </div>

            <div v-if="tracking === 'serialized'" class="grid gap-2">
                <Label for="condition">Kondisi</Label>
                <FormSelect
                    id="condition"
                    name="condition"
                    :options="conditions"
                    default-value="good"
                />
                <InputError :message="errors.condition" />
            </div>

            <div class="grid gap-2">
                <Label for="notes">Catatan (opsional)</Label>
                <Input
                    id="notes"
                    name="notes"
                    placeholder="Nomor surat jalan, kiriman batch 1…"
                />
                <InputError :message="errors.notes" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing || !tracking"
                    >Terima</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="AssetController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
