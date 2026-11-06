<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option, VendorOption } from '@/types/master';
import type { TicketTypeOption } from '@/types/ticket';

const props = defineProps<{
    vendors: VendorOption[];
    types: TicketTypeOption[];
    priorities: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tiket', href: TicketController.index() }],
    },
});

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({
        value: vendor.ulid,
        label: `${vendor.code} — ${vendor.name}`,
    })),
);
const typeOptions = computed(() =>
    props.types.map((type) => ({
        value: type.ulid,
        label: `${type.name} (SLA ${type.sla_hours} jam)`,
    })),
);
</script>

<template>
    <Head title="Buat tiket" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Buat tiket</h1>
            <p class="text-sm text-muted-foreground">
                Untuk tiket yang tidak datang lewat API vendor. Vendor, merchant,
                dan lokasi diambil dari TID.
            </p>
        </div>

        <Form
            v-bind="TicketController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="vendor">Vendor</Label>
                <FormSelect
                    id="vendor"
                    name="vendor"
                    :options="vendorOptions"
                    placeholder="Pilih vendor…"
                    required
                />
                <InputError :message="errors.vendor" />
            </div>

            <div class="grid gap-2">
                <Label for="tid">TID</Label>
                <Input
                    id="tid"
                    name="tid"
                    class="font-mono"
                    placeholder="Ketik TID persis"
                    required
                />
                <InputError :message="errors.tid" />
            </div>

            <div class="grid gap-2">
                <Label for="ticket_type">Jenis pekerjaan</Label>
                <FormSelect
                    id="ticket_type"
                    name="ticket_type"
                    :options="typeOptions"
                    placeholder="Pilih jenis…"
                    required
                />
                <InputError :message="errors.ticket_type" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="priority">Prioritas</Label>
                    <FormSelect
                        id="priority"
                        name="priority"
                        :options="priorities"
                        default-value="normal"
                        required
                    />
                    <InputError :message="errors.priority" />
                </div>
                <div class="grid gap-2">
                    <Label for="scheduled_at">Jadwal (opsional)</Label>
                    <Input
                        id="scheduled_at"
                        name="scheduled_at"
                        type="datetime-local"
                    />
                    <InputError :message="errors.scheduled_at" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="vendor_ticket_no">No. tiket vendor (opsional)</Label>
                <Input id="vendor_ticket_no" name="vendor_ticket_no" />
                <InputError :message="errors.vendor_ticket_no" />
            </div>

            <div class="grid gap-2">
                <Label for="description">Keterangan</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">Buat tiket</Button>
                <Button variant="ghost" as-child>
                    <Link :href="TicketController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
