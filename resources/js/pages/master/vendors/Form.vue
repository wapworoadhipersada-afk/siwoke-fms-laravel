<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import VendorController from '@/actions/Modules/Master/Http/Web/Controllers/VendorController';
import FormCheckbox from '@/components/master/FormCheckbox.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Vendor } from '@/types/master';

const props = defineProps<{ vendor: Vendor | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vendor', href: VendorController.index() }],
    },
});

const form = computed(() =>
    props.vendor
        ? VendorController.update.form(props.vendor.ulid)
        : VendorController.store.form(),
);
const title = computed(() => (props.vendor ? 'Ubah vendor' : 'Tambah vendor'));
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
                <Label for="code">Kode</Label>
                <Input
                    id="code"
                    name="code"
                    :default-value="vendor?.code"
                    placeholder="BRI"
                    required
                />
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="vendor?.name"
                    placeholder="Bank Rakyat Indonesia"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <FormCheckbox
                name="is_active"
                label="Vendor aktif"
                :default-checked="vendor?.is_active ?? true"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="VendorController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
