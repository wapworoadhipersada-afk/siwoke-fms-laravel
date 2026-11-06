<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import WarehouseController from '@/actions/Modules/Asset/Http/Web/Controllers/WarehouseController';
import InputError from '@/components/InputError.vue';
import FormCheckbox from '@/components/master/FormCheckbox.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { WarehouseForm } from '@/types/asset';
import type { UlidOption } from '@/types/master';

const props = defineProps<{
    warehouse: WarehouseForm | null;
    locations: UlidOption[];
    people: UlidOption[];
}>();

const form = computed(() =>
    props.warehouse
        ? WarehouseController.update.form(props.warehouse.ulid)
        : WarehouseController.store.form(),
);
const title = computed(() =>
    props.warehouse ? 'Ubah gudang' : 'Tambah gudang',
);
const locationOptions = computed(() =>
    props.locations.map((option) => ({
        value: option.ulid,
        label: option.label,
    })),
);
const personOptions = computed(() =>
    props.people.map((option) => ({ value: option.ulid, label: option.label })),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>

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
                    :default-value="warehouse?.code"
                    placeholder="GDG-BDG"
                    required
                />
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="warehouse?.name"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="location">Lokasi</Label>
                <FormSelect
                    id="location"
                    name="location"
                    :options="locationOptions"
                    :default-value="warehouse?.location ?? ''"
                    placeholder="Pilih lokasi…"
                    required
                />
                <InputError :message="errors.location" />
            </div>

            <div class="grid gap-2">
                <Label for="pic">Penanggung jawab (opsional)</Label>
                <FormSelect
                    id="pic"
                    name="pic"
                    :options="personOptions"
                    :default-value="warehouse?.pic ?? ''"
                    placeholder="Tidak ada"
                />
                <InputError :message="errors.pic" />
            </div>

            <FormCheckbox
                name="is_active"
                label="Gudang aktif"
                :default-checked="warehouse?.is_active ?? true"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="WarehouseController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
