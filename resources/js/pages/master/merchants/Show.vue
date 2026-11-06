<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import MerchantSiteController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantSiteController';
import TerminalController from '@/actions/Modules/Master/Http/Web/Controllers/TerminalController';
import ConfirmDelete from '@/components/master/ConfirmDelete.vue';
import StatusBadge from '@/components/master/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { MerchantDetail, SiteDetail } from '@/types/master';

const props = defineProps<{
    merchant: MerchantDetail;
    sites: SiteDetail[];
    can: { manage: boolean; manageTerminals: boolean };
}>();

const details: [string, string | null][] = [
    ['MID', props.merchant.merchant_code],
    ['Vendor', props.merchant.vendor],
    ['Pemilik', props.merchant.owner_name],
    ['Telepon', props.merchant.phone],
    ['Kategori', props.merchant.category],
];
</script>

<template>
    <Head :title="merchant.name" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ merchant.name }}
                </h1>
                <dl
                    class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm"
                >
                    <template v-for="[label, value] in details" :key="label">
                        <dt class="text-muted-foreground">{{ label }}</dt>
                        <dd>{{ value ?? '—' }}</dd>
                    </template>
                </dl>
            </div>
            <div v-if="can.manage" class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link :href="MerchantController.edit(merchant.ulid)"
                        >Ubah</Link
                    >
                </Button>
                <ConfirmDelete
                    :url="MerchantController.destroy.url(merchant.ulid)"
                    :title="`Hapus merchant ${merchant.name}?`"
                    description="Seluruh site merchant ikut dihapus. Merchant yang masih memiliki TID tidak dapat dihapus."
                />
            </div>
        </div>

        <div class="flex items-center justify-between">
            <h2 class="text-lg font-medium">Site</h2>
            <Button v-if="can.manage" size="sm" as-child>
                <Link :href="MerchantSiteController.create(merchant.ulid)"
                    >Tambah site</Link
                >
            </Button>
        </div>

        <p
            v-if="sites.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Belum ada site. Tambahkan site terlebih dahulu agar TID dapat
            didaftarkan.
        </p>

        <Card v-for="site in sites" :key="site.ulid">
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <CardTitle class="flex items-center gap-2">
                            {{ site.name ?? site.address }}
                            <StatusBadge :active="site.is_active" />
                        </CardTitle>
                        <CardDescription>
                            {{ site.address }} · {{ site.area }}<template v-if="site.service_point">
                                ({{ site.service_point }}<template v-if="site.province">, {{ site.province }}</template>)</template
                            >
                        </CardDescription>
                        <p
                            v-if="site.pic_name || site.pic_phone"
                            class="text-sm text-muted-foreground"
                        >
                            PIC: {{ site.pic_name ?? '—' }}
                            {{ site.pic_phone ? `· ${site.pic_phone}` : '' }}
                        </p>
                    </div>
                    <div v-if="can.manage" class="flex gap-1">
                        <Button variant="ghost" size="sm" as-child>
                            <Link
                                :href="MerchantSiteController.edit(site.ulid)"
                                >Ubah</Link
                            >
                        </Button>
                        <ConfirmDelete
                            :url="MerchantSiteController.destroy.url(site.ulid)"
                            title="Hapus site ini?"
                            description="Site yang masih memiliki TID tidak dapat dihapus."
                        />
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium">TID</h3>
                    <Button
                        v-if="can.manageTerminals"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                TerminalController.create({
                                    query: { site: site.ulid },
                                })
                            "
                            >Tambah TID</Link
                        >
                    </Button>
                </div>
                <p
                    v-if="site.terminals.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada TID di site ini.
                </p>
                <ul v-else class="divide-y rounded-md border">
                    <li
                        v-for="terminal in site.terminals"
                        :key="terminal.ulid"
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-sm">{{
                                terminal.tid
                            }}</span>
                            <StatusBadge
                                :status="terminal.status"
                                :label="terminal.status_label"
                            />
                        </div>
                        <div v-if="can.manageTerminals" class="flex gap-1">
                            <Button variant="ghost" size="sm" as-child>
                                <Link
                                    :href="
                                        TerminalController.edit(terminal.ulid)
                                    "
                                    >Ubah</Link
                                >
                            </Button>
                            <ConfirmDelete
                                :url="
                                    TerminalController.destroy.url(
                                        terminal.ulid,
                                    )
                                "
                                :title="`Hapus TID ${terminal.tid}?`"
                                description="TID akan dihapus dari daftar. Riwayatnya tetap tersimpan di audit log."
                            />
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
