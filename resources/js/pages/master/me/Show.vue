<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import UserPhotoController from '@/actions/Modules/IAM/Http/Web/Controllers/UserPhotoController';
import MeController from '@/actions/Modules/Master/Http/Web/Controllers/MeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    me: {
        ulid: string;
        name: string;
        email: string;
        phone: string | null;
        user_code: string | null;
        employee_no: string | null;
        user_type_label: string;
        roles: string[];
        photo_full_url: string | null;
    };
    vendors: { code: string; name: string }[];
    areas: { name: string; type_label: string; parent: string | null; vendor: string | null }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Profil Saya', href: MeController.show() }] },
});
</script>

<template>
    <Head title="Profil Saya" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Profil Saya</h1>

        <section class="flex max-w-3xl flex-wrap items-center gap-5 rounded-xl border bg-card p-5">
            <img
                v-if="me.photo_full_url"
                :src="me.photo_full_url"
                :alt="`Foto ${me.name}`"
                class="size-24 rounded-full border object-cover"
            />
            <div v-else class="flex size-24 items-center justify-center rounded-full border bg-muted text-3xl font-semibold text-muted-foreground">
                {{ me.name.slice(0, 1).toUpperCase() }}
            </div>

            <div class="flex-1 space-y-1">
                <p class="text-lg font-medium">{{ me.name }}</p>
                <p class="text-sm text-muted-foreground">
                    <span v-if="me.user_code" class="font-mono">{{ me.user_code }}</span>
                    <span v-if="me.user_code"> · </span>{{ me.user_type_label }} · {{ me.roles.join(', ') }}
                </p>
                <p class="text-sm text-muted-foreground">{{ me.email }}<template v-if="me.phone"> · {{ me.phone }}</template></p>

                <Form
                    v-bind="UserPhotoController.update.form(me.ulid)"
                    class="flex flex-wrap items-center gap-2 pt-2"
                    v-slot="{ errors, processing }"
                >
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="text-sm" />
                    <Button type="submit" size="sm" :disabled="processing">Ganti foto</Button>
                    <InputError :message="errors.photo" />
                </Form>
            </div>
        </section>

        <div class="grid max-w-3xl gap-6 md:grid-cols-2">
            <section class="rounded-xl border bg-card p-5">
                <h2 class="mb-2 font-medium">Vendor yang saya tangani</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="vendor in vendors" :key="vendor.code">{{ vendor.code }} — {{ vendor.name }}</li>
                    <li v-if="vendors.length === 0" class="text-muted-foreground">Semua vendor (tidak dibatasi).</li>
                </ul>
            </section>
            <section class="rounded-xl border bg-card p-5">
                <h2 class="mb-2 font-medium">Area kerja saya</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="area in areas" :key="area.name + area.type_label">
                        {{ area.name }}
                        <span class="text-xs text-muted-foreground">
                            {{ area.type_label }}<template v-if="area.parent"> · {{ area.parent }}</template
                            ><template v-if="area.vendor"> · {{ area.vendor }}</template>
                        </span>
                    </li>
                    <li v-if="areas.length === 0" class="text-muted-foreground">Belum ada area. Hubungi koordinator.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
