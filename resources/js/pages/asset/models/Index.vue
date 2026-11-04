<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AssetModelController from '@/actions/Modules/Asset/Http/Web/Controllers/AssetModelController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import DataTable from '@/components/master/DataTable.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import Pagination from '@/components/master/Pagination.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import type { CategoryOption, ModelRow } from '@/types/asset';
import type { Paginator } from '@/types/master';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Jenis aset', href: AssetModelController.index() },
        ],
    },
});

const props = defineProps<{
    models: Paginator<ModelRow>;
    filters: { q: string; category: string };
    categories: CategoryOption[];
    can: { manage: boolean };
}>();

const filters = useListFilters(AssetModelController.index.url(), {
    q: props.filters.q,
    category: props.filters.category,
});

const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.value,
        label: category.label,
    })),
);
</script>

<template>
    <Head title="Jenis aset" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Jenis aset
                </h1>
                <p class="text-sm text-muted-foreground">
                    Katalog produk, misalnya "Verifone VX520". Unit-nya dicatat
                    sebagai aset.
                </p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link :href="AssetModelController.create()"
                    >Tambah jenis aset</Link
                >
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                class="max-w-sm"
                placeholder="Cari merek atau model…"
            />
            <FormSelect
                v-model="filters.category"
                :options="categoryOptions"
                placeholder="Semua kategori"
                class="max-w-56"
            />
        </div>

        <DataTable
            :columns="['Nama', 'Kategori', 'Pelacakan', 'Unit', 'Status', '']"
            :empty="models.data.length === 0"
        >
            <tr v-for="model in models.data" :key="model.ulid">
                <td class="px-4 py-3 font-medium">{{ model.name }}</td>
                <td class="px-4 py-3">{{ model.category }}</td>
                <td class="px-4 py-3 text-muted-foreground">
                    {{ model.tracking }}
                </td>
                <td class="px-4 py-3">{{ model.assets_count }}</td>
                <td class="px-4 py-3">
                    <StatusBadge :active="model.is_active" />
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <template v-if="can.manage">
                        <Button variant="ghost" size="sm" as-child>
                            <Link
                                :href="AssetModelController.edit(model.ulid)"
                                >Ubah</Link
                            >
                        </Button>
                        <ConfirmDelete
                            :url="AssetModelController.destroy.url(model.ulid)"
                            :title="`Hapus ${model.name}?`"
                            description="Jenis aset yang sudah memiliki unit atau riwayat tidak dapat dihapus. Nonaktifkan sebagai gantinya."
                        />
                    </template>
                </td>
            </tr>
        </DataTable>

        <Pagination :paginator="models" />
    </div>
</template>
