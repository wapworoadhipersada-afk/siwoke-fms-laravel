<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AssetModelController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetModelController';
import InputError from '@/components/InputError.vue';
import FormCheckbox from '@/components/master/FormCheckbox.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CategoryOption } from '@/types/asset';

type ModelFields = {
    ulid: string;
    category: string;
    brand: string;
    model_name: string;
    is_active: boolean;
};

const props = defineProps<{
    model: ModelFields | null;
    categories: CategoryOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Jenis aset', href: AssetModelController.index() },
        ],
    },
});

const form = computed(() =>
    props.model
        ? AssetModelController.update.form(props.model.ulid)
        : AssetModelController.store.form(),
);
const title = computed(() =>
    props.model ? 'Ubah jenis aset' : 'Tambah jenis aset',
);
const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.value,
        label: `${category.label} — ${category.tracking}`,
    })),
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
                <Label for="category">Kategori</Label>
                <p v-if="model" class="text-sm">
                    {{ model.category }}
                    <span class="text-muted-foreground"
                        >(tidak dapat diubah)</span
                    >
                </p>
                <FormSelect
                    v-else
                    id="category"
                    name="category"
                    :options="categoryOptions"
                    placeholder="Pilih kategori…"
                    required
                />
                <InputError :message="errors.category" />
            </div>

            <div class="grid gap-2">
                <Label for="brand">Merek</Label>
                <Input
                    id="brand"
                    name="brand"
                    :default-value="model?.brand"
                    placeholder="Verifone"
                    required
                />
                <InputError :message="errors.brand" />
            </div>

            <div class="grid gap-2">
                <Label for="model_name">Model</Label>
                <Input
                    id="model_name"
                    name="model_name"
                    :default-value="model?.model_name"
                    placeholder="VX520"
                    required
                />
                <InputError :message="errors.model_name" />
            </div>

            <FormCheckbox
                name="is_active"
                label="Aktif"
                :default-checked="model?.is_active ?? true"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="AssetModelController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
