<script setup lang="ts">
import { ChevronDown, ChevronRight, X } from '@lucide/vue';
import { computed, ref } from 'vue';

export type PickerLocation = {
    id: number;
    parent_id: number | null;
    name: string;
    code: string;
    type: string;
    type_label: string;
    vendor: string | null;
};

const props = defineProps<{
    locations: PickerLocation[];
    /** Vendors the person works for. When set, service points of other vendors are hidden (unless asked for). */
    vendorCodes: string[];
}>();

// The places ticked by hand. Ticking a province or service point covers everything below it.
const model = defineModel<number[]>({ required: true });

const search = ref('');
const onlyVendors = ref(true);
const expanded = ref<Set<number>>(new Set());

const selected = computed(() => new Set(model.value));
const byId = computed(() => new Map(props.locations.map((location) => [location.id, location])));
const childrenOf = computed(() => {
    const map = new Map<number | null, PickerLocation[]>();

    for (const location of props.locations) {
        map.set(location.parent_id, [...(map.get(location.parent_id) ?? []), location]);
    }

    return map;
});

const ancestorsOf = (id: number): number[] => {
    const result: number[] = [];
    let current = byId.value.get(id)?.parent_id ?? null;

    while (current !== null) {
        result.push(current);
        current = byId.value.get(current)?.parent_id ?? null;
    }

    return result;
};

const descendantsOf = (id: number): number[] =>
    (childrenOf.value.get(id) ?? []).flatMap((child) => [child.id, ...descendantsOf(child.id)]);

type State = 'on' | 'inherited' | 'partial' | 'off';

const stateOf = (id: number): State => {
    if (selected.value.has(id)) {
        return 'on';
    }

    if (ancestorsOf(id).some((ancestor) => selected.value.has(ancestor))) {
        return 'inherited';
    }

    return descendantsOf(id).some((descendant) => selected.value.has(descendant)) ? 'partial' : 'off';
};

const toggle = (location: PickerLocation): void => {
    const state = stateOf(location.id);

    if (state === 'inherited') {
        return;
    }

    if (state === 'on') {
        model.value = model.value.filter((id) => id !== location.id);

        return;
    }

    // Ticking a place makes anything ticked below it redundant.
    const below = new Set(descendantsOf(location.id));
    model.value = [...model.value.filter((id) => !below.has(id)), location.id];
};

const term = computed(() => search.value.trim().toLowerCase());
const filtering = computed(() => onlyVendors.value && props.vendorCodes.length > 0);

const vendorAllowed = (location: PickerLocation): boolean => {
    if (!filtering.value) {
        return true;
    }

    // The vendor sits on the service point; a province is never filtered and an area follows its service point.
    const owner = location.type === 'area' ? byId.value.get(location.parent_id ?? -1) : location;

    return location.type === 'province' || !owner?.vendor || props.vendorCodes.includes(owner.vendor);
};

const matches = (location: PickerLocation): boolean =>
    term.value === '' || location.name.toLowerCase().includes(term.value) || location.code.toLowerCase().includes(term.value);

// A place is shown when it, or something below it, matches the search and fits the vendors chosen.
const shown = (location: PickerLocation): boolean =>
    vendorAllowed(location) && (matches(location) || (childrenOf.value.get(location.id) ?? []).some(shown));

const isOpen = (id: number): boolean => term.value !== '' || expanded.value.has(id);

const rows = computed(() => {
    const result: (PickerLocation & { depth: number; hasChildren: boolean })[] = [];

    const walk = (parent: number | null, depth: number): void => {
        for (const location of childrenOf.value.get(parent) ?? []) {
            if (!shown(location)) {
                continue;
            }

            const kids = (childrenOf.value.get(location.id) ?? []).filter(shown);
            result.push({ ...location, depth, hasChildren: kids.length > 0 });

            if (kids.length > 0 && isOpen(location.id)) {
                walk(location.id, depth + 1);
            }
        }
    };

    walk(null, 0);

    return result;
});

const flip = (id: number): void => {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
};

const openAll = (): void => {
    expanded.value = new Set(props.locations.filter((location) => (childrenOf.value.get(location.id) ?? []).length > 0).map((location) => location.id));
};

// Places with something ticked inside start open, so the person sees what is already set.
for (const id of model.value) {
    ancestorsOf(id).forEach((ancestor) => expanded.value.add(ancestor));
}

const areaCount = (id: number): number => descendantsOf(id).filter((child) => byId.value.get(child)?.type === 'area').length;

const coveredAreas = computed(
    () => props.locations.filter((location) => location.type === 'area' && (selected.value.has(location.id) || ancestorsOf(location.id).some((ancestor) => selected.value.has(ancestor)))).length,
);

