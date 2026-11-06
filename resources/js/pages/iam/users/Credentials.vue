<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/Modules/IAM/Http/Web/Controllers/UserController';
import { Button } from '@/components/ui/button';

defineProps<{
    account: { name: string; email: string };
    password: string | null;
    reset: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }] },
});
</script>

<template>
    <Head title="Password sementara" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                {{ reset ? 'Password baru dibuat' : 'Pengguna dibuat' }}
            </h1>
            <p class="text-sm text-muted-foreground">
                Password sementara hanya tampil di halaman ini. Berikan kepada pemiliknya lewat jalur aman dan minta ia
                menggantinya setelah masuk. Jika hilang, buat ulang dari halaman ubah pengguna.
            </p>
        </div>

        <dl class="panel max-w-2xl space-y-4 p-5 text-sm">
            <div>
                <dt class="text-muted-foreground">Akun</dt>
                <dd class="font-medium">{{ account.name }} ({{ account.email }})</dd>
            </div>
            <div v-if="password">
                <dt class="text-muted-foreground">Password sementara</dt>
                <dd class="font-mono text-base break-all select-all">{{ password }}</dd>
            </div>
        </dl>

        <div>
            <Button as-child>
                <Link :href="UserController.index()">Sudah saya simpan</Link>
            </Button>
        </div>
    </div>
</template>
