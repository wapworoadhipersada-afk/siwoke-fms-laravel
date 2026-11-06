<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    account: { name: string; email: string };
    password: string | null;
    reset: boolean;
    sectionHref: string;
    coverageHref: string | null;
}>();
</script>

<template>
    <Head title="Password sementara" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold text-foreground">
                {{ reset ? 'Password baru dibuat' : 'Akun dibuat' }}
            </h1>
            <p class="text-sm text-muted-foreground">
                Password sementara hanya tampil di halaman ini. Berikan kepada pemiliknya lewat jalur aman dan minta ia
                menggantinya setelah masuk. Jika hilang, buat ulang dari halaman ubah.
            </p>
        </div>

        <dl class="max-w-2xl space-y-4 rounded-xl border bg-card p-5 text-sm">
            <div>
                <dt class="text-muted-foreground">Akun</dt>
                <dd class="font-medium">{{ account.name }} ({{ account.email }})</dd>
            </div>
            <div v-if="password">
                <dt class="text-muted-foreground">Password sementara</dt>
                <dd class="font-mono text-base break-all select-all">{{ password }}</dd>
            </div>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button v-if="coverageHref" as-child>
                <Link :href="coverageHref">Atur area dan vendor</Link>
            </Button>
            <Button :variant="coverageHref ? 'outline' : 'default'" as-child>
                <Link :href="sectionHref">Sudah saya simpan</Link>
            </Button>
        </div>
    </div>
</template>
