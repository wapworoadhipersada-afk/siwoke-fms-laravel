<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserScopeController from '@/actions/Modules/Master/Http/Web/Controllers/UserScopeController';
import LocationTreePicker from '@/components/master/LocationTreePicker.vue';
import type { PickerLocation } from '@/components/master/LocationTreePicker.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    backHref: string;
    managed: {
        ulid: string;
        name: string;
        email: string;
        user_code: string | null;
        photo_url: string | null;
        roles: string[];
        sees_everything: boolean;
    };
    locations: PickerLocation[];
    assigned: number[];
    vendors: { ulid: string; code: string; label: string }[];
    assignedVendors: string[];
}>();

const chosenVendors = ref<string[]>([...props.assignedVendors]);
const chosenAreas = ref<number[]>([...props.assigned]);

const vendorCodes = computed(() => props.vendors.filter((vendor) => chosenVendors.value.includes(vendor.ulid)).map((vendor) => vendor.code));

const toggleVendor = (ulid: string): void => {
    chosenVendors.value = chosenVendors.value.includes(ulid) ? chosenVendors.value.filter((item) => item !== ulid) : [...chosenVendors.value, ulid];
};
</script>

<template>
    <Head :title="`Vendor dan area ${managed.name}`" />

    <Form v-bind="UserScopeController.update.form(managed.ulid)" class="flex flex-1 flex-col gap-6 p-4 pb-28 md:p-6" v-slot="{ errors, processing }">
        <header class="flex flex-wrap items-center gap-4">
            <img v-if="managed.photo_url" :src="managed.photo_url" :alt="managed.name" class="size-14 rounded-full border object-cover" />
            <span v-else class="flex size-14 items-center justify-center rounded-full border bg-muted text-lg font-semibold text-muted-foreground">{{ managed.name.slice(0, 1).toUpperCase() }}</span>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-xl font-semibold">{{ managed.name }}</h1>
                <p class="text-sm text-muted-foreground">
                    <span v-if="managed.user_code" class="font-mono">{{ managed.user_code }} · </span>{{ managed.roles.join(', ') }}
                </p>
            </div>
        </header>

        <p v-if="managed.sees_everything" class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
            Peran orang ini melihat semua area, jadi pilihan area di bawah tidak berpengaruh.
        </p>

        <section class="space-y-3 rounded-xl border bg-card p-5">
            <div>
                <h2 class="font-medium">1. Vendor</h2>
                <p class="text-sm text-muted-foreground">Pilih vendor yang ditangani. Tidak memilih apa pun berarti tidak dibatasi vendor.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="vendor in vendors"
                    :key="vendor.ulid"
                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                >
                    <input type="checkbox" name="vendor_ids[]" :value="vendor.ulid" :checked="chosenVendors.includes(vendor.ulid)" class="size-4 accent-primary" @change="toggleVendor(vendor.ulid)" />
                    {{ vendor.label }}
                </label>
                <p v-if="vendors.length === 0" class="text-sm text-muted-foreground">Belum ada vendor aktif.</p>
            </div>
            <p v-if="errors.vendor_ids" class="text-sm text-destructive">{{ errors.vendor_ids }}</p>
        </section>

        <section class="space-y-3 rounded-xl border bg-card p-5">
            <div>
                <h2 class="font-medium">2. Area kerja</h2>
                <p class="text-sm text-muted-foreground">Tanpa area, orang ini tidak melihat data apa pun (kecuali perannya melihat semua area).</p>
            </div>
            <LocationTreePicker v-model="chosenAreas" :locations="locations" :vendor-codes="vendorCodes" />
            <p v-if="errors.location_ids" class="text-sm text-destructive">{{ errors.location_ids }}</p>
        </section>

        <div class="fixed inset-x-0 bottom-0 z-10 border-t bg-background/95 px-4 py-3 backdrop-blur md:left-[var(--sidebar-width,0px)]">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-end gap-2">
                <Button variant="ghost" as-child>
                    <Link :href="backHref">Kembali ke data</Link>
                </Button>
                <Button type="submit" variant="outline" :disabled="processing">Simpan</Button>
                <Button type="submit" name="done" value="1" :disabled="processing">Simpan dan selesai</Button>
            </div>
        </div>
    </Form>
</template>
