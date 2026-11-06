<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LocationController from '@/actions/Modules/Master/Http/Web/Controllers/LocationController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { LocationRow, Option, Paginator } from '@/types/master';


const props = defineProps<{
    locations: Paginator<LocationRow>;
    filters: { q: string; type: string };
    types: Option[];
    fixedType: string | null;
    can: { manage: boolean };
}>();

const slugs: Record<string, string> = { province: 'provinces', service_point: 'service-points', area: 'areas' };
const names: Record<string, string> = { province: 'Provinsi', service_point: 'Service Point', area: 'Area Kelolaan' };
const listUrl = props.fixedType ? `/master/${slugs[props.fixedType]}` : LocationController.index.url();
const heading = props.fixedType ? names[props.fixedType] : 'Lokasi';

const filters = useListFilters(listUrl, {
    q: props.filters.q,
    type: props.filters.type,
});
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ heading }}</h1>
                <p class="text-sm text-muted-foreground">
                    Provinsi → Service Point → Area Kelolaan. Anda hanya
                    melihat wilayah yang ditugaskan.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="LocationController.create({ query: fixedType ? { type: fixedType } : {} })">Tambah {{ heading.toLowerCase() }}</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-sm"
                placeholder="Cari kode atau nama…"
            />
            <FormSelect
                v-if="!fixedType"
                v-model="filters.type"
                :options="types"
                placeholder="Semua tingkat"
                class="max-w-48"
            />
        </div>

        <DataTable
            :columns="['Tingkat', 'Kode', 'Nama', 'Induk', 'Vendor', 'Status', '']"
            :empty="locations.data.length === 0"
        >
            <tr v-for="location in locations.data" :key="location.ulid">
                <td class="px-4 py-3">{{ location.type_label }}</td>
                <td class="px-4 py-3 font-mono">{{ location.code }}</td>
                <td class="px-4 py-3 font-medium">{{ location.name }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ location.parent ?? '—' }}
                </td>
                <td class="px-4 py-3">
                    {{ location.type === 'province' ? '—' : (location.vendor ?? 'Semua vendor') }}
                </td>
                <td class="px-4 py-3">
                    <StatusBadge :active="location.is_active" />
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <template v-if="can.manage">
                        <Button variant="ghost" size="sm" as-child>
                            <Link
                                :href="LocationController.edit(location.ulid)"
                                >Ubah</Link
                            >
                        </Button>
                        <ConfirmDelete
                            :url="LocationController.destroy.url(location.ulid)"
                            :title="`Hapus ${location.type_label} ${location.name}?`"
                            description="Lokasi yang masih memiliki lokasi di bawahnya atau dipakai site merchant tidak dapat dihapus."
                        />
                    </template>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="locations" />
    </div>
</template>
