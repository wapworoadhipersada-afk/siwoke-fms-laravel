<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EngineerController from '@/actions/Modules/IAM/Http/Web/Controllers/EngineerController';
import UserPhotoController from '@/actions/Modules/IAM/Http/Web/Controllers/UserPhotoController';
import UserScopeController from '@/actions/Modules/Master/Http/Web/Controllers/UserScopeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Managed = {
    ulid: string;
    name: string;
    email: string;
    phone: string | null;
    employee_no: string | null;
    user_code: string | null;
    photo_full_url: string | null;
    role: string | null;
    is_active: boolean;
    is_self: boolean;
};

const props = defineProps<{
    managed: Managed | null;
    roles: { name: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Engineer / Teknisi', href: '/iam/engineers' }] },
});

const form = computed(() => (props.managed ? EngineerController.update.form(props.managed.ulid) : EngineerController.store.form()));
const title = computed(() => (props.managed ? `Ubah ${props.managed.name}` : 'Tambah engineer / teknisi'));
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-foreground">{{ title }}</h1>
                <p v-if="!managed" class="text-sm text-muted-foreground">
                    Setelah disimpan, Anda akan diarahkan untuk mengatur vendor dan area kerjanya.
                </p>
            </div>
            <Button v-if="managed" variant="outline" as-child>
                <Link :href="UserScopeController.edit(managed.ulid)">Atur area dan vendor</Link>
            </Button>
        </div>

        <section v-if="managed" class="flex max-w-3xl items-center gap-4 rounded-xl border bg-card p-5">
            <img v-if="managed.photo_full_url" :src="managed.photo_full_url" :alt="`Foto ${managed.name}`" class="size-20 rounded-full border object-cover" />
            <div v-else class="flex size-20 items-center justify-center rounded-full border bg-muted text-xl font-semibold text-muted-foreground">
                {{ managed.name.slice(0, 1).toUpperCase() }}
            </div>
            <div class="flex-1 space-y-2">
                <p class="text-sm font-medium">Foto profil</p>
                <Form v-bind="UserPhotoController.update.form(managed.ulid)" :options="{ preserveScroll: true }" class="flex flex-wrap items-center gap-2" v-slot="{ errors, processing }">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="text-sm" />
                    <Button type="submit" size="sm" :disabled="processing">Unggah</Button>
                    <InputError :message="errors.photo" />
                </Form>
                <Form v-if="managed.photo_full_url" v-bind="UserPhotoController.destroy.form(managed.ulid)" :options="{ preserveScroll: true }">
                    <Button type="submit" variant="ghost" size="sm">Hapus foto</Button>
                </Form>
            </div>
        </section>

        <Form v-bind="form" class="max-w-3xl space-y-5 rounded-xl border bg-card p-5 md:p-6" v-slot="{ errors, processing }">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="user_code">Kode user</Label>
                    <Input id="user_code" name="user_code" :default-value="managed?.user_code ?? ''" placeholder="ENG-0012" required />
                    <InputError :message="errors.user_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">Nama lengkap</Label>
                    <Input id="name" name="name" :default-value="managed?.name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" :default-value="managed?.email" required />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Nomor HP</Label>
                    <Input id="phone" name="phone" :default-value="managed?.phone ?? ''" />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="employee_no">NIK / nomor pegawai</Label>
                    <Input id="employee_no" name="employee_no" :default-value="managed?.employee_no ?? ''" />
                    <InputError :message="errors.employee_no" />
                </div>
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Posisi</legend>
                <label v-for="role in roles" :key="role.name" class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="role"
                        :value="role.name"
                        :checked="(managed?.role ?? roles[0]?.name) === role.name"
                        :disabled="managed?.is_self"
                        class="size-4 accent-primary"
                    />
                    {{ role.label }}
                </label>
                <input v-if="managed?.is_self" type="hidden" name="role" :value="managed.role ?? ''" />
                <InputError :message="errors.role" />
            </fieldset>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" :checked="managed?.is_active ?? true" :disabled="managed?.is_self" class="size-4 rounded border-gray-300 accent-primary" />
                Akun aktif (boleh masuk aplikasi)
            </label>
            <input v-if="managed?.is_self" type="hidden" name="is_active" value="1" />

            <Button type="submit" :disabled="processing">{{ managed ? 'Simpan' : 'Simpan dan atur area' }}</Button>
        </Form>
    </div>
</template>
