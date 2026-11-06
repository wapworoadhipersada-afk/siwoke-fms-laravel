<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import IntegrationClientController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationClientController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UlidOption } from '@/types/master';

const props = defineProps<{ vendors: UlidOption[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Klien integrasi', href: IntegrationClientController.index() },
        ],
    },
});

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({ value: vendor.ulid, label: vendor.label })),
);
</script>

<template>
    <Head title="Tambah klien integrasi" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold tracking-tight">Tambah klien integrasi</h1>

        <Form
            v-bind="IntegrationClientController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="vendor">Vendor</Label>
                <FormSelect id="vendor" name="vendor" :options="vendorOptions" placeholder="Pilih vendor…" required />
                <InputError :message="errors.vendor" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama klien</Label>
                <Input id="name" name="name" placeholder="Sistem tiket BRI (produksi)" required />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="allowed_ips">Alamat IP yang diizinkan (opsional, pisahkan dengan koma)</Label>
                <Input id="allowed_ips" name="allowed_ips" placeholder="203.0.113.10, 203.0.113.0/24 (kosong = semua alamat)" />
                <InputError :message="errors.allowed_ips" />
            </div>

            <div class="grid gap-2">
                <Label for="webhook_url">Alamat callback status (opsional)</Label>
                <Input id="webhook_url" name="webhook_url" type="url" placeholder="Alamat https lengkap" />
                <span class="text-xs text-muted-foreground">
                    Harus https dan bukan alamat jaringan internal. Jika diisi, setiap perubahan status tiket vendor ini dikirim ke sini.
                </span>
                <InputError :message="errors.webhook_url" />
            </div>

            <Button type="submit" :disabled="processing">Buat klien</Button>
        </Form>
    </div>
</template>