const chips = computed(() => model.value.map((id) => byId.value.get(id)).filter((location): location is PickerLocation => location !== undefined));
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-5">
        <div class="space-y-3 lg:col-span-3">
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="search" type="search" placeholder="Cari provinsi, service point, atau area…" class="h-10 min-w-0 flex-1 rounded-lg border border-input bg-transparent px-3 text-sm" />
                <button type="button" class="rounded-lg border px-3 py-2 text-xs hover:bg-accent" @click="openAll">Buka semua</button>
                <button type="button" class="rounded-lg border px-3 py-2 text-xs hover:bg-accent" @click="expanded = new Set()">Tutup semua</button>
            </div>

            <label v-if="vendorCodes.length > 0" class="flex items-center gap-2 text-sm">
                <input v-model="onlyVendors" type="checkbox" class="size-4 rounded border-input accent-primary" />
                Hanya tampilkan lokasi untuk vendor yang dipilih ({{ vendorCodes.join(', ') }})
            </label>

            <ul class="max-h-[30rem] divide-y overflow-y-auto rounded-xl border">
                <li v-for="row in rows" :key="row.id" class="flex items-center gap-2 px-3 py-2" :style="{ paddingLeft: `${12 + row.depth * 24}px` }">
                    <button
                        v-if="row.hasChildren"
                        type="button"
                        class="rounded p-0.5 text-muted-foreground hover:bg-accent"
                        :aria-label="isOpen(row.id) ? `Tutup ${row.name}` : `Buka ${row.name}`"
                        :aria-expanded="isOpen(row.id)"
                        @click="flip(row.id)"
                    >
                        <ChevronDown v-if="isOpen(row.id)" class="size-4" />
                        <ChevronRight v-else class="size-4" />
                    </button>
                    <span v-else class="size-5" />

                    <label class="flex min-w-0 flex-1 items-center gap-3 text-sm" :class="stateOf(row.id) === 'inherited' ? 'opacity-60' : 'cursor-pointer'">
                        <input
                            type="checkbox"
                            class="size-4 shrink-0 rounded border-input accent-primary"
                            :checked="stateOf(row.id) === 'on' || stateOf(row.id) === 'inherited'"
                            :indeterminate.prop="stateOf(row.id) === 'partial'"
                            :disabled="stateOf(row.id) === 'inherited'"
                            @change="toggle(row)"
                        />
                        <span class="min-w-0 flex-1 truncate" :class="row.depth === 0 ? 'font-medium' : ''">
                            {{ row.name }}
                            <span class="ms-1 text-xs text-muted-foreground">{{ row.type_label }}</span>
                        </span>
                        <span v-if="row.vendor" class="shrink-0 rounded-full bg-accent px-2 py-0.5 text-xs text-primary">{{ row.vendor }}</span>
                        <span v-if="row.hasChildren" class="shrink-0 text-xs text-muted-foreground">{{ areaCount(row.id) }} area</span>
                    </label>
                </li>
                <li v-if="rows.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">
                    Tidak ada lokasi yang cocok.<template v-if="filtering"> Matikan filter vendor di atas untuk melihat semuanya.</template>
                </li>
            </ul>
        </div>

        <aside class="space-y-3 lg:col-span-2">
            <div class="rounded-xl border p-4">
                <p class="text-sm font-medium">Area yang ditangani</p>
                <p class="mt-1 text-3xl font-semibold">{{ coveredAreas }}</p>
                <p class="text-xs text-muted-foreground">
                    Memilih provinsi atau service point otomatis mencakup semua area di bawahnya, termasuk yang ditambahkan nanti.
                </p>
            </div>

            <div class="rounded-xl border p-4">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-medium">Dipilih ({{ chips.length }})</p>
                    <button v-if="chips.length > 0" type="button" class="text-xs text-muted-foreground underline" @click="model = []">Kosongkan</button>
                </div>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="chip in chips" :key="chip.id">
                        <button type="button" class="inline-flex items-center gap-1 rounded-full border bg-accent/50 px-3 py-1 text-xs hover:bg-accent" :aria-label="`Hapus ${chip.name}`" @click="toggle(chip)">
                            {{ chip.name }}
                            <span class="text-muted-foreground">· {{ chip.type_label }}</span>
                            <X class="size-3" />
                        </button>
                    </li>
                    <li v-if="chips.length === 0" class="text-sm text-muted-foreground">Belum ada. Centang lokasi di sebelah kiri.</li>
                </ul>
            </div>
        </aside>

        <input v-for="id in model" :key="id" type="hidden" name="location_ids[]" :value="id" />
    </div>
</template>
