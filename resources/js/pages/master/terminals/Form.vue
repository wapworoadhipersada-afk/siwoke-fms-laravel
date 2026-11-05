<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import TerminalController from '@/actions/Modules/Master/Http/Web/Controllers/TerminalController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option, SiteSummary, UlidOption } from '@/types/master';

const props = defineProps<{
    terminal: { ulid: string; tid: string; status: string; site: string } | null;
    site: SiteSummary;
    siteOptions: UlidOption[];
    statuses: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'TID', href: TerminalController.index() }],
    },
});

const form = computed(() =>
    props.terminal
        ? TerminalController.update.form(props.terminal.ulid)
        : TerminalController.store.form(),
);
const title = computed(() => (props.terminal ? 'Ubah TID' : 'Tambah TID'));
const sites = computed(() =>
    props.siteOptions.map((option) => ({
        value: option.ulid,
        label: option.label,
    })),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ site.vendor }} · {{ site.merchant }} — {{ site.address }}
            </p>
        </div>

        <Form
            v-bind="form"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <!-- A new TID is always registered from its site; moving one is limited to the same merchant's sites. -->
            <input
                v-if="!terminal || sites.length <= 1"
                type="hidden"
                name="site"
                :value="site.ulid"
            />
            <div v-else class="grid gap-2">
                <Label for="site">Site</Label>
                <FormSelect
                    id="site"
                    name="site"
                    :options="sites"
                    :default-value="terminal.site"
                    required
                />
            </div>
            <InputError :message="errors.site" />

            <div class="grid gap-2">
                <Label for="tid">TID</Label>
                <Input
                    id="tid"
                    name="tid"
                    :default-value="terminal?.tid"
                    class="font-mono"
                    required
                />
                <InputError :message="errors.tid" />
            </div>

            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <FormSelect
                    id="status"
                    name="status"
                    :options="statuses"
                    :default-value="terminal?.status ?? 'active'"
                    required
                />
                <InputError :message="errors.status" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button variant="ghost" as-child>
                    <Link :href="MerchantController.show(site.merchant_ulid)"
                        >Batal</Link
                    >
                </Button>
            </div>
        </Form>
    </div>
</template>
