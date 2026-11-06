<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserScopeController from '@/actions/Modules/Master/Http/Web/Controllers/UserScopeController';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    sectionTitle: string;
    sectionHref: string;
    backHref: string;
    managed: {
        ulid: string;
        name: string;
        email: string;
        user_code: string | null;
        sees_everything: boolean;
    };
    locations: {
        id: number;
        name: string;
        code: string;
        type: string;
        type_label: string;
        depth: number;
        vendor: string | null;
    }[];
    assigned: number[];
    vendors: { ulid: string; label: string }[];
    assignedVendors: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [] },
});

const chosenAreas = ref<Set<number>>(new Set(props.assigned));
const search = ref('');

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return props.locations;
    }

    return props.locations.filter(
        (location) =>
            location.name.toLowerCase().includes(term) ||
            location.code.toLowerCase().includes(term),
    );
});

const toggle = (id: number, on: boolean): void => {
    const next = new Set(chosenAreas.value);

    if (on) {
        next.add(id);
    } else {
        next.delete(id);
    }

    chosenAreas.value = next;
};

const areaCount = computed(
    () => props.locations.filter((l) => chosenAreas.value.has(l.id)).length,
);
</script>

<template>
    <Head title="Cakupan pengguna" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                Cakupan {{ managed.name }}
                <span
                    v-if="managed.user_code"
                    class="font-mono text-base text-muted-foreground"
                >
                    ({{ managed.user_code }})
                </span>
            </h1>
            <p class="text-sm text-muted-foreground">
                Satu orang bisa memegang beberapa area dan beberapa vendor.
                Memilih provinsi atau service point otomatis mencakup semua
                area di bawahnya. Tanpa area, orang ini tidak melihat data apa
                pun, kecuali perannya melihat semua area.
            </p>
            <p
                v-if="managed.sees_everything"
                class="mt-2 text-sm text-amber-700 dark:text-amber-400"
            >
                Peran orang ini melihat semua area, jadi pilihan area tidak
                berpengaruh.
            </p>
        </div>

        <Form
            v-bind="UserScopeController.update.form(managed.ulid)"
            class="grid max-w-5xl gap-6 lg:grid-cols-2"
            v-slot="{ errors, processing }"
        >
            <section class="rounded-xl border bg-card p-5">
                <h2 class="font-medium">Vendor</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Kosong berarti tidak dibatasi vendor. Bila diisi, orang ini
                    hanya melihat vendor yang dipilih.
                </p>
                <ul class="space-y-2">
                    <li v-for="vendor in vendors" :key="vendor.ulid">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="vendor_ids[]"
                                :value="vendor.ulid"
                                :checked="assignedVendors.includes(vendor.ulid)"
                                class="size-4 rounded border-input accent-primary"
                            />
                            {{ vendor.label }}
                        </label>
                    </li>
                    <li
                        v-if="vendors.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Belum ada vendor.
                    </li>
                </ul>
                <p v-if="errors.vendor_ids" class="mt-2 text-sm text-destructive">
                    {{ errors.vendor_ids }}
                </p>
            </section>

            <section class="rounded-xl border bg-card p-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-medium">Area kerja</h2>
                        <p class="text-xs text-muted-foreground">
                            {{ areaCount }} dipilih
                        </p>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Cari lokasi…"
                        class="h-9 w-44 rounded-md border bg-transparent px-3 text-sm"
                    />
                </div>

                <ul
                    class="max-h-[28rem] divide-y overflow-y-auto rounded-lg border"
                >
                    <li
                        v-for="location in visible"
                        :key="location.id"
                        class="px-3 py-2"
                    >
                        <label
                            class="flex items-center gap-3 text-sm"
                            :style="{
                                paddingLeft: `${(location.depth - 1) * 20}px`,
                            }"
                        >
                            <input
                                type="checkbox"
                                name="location_ids[]"
                                :value="location.id"
                                :checked="chosenAreas.has(location.id)"
                                class="size-4 rounded border-input accent-primary"
                                @change="
                                    toggle(
                                        location.id,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            <span :class="location.depth === 1 ? 'font-medium' : ''">{{
                                location.name
                            }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ location.type_label
                                }}<template v-if="location.vendor">
                                    · {{ location.vendor }}</template
                                >
                            </span>
                        </label>
                    </li>
                    <li
                        v-if="visible.length === 0"
                        class="px-3 py-6 text-sm text-muted-foreground"
                    >
                        Tidak ada lokasi yang cocok.
                    </li>
                </ul>
            </section>

            <div class="flex gap-2 lg:col-span-2">
                <Button type="submit" :disabled="processing">Simpan cakupan</Button>
                <Button variant="outline" as-child>
                    <Link :href="backHref">Kembali</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
