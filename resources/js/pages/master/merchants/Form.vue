<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { VendorOption } from '@/types/master';

type MerchantFields = {
    ulid: string;
    vendor: string;
    merchant_code: string;
    name: string;
    owner_name: string | null;
    phone: string | null;
    category: string | null;
};

const props = defineProps<{
    merchant: MerchantFields | null;
    vendors: VendorOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Merchant', href: MerchantController.index() },
        ],
    },
});

const form = computed(() =>
    props.merchant
        ? MerchantController.update.form(props.merchant.ulid)
        : MerchantController.store.form(),
);
const title = computed(() =>
    props.merchant ? 'Ubah merchant' : 'Tambah merchant',
);
const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
const cancelUrl = computed(() =>
    props.merchant
        ? MerchantController.show(props.merchant.ulid)
        : MerchantController.index(),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ title }}</h1>

        <Form
            v-bind="form"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="vendor">Vendor</Label>
                <p v-if="merchant" class="text-sm">
                    {{ merchant.vendor }}
                    <span class="text-muted-foreground"
                        >(tidak dapat diubah)</span
                    >
                </p>
                <FormSelect
                    v-else
                    id="vendor"
                    name="vendor"
                    :options="vendorOptions"
                    placeholder="Pilih vendor…"
                    required
                />
                <InputError :message="errors.vendor" />
            </div>

            <div class="grid gap-2">
                <Label for="merchant_code">MID</Label>
                <Input
                    id="merchant_code"
                    name="merchant_code"
                    :default-value="merchant?.merchant_code"
                    required
                />
                <InputError :message="errors.merchant_code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama merchant</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="merchant?.name"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="owner_name">Nama pemilik</Label>
                <Input
                    id="owner_name"
                    name="owner_name"
                    :default-value="merchant?.owner_name ?? ''"
                />
                <InputError :message="errors.owner_name" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Telepon</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="merchant?.phone ?? ''"
                    />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="category">Kategori</Label>
                    <Input
                        id="category"
                        name="category"
                        :default-value="merchant?.category ?? ''"
                    />
                    <InputError :message="errors.category" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="cancelUrl">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
