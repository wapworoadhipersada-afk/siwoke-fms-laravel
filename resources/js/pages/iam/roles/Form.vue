<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RoleController from '@/actions/Modules/IAM/Http/Web/Controllers/RoleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PermissionGroup, RoleDetail } from '@/types/iam';

const props = defineProps<{
    role: RoleDetail | null;
    groups: PermissionGroup[];
    canGrantPrivileged: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Peran', href: RoleController.index() }] },
});

const form = computed(() => (props.role ? RoleController.update.form(String(props.role.id)) : RoleController.store.form()));
const title = computed(() => (props.role ? `Ubah peran ${props.role.label}` : 'Tambah peran'));
const locked = computed(() => props.role?.locked ?? false);

// What is ticked is tracked here so "pilih semua" per group can flip many boxes at once.
const chosen = ref<Set<string>>(new Set(props.role?.permissions ?? []));

const canToggle = (permission: { privileged: boolean }): boolean =>
    !locked.value && (!permission.privileged || props.canGrantPrivileged);

const toggle = (name: string, on: boolean): void => {
    const next = new Set(chosen.value);

    if (on) {
        next.add(name);
    } else {
        next.delete(name);
    }

    chosen.value = next;
};

const allOn = (group: PermissionGroup): boolean =>
    group.permissions.filter(canToggle).every((permission) => chosen.value.has(permission.name));

const toggleGroup = (group: PermissionGroup): void => {
    const turnOn = !allOn(group);

    for (const permission of group.permissions.filter(canToggle)) {
        toggle(permission.name, turnOn);
    }
};
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ title }}</h1>
            <p v-if="locked" class="mt-1 text-sm text-muted-foreground">
                Super Admin selalu boleh melakukan semuanya dan tidak dapat diubah.
            </p>
        </div>

        <Form v-bind="form" class="space-y-6" v-slot="{ errors, processing }">
            <div class="panel max-w-3xl space-y-5 p-5 md:p-6">
                <div class="grid gap-2">
                    <Label for="label">Nama peran</Label>
                    <Input id="label" name="label" :default-value="role?.label" placeholder="Supervisor Wilayah" :disabled="locked" required />
                    <InputError :message="errors.label" />
                </div>

                <div v-if="!role" class="grid gap-2">
                    <Label for="name">Kode (opsional)</Label>
                    <Input id="name" name="name" placeholder="supervisor-wilayah" />
                    <p class="text-xs text-muted-foreground">Huruf, angka, minus. Jika kosong dibuat dari nama peran. Tidak dapat diubah nanti.</p>
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="description">Deskripsi</Label>
                    <Input id="description" name="description" :default-value="role?.description ?? ''" :disabled="locked" />
                    <InputError :message="errors.description" />
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <section v-for="group in groups" :key="group.key" class="panel p-5">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="font-medium text-gray-800 dark:text-white/90">{{ group.label }}</h2>
                        <button
                            v-if="!locked"
                            type="button"
                            class="text-xs text-brand-500 hover:text-brand-600"
                            @click="toggleGroup(group)"
                        >
                            {{ allOn(group) ? 'Kosongkan' : 'Pilih semua' }}
                        </button>
                    </div>

                    <ul class="space-y-2">
                        <li v-for="permission in group.permissions" :key="permission.name">
                            <!-- A disabled box submits nothing; keep what the person may not change so it is not read as removed. -->
                            <input
                                v-if="!canToggle(permission) && !locked && chosen.has(permission.name)"
                                type="hidden"
                                name="permissions[]"
                                :value="permission.name"
                            />
                            <label class="flex items-start gap-2 text-sm" :class="canToggle(permission) ? 'cursor-pointer' : 'opacity-60'">
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    :value="permission.name"
                                    :checked="locked || chosen.has(permission.name)"
                                    :disabled="!canToggle(permission)"
                                    class="mt-0.5 size-4 rounded border-gray-300 accent-brand-500"
                                    @change="toggle(permission.name, ($event.target as HTMLInputElement).checked)"
                                />
                                <span>
                                    <span class="text-gray-700 dark:text-gray-300">{{ permission.label }}</span>
                                    <span
                                        v-if="permission.privileged"
                                        class="ms-1 rounded-full bg-warning-50 px-1.5 py-0.5 text-[10px] font-medium text-warning-700 dark:bg-warning-500/15 dark:text-orange-400"
                                    >
                                        Super Admin saja
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </section>
            </div>
            <InputError :message="errors.permissions" />
            <InputError :message="errors.role" />

            <div v-if="!locked">
                <Button type="submit" :disabled="processing">Simpan peran</Button>
            </div>
        </Form>
    </div>
</template>
