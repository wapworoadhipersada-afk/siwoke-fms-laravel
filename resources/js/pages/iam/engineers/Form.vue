<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
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
    username: string | null;
    email: string;
    phone: string | null;
    employment_status: string | null;
    nik: string | null;
    address: string | null;
    user_code: string | null;
    photo_full_url: string | null;
    role: string | null;
    is_active: boolean;
    is_self: boolean;
};

const props = defineProps<{
    managed: Managed | null;
    roles: { name: string; label: string }[];
    employment: { value: string; label: string }[];
    passwordHint: string;
}>();

const editing = computed(() => props.managed !== null);
const form = computed(() => (props.managed ? EngineerController.update.form(props.managed.ulid) : EngineerController.store.form()));
const title = computed(() => (props.managed ? `Ubah ${props.managed.name}` : 'Tambah teknisi'));

// The chosen picture is shown at once, before anything is uploaded.
const preview = ref<string | null>(props.managed?.photo_full_url ?? null);
let objectUrl: string | null = null;

const pickPhoto = (event: Event): void => {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }

    if (file) {
        objectUrl = URL.createObjectURL(file);
        preview.value = objectUrl;
    }
};

onBeforeUnmount(() => objectUrl && URL.revokeObjectURL(objectUrl));

const showPassword = ref(false);
const selectClass = 'h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-foreground">{{ title }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        editing
                            ? 'Ubah data teknisi. Kosongkan password bila tidak ingin menggantinya.'
                            : 'Isi data teknisi. Setelah disimpan, Anda langsung diarahkan untuk mengatur vendor dan area kerjanya.'
                    }}
                </p>
            </div>
            <Button v-if="managed" variant="outline" as-child>
                <Link :href="UserScopeController.edit(managed.ulid)">Atur vendor dan area</Link>
            </Button>
        </div>

        <Form v-bind="form" class="grid max-w-5xl gap-6 lg:grid-cols-3" enctype="multipart/form-data" v-slot="{ errors, processing }">
            <section class="space-y-4 rounded-xl border bg-card p-5 lg:order-2">
                <h2 class="font-medium">Foto profil</h2>
                <div class="flex flex-col items-center gap-3">
                    <img v-if="preview" :src="preview" alt="Pratinjau foto" class="size-36 rounded-full border object-cover" />
                    <div v-else class="flex size-36 items-center justify-center rounded-full border bg-muted text-sm text-muted-foreground">Belum ada foto</div>
                    <input
                        id="photo"
                        type="file"
                        name="photo"
                        accept="image/jpeg,image/png,image/webp"
                        :required="!editing"
                        class="w-full text-sm file:me-3 file:rounded-md file:border file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                        @change="pickPhoto"
                    />
                    <p class="text-center text-xs text-muted-foreground">JPG, PNG, atau WebP. Fotonya dipotong persegi otomatis.</p>
                    <InputError :message="errors.photo" />
                </div>
            </section>

            <div class="space-y-6 lg:order-1 lg:col-span-2">
                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">Identitas</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="user_code">Kode user</Label>
                            <Input id="user_code" name="user_code" :default-value="managed?.user_code ?? ''" placeholder="ENG-0012" autocomplete="off" required />
                            <InputError :message="errors.user_code" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="name">Nama lengkap</Label>
                            <Input id="name" name="name" :default-value="managed?.name" autocomplete="name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="nik">NIK</Label>
                            <Input id="nik" name="nik" inputmode="numeric" maxlength="16" pattern="[0-9]{16}" :default-value="managed?.nik ?? ''" placeholder="16 digit" autocomplete="off" required />
                            <InputError :message="errors.nik" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="phone">Nomor telepon</Label>
                            <Input id="phone" name="phone" type="tel" :default-value="managed?.phone ?? ''" placeholder="0812…" autocomplete="tel" required />
                            <InputError :message="errors.phone" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="address">Alamat</Label>
                            <textarea id="address" name="address" rows="3" maxlength="500" required class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm">{{ managed?.address ?? '' }}</textarea>
                            <InputError :message="errors.address" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-medium">Akun dan kepegawaian</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="username">Username</Label>
                            <Input id="username" name="username" :default-value="managed?.username ?? ''" placeholder="huruf kecil, angka, . _ -" autocomplete="off" required />
                            <InputError :message="errors.username" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">Email</Label>
                            <Input id="email" name="email" type="email" :default-value="managed?.email" autocomplete="off" required />
                            <InputError :message="errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="employment_status">Status karyawan</Label>
                            <select id="employment_status" name="employment_status" :class="selectClass" required>
                                <option value="" disabled :selected="!managed?.employment_status">Pilih status</option>
                                <option v-for="option in employment" :key="option.value" :value="option.value" :selected="option.value === managed?.employment_status">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="errors.employment_status" />
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="mb-1 text-sm font-medium">Posisi</legend>
                            <div class="flex flex-wrap gap-4">
                                <label v-for="role in roles" :key="role.name" class="flex items-center gap-2 text-sm">
                                    <input type="radio" name="role" :value="role.name" :checked="(managed?.role ?? roles[0]?.name) === role.name" :disabled="managed?.is_self" class="size-4 accent-primary" />
                                    {{ role.label }}
                                </label>
                            </div>
                            <input v-if="managed?.is_self" type="hidden" name="role" :value="managed.role ?? ''" />
                            <InputError :message="errors.role" />
                        </fieldset>
                        <div class="grid gap-2">
                            <Label for="password">{{ editing ? 'Password baru (opsional)' : 'Password' }}</Label>
                            <div class="flex gap-2">
                                <Input id="password" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" :required="!editing" class="flex-1" />
                                <Button type="button" variant="outline" size="sm" class="h-10" @click="showPassword = !showPassword">{{ showPassword ? 'Sembunyi' : 'Lihat' }}</Button>
                            </div>
                            <InputError :message="errors.password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password_confirmation">Ulangi password</Label>
                            <Input id="password_confirmation" name="password_confirmation" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" :required="!editing" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Password minimal sesuai aturan sistem. Menggantinya mengeluarkan teknisi dari semua perangkat.</p>

                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" :checked="managed?.is_active ?? true" :disabled="managed?.is_self" class="size-4 rounded border-input accent-primary" />
                        Akun aktif (boleh masuk aplikasi)
                    </label>
                    <input v-if="managed?.is_self" type="hidden" name="is_active" value="1" />
                </section>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="processing">{{ editing ? 'Simpan' : 'Simpan dan atur area' }}</Button>
                    <Button variant="outline" as-child>
                        <Link href="/iam/engineers">Batal</Link>
                    </Button>
                </div>
            </div>
        </Form>

        <Form
            v-if="managed?.photo_full_url"
            v-bind="UserPhotoController.destroy.form(managed.ulid)"
            :options="{ preserveScroll: true }"
            class="max-w-5xl"
        >
            <Button type="submit" variant="ghost" size="sm">Hapus foto profil</Button>
        </Form>
    </div>
</template>
