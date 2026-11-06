<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import IntegrationClientController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationClientController';
import { Button } from '@/components/ui/button';

defineProps<{
    client: { name: string; vendor: string; key_id: string };
    secret: string;
    webhook_secret: string | null;
}>();

</script>

<template>
    <Head title="Kunci rahasia klien" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Simpan kunci ini sekarang</h1>
            <p class="text-sm text-muted-foreground">
                Kunci rahasia hanya ditampilkan di halaman ini. Setelah Anda meninggalkannya, tidak ada yang dapat
                melihatnya lagi, termasuk kami. Kirim lewat jalur aman ke pihak {{ client.vendor }}. Jika hilang, ganti kuncinya.
            </p>
        </div>

        <dl class="max-w-2xl space-y-4 rounded-lg border p-4 text-sm">
            <div>
                <dt class="text-muted-foreground">Klien</dt>
                <dd class="font-medium">{{ client.name }} ({{ client.vendor }})</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Key ID</dt>
                <dd class="font-mono break-all">{{ client.key_id }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Secret (untuk menandatangani permintaan ke kita)</dt>
                <dd class="font-mono break-all">{{ secret }}</dd>
            </div>
            <div v-if="webhook_secret">
                <dt class="text-muted-foreground">Secret callback (untuk memeriksa tanda tangan kiriman kita)</dt>
                <dd class="font-mono break-all">{{ webhook_secret }}</dd>
            </div>
        </dl>

        <div>
            <Button as-child>
                <Link :href="IntegrationClientController.index()">Sudah saya simpan</Link>
            </Button>
        </div>
    </div>
</template>
