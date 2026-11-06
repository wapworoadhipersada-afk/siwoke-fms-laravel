<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import UserController from '@/actions/Modules/IAM/Http/Web/Controllers/UserController';
import UserPhotoController from '@/actions/Modules/IAM/Http/Web/Controllers/UserPhotoController';
import UserScopeController from '@/actions/Modules/Master/Http/Web/Controllers/UserScopeController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ManagedUser, RoleOption } from '@/types/iam';

const props = defineProps<{
    managed: ManagedUser | null;
    roles: RoleOption[];
    types: { value: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }] },
});

const form = computed(() => (props.managed ? UserController.update.form(props.managed.ulid) : UserController.store.form()));
const title = computed(() => (props.managed ? `Ubah ${props.managed.name}` : 'Tambah pengguna'));
// A new password cuts off the old one at once and ends mobile sessions, so ask first.
const confirmReset = (event: Event): void => {
    if (!window.confirm('Buat password baru? Password lama langsung tidak berlaku dan sesi mobile dicabut.')) {
        event.preventDefault();
    }
};

const typeOptions = computed(() => props.types.map((type) => ({ value: type.value, label: type.label })));
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-foreground">{{ title }}</h1>
            <div v-if="managed" class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="UserScopeController.edit(managed.ulid)">Atur area dan vendor</Link>
                </Button>
                <Form v-bind="UserController.resetPassword.form(managed.ulid)" @submit="confirmReset">
                    <Button type="submit" variant="outline">Atur ulang password</Button>
                </Form>
            </div>
        </div>

        <section v-if="managed" class="flex max-w-3xl items-center gap-4 rounded-xl border bg-card p-5">
            <img
                v-if="managed.photo_full_url"
                :src="managed.photo_full_url"
                :alt="`Foto ${managed.name}`"
                class="size-20 rounded-full border object-cover"
            />
            <div
                v-else
                class="flex size-20 items-center justify-center rounded-full border bg-muted text-xl font-semibold text-muted-foreground"
            >
                {{ managed.name.slice(0, 1).toUpperCase() }}
            </div>
            <div class="flex-1 space-y-2">
                <p class="text-sm font-medium">Foto profil</p>
                <Form
                    v-bind="UserPhotoController.update.form(managed.ulid)"
                    class="flex flex-wrap items-center gap-2"
                    v-slot="{ errors, processing }"
                >
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="text-sm" />
                    <Button type="submit" size="sm" :disabled="processing">Unggah</Button>
                    <InputError :message="errors.photo" />
                </Form>
                <Form
                    v-if="managed.photo_full_url"
                    v-bind="UserPhotoController.destroy.form(managed.ulid)"
                    :options="{ preserveScroll: true }"
                >
                    <Button type="submit" variant="ghost" size="sm">Hapus foto</Button>
                </Form>
            </div>
        </section>

        <Form v-bind="form" class="rounded-xl border bg-card max-w-3xl space-y-5 p-5 md:p-6" v-slot="{ errors, processing }">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Nama</Label>
                    <Input id="name" name="name" :default-value="managed?.name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" :default-value="managed?.email" required />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Telepon</Label>
                    <Input id="phone" name="phone" :default-value="managed?.phone ?? ''" />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="user_code">Kode user (wajib untuk engineer)</Label>
                    <Input id="user_code" name="user_code" :default-value="managed?.user_code ?? ''" placeholder="ENG-0012" />
                    <InputError :message="errors.user_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="employee_no">NIK / nomor pegawai</Label>
                    <Input id="employee_no" name="employee_no" :default-value="managed?.employee_no ?? ''" />
                    <InputError :message="errors.employee_no" />
                </div>
                <div class="grid gap-2">
                    <Label for="user_type">Jenis akun</Label>
                    <FormSelect id="user_type" name="user_type" :options="typeOptions" :default-value="managed?.user_type ?? 'staff'" required />
                    <InputError :message="errors.user_type" />
                </div>
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Peran</legend>
                <label v-for="role in roles" :key="role.name" class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="roles[]"
                        :value="role.name"
                        :checked="managed?.roles.includes(role.name) ?? false"
                        :disabled="managed?.is_self"
                        class="size-4 rounded border-gray-300 accent-primary"
                    />
                    {{ role.label }}
                </label>
                <!-- A disabled box submits nothing, so a person's own roles are sent as they are. -->
                <template v-if="managed?.is_self">
                    <input v-for="name in managed.roles" :key="name" type="hidden" name="roles[]" :value="name" />
                    <p class="text-xs text-muted-foreground">Anda tidak dapat mengubah peran Anda sendiri.</p>
                </template>
                <InputError :message="errors.roles" />
            </fieldset>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input type="hidden" name="is_active" value="0" />
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    :checked="managed?.is_active ?? true"
                    :disabled="managed?.is_self"
                    class="size-4 rounded border-gray-300 accent-primary"
                />
                Akun aktif (boleh masuk)
            </label>
            <input v-if="managed?.is_self" type="hidden" name="is_active" value="1" />
            <InputError :message="errors.is_active" />

            <Button type="submit" :disabled="processing">Simpan</Button>
        </Form>
    </div>
</template>
