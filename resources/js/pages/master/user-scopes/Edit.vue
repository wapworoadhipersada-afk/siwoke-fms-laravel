<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/Modules/IAM/Http/Web/Controllers/UserController';
import UserScopeController from '@/actions/Modules/Master/Http/Web/Controllers/UserScopeController';
import { Button } from '@/components/ui/button';

defineProps<{
    managed: { ulid: string; name: string; email: string; sees_everything: boolean };
    locations: { id: number; name: string; type_label: string; depth: number }[];
    assigned: number[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }] },
});
</script>

<template>
    <Head title="Area kerja" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Area kerja {{ managed.name }}</h1>
            <p class="text-sm text-muted-foreground">
                Memilih sebuah lokasi otomatis mencakup semua lokasi di bawahnya. Tanpa area, orang ini tidak melihat
                data apa pun, kecuali perannya melihat semua area.
            </p>
            <p v-if="managed.sees_everything" class="mt-2 text-sm text-warning-700 dark:text-orange-400">
                Peran orang ini melihat semua area, jadi pilihan di sini tidak berpengaruh.
            </p>
        </div>

        <Form v-bind="UserScopeController.update.form(managed.ulid)" class="space-y-5" v-slot="{ processing }">
            <ul class="panel max-w-3xl divide-y divide-gray-100 dark:divide-gray-800">
                <li v-for="location in locations" :key="location.id" class="px-5 py-3">
                    <label class="flex items-center gap-3 text-sm" :style="{ paddingLeft: `${location.depth * 20}px` }">
                        <input
                            type="checkbox"
                            name="location_ids[]"
                            :value="location.id"
                            :checked="assigned.includes(location.id)"
                            class="size-4 rounded border-gray-300 accent-brand-500"
                        />
                        <span class="text-gray-800 dark:text-white/90">{{ location.name }}</span>
                        <span class="text-xs text-gray-500">{{ location.type_label }}</span>
                    </label>
                </li>
                <li v-if="locations.length === 0" class="px-5 py-6 text-sm text-muted-foreground">Belum ada lokasi.</li>
            </ul>

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">Simpan area</Button>
                <Button variant="outline" as-child>
                    <Link :href="UserController.edit(managed.ulid)">Kembali</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
