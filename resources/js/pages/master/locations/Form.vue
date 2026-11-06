<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LocationController from '@/actions/Modules/Master/Http/Web/Controllers/LocationController';
import InputError from '@/components/InputError.vue';
import FormCheckbox from '@/components/master/FormCheckbox.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { LocationForm, Option, UlidOption } from '@/types/master';

const props = defineProps<{
    location: LocationForm | null;
    types: Option[];
    parentOptions: Record<string, UlidOption[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lokasi', href: LocationController.index() }],
    },
});

// What a node of each type must sit under; the hierarchy is fixed.
const parentTypeOf: Record<string, string | null> = {
    province: null,
    city: 'province',
    area: 'city',
    service_point: 'area',
};

const type = ref(props.location?.type ?? props.types[0]?.value ?? 'province');
const parentType = computed(() => parentTypeOf[type.value] ?? null);
const parents = computed(() =>
    parentType.value
        ? (props.parentOptions[parentType.value] ?? []).map((option) => ({
              value: option.ulid,
              label: option.label,
          }))
        : [],
);
const parentLabel = computed(
    () => props.types.find((item) => item.value === parentType.value)?.label,
);

const form = computed(() =>
    props.location
        ? LocationController.update.form(props.location.ulid)
        : LocationController.store.form(),
);
const title = computed(() =>
    props.location ? 'Ubah lokasi' : 'Tambah lokasi',
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
                <Label for="type">Tingkat</Label>
                <p v-if="location" class="text-sm">
                    {{ location.type_label }}
                    <span class="text-muted-foreground"
                        >(tidak dapat diubah)</span
                    >
                </p>
                <FormSelect
                    v-else
                    id="type"
                    v-model="type"
                    name="type"
                    :options="types"
                    required
                />
                <InputError :message="errors.type" />
            </div>

            <div v-if="parentType" class="grid gap-2">
                <Label for="parent">{{ parentLabel }} (induk)</Label>
                <FormSelect
                    id="parent"
                    :key="type"
                    name="parent"
                    :options="parents"
                    :default-value="location?.parent ?? ''"
                    placeholder="Pilih…"
                    required
                />
                <p
                    v-if="parents.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada {{ parentLabel }} yang dapat Anda akses. Buat
                    terlebih dahulu.
                </p>
                <InputError :message="errors.parent" />
            </div>

            <div class="grid gap-2">
                <Label for="code">Kode</Label>
                <Input
                    id="code"
                    name="code"
                    :default-value="location?.code"
                    placeholder="3273"
                    required
                />
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="location?.name"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Alamat</Label>
                <Input
                    id="address"
                    name="address"
                    :default-value="location?.address ?? ''"
                />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="latitude">Latitude</Label>
                    <Input
                        id="latitude"
                        name="latitude"
                        inputmode="decimal"
                        :default-value="location?.latitude ?? ''"
                        placeholder="-6.9175"
                    />
                    <InputError :message="errors.latitude" />
                </div>
                <div class="grid gap-2">
                    <Label for="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        name="longitude"
                        inputmode="decimal"
                        :default-value="location?.longitude ?? ''"
                        placeholder="107.6191"
                    />
                    <InputError :message="errors.longitude" />
                </div>
            </div>

            <FormCheckbox
                name="is_active"
                label="Lokasi aktif"
                :default-checked="location?.is_active ?? true"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="LocationController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
