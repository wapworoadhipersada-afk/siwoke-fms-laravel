<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import MerchantSiteController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantSiteController';
import InputError from '@/components/InputError.vue';
import FormCheckbox from '@/components/master/FormCheckbox.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SiteForm, UlidOption } from '@/types/master';

const props = defineProps<{
    merchant: { ulid: string; name: string; merchant_code: string };
    site: SiteForm | null;
    servicePoints: UlidOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Merchant', href: MerchantController.index() },
        ],
    },
});

const form = computed(() =>
    props.site
        ? MerchantSiteController.update.form(props.site.ulid)
        : MerchantSiteController.store.form(props.merchant.ulid),
);
const title = computed(() => (props.site ? 'Ubah site' : 'Tambah site'));
const options = computed(() =>
    props.servicePoints.map((option) => ({
        value: option.ulid,
        label: option.label,
    })),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ merchant.name }} · {{ merchant.merchant_code }}
            </p>
        </div>

        <Form
            v-bind="form"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="location">Area Kelolaan</Label>
                <FormSelect
                    id="location"
                    name="location"
                    :options="options"
                    :default-value="site?.location ?? ''"
                    placeholder="Pilih Area Kelolaan…"
                    required
                />
                <p
                    v-if="options.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada Area Kelolaan untuk vendor ini yang dapat Anda akses.
                </p>
                <InputError :message="errors.location" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Nama outlet (opsional)</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="site?.name ?? ''"
                    placeholder="Cabang Braga"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Alamat</Label>
                <Input
                    id="address"
                    name="address"
                    :default-value="site?.address"
                    required
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
                        :default-value="site?.latitude ?? ''"
                    />
                    <InputError :message="errors.latitude" />
                </div>
                <div class="grid gap-2">
                    <Label for="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        name="longitude"
                        inputmode="decimal"
                        :default-value="site?.longitude ?? ''"
                    />
                    <InputError :message="errors.longitude" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="pic_name">Nama PIC</Label>
                    <Input
                        id="pic_name"
                        name="pic_name"
                        :default-value="site?.pic_name ?? ''"
                    />
                    <InputError :message="errors.pic_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="pic_phone">Telepon PIC</Label>
                    <Input
                        id="pic_phone"
                        name="pic_phone"
                        :default-value="site?.pic_phone ?? ''"
                    />
                    <InputError :message="errors.pic_phone" />
                </div>
            </div>

            <FormCheckbox
                name="is_active"
                label="Site aktif"
                :default-checked="site?.is_active ?? true"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="MerchantController.show(merchant.ulid)"
                        >Batal</Link
                    >
                </Button>
            </div>
        </Form>
    </div>
</template>
