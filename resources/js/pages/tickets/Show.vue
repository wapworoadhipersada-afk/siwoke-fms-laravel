<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BeritaAcaraController from '@/actions/Modules/Ticket/Http/Web/Controllers/BeritaAcaraController';
import MerchantController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import InputError from '@/components/InputError.vue';
import FormSelect from '@/components/master/FormSelect.vue';
import ReasonDialog from '@/components/master/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UlidOption } from '@/types/master';
import type {
    AssetLineEntry,
    AssignmentEntry,
    AttachmentEntry,
    BeritaAcaraEntry,
    TicketDetail,
    TimelineEntry,
    VisitEntry,
} from '@/types/ticket';

const props = defineProps<{
    ticket: TicketDetail;
    timeline: TimelineEntry[];
    visits: VisitEntry[];
    assignments: AssignmentEntry[];
    attachments: AttachmentEntry[];
    beritaAcara: BeritaAcaraEntry[];
    assetLines: AssetLineEntry[];
    technicians: UlidOption[];
    can: {
        assign: boolean;
        unassign: boolean;
        cancel: boolean;
        reopen: boolean;
        generate_ba: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tiket', href: TicketController.index() }],
    },
});

const technicianOptions = computed(() =>
    props.technicians.map((technician) => ({
        value: technician.ulid,
        label: technician.label,
    })),
);

const hasActions = computed(
    () =>
        props.can.assign ||
        props.can.unassign ||
        props.can.cancel ||
        props.can.reopen,
);

const reportableVisits = computed(() => props.visits.filter((visit) => visit.reportable));

const hasReport = (visitNo: number): boolean =>
    props.beritaAcara.some((report) => report.visit_no === visitNo);

// Evidence grouped by visit, so a rescheduled ticket shows each attempt's photos on their own.
const evidenceByVisit = computed(() => {
    const groups = new Map<number, AttachmentEntry[]>();

    for (const file of props.attachments) {
        groups.set(file.visit_no, [...(groups.get(file.visit_no) ?? []), file]);
    }

    return [...groups.entries()].sort(([a], [b]) => a - b);
});

const mapUrl = computed(() =>
    props.ticket.site.latitude && props.ticket.site.longitude
        ? `https://www.google.com/maps?q=${props.ticket.site.latitude},${props.ticket.site.longitude}`
        : null,
);

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString('id-ID', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}
</script>

<template>
    <Head :title="ticket.ticket_no" />

    <div class="flex flex-1 flex-col gap-6">
        <div>
            <h1
                class="flex flex-wrap items-center gap-3 font-mono text-xl font-semibold text-gray-800 dark:text-white/90"
            >
                {{ ticket.ticket_no }}
                <Badge>{{ ticket.status_label }}</Badge>
                <Badge v-if="ticket.is_overdue" variant="destructive"
                    >Terlambat SLA</Badge
                >
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ ticket.type }} · {{ ticket.vendor }}
                <template v-if="ticket.vendor_ticket_no">
                    · ref. {{ ticket.vendor_ticket_no }}</template
                >
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Tiket</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Prioritas</dt>
                        <dd>{{ ticket.priority_label }}</dd>
                        <dt class="text-muted-foreground">Sumber</dt>
                        <dd>{{ ticket.source }}</dd>
                        <dt class="text-muted-foreground">Teknisi</dt>
                        <dd>{{ ticket.technician ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Diterima</dt>
                        <dd>{{ formatDate(ticket.received_at) }}</dd>
                        <dt class="text-muted-foreground">Jadwal</dt>
                        <dd>{{ formatDate(ticket.scheduled_at) }}</dd>
                        <dt class="text-muted-foreground">Batas SLA</dt>
                        <dd :class="ticket.is_overdue ? 'text-destructive' : ''">
                            {{ formatDate(ticket.sla_due_at) }}
                        </dd>
                        <dt class="text-muted-foreground">Ditutup</dt>
                        <dd>{{ formatDate(ticket.closed_at) }}</dd>
                        <dt class="text-muted-foreground">Dibuat oleh</dt>
                        <dd>{{ ticket.created_by ?? 'Sistem / API' }}</dd>
                    </dl>
                    <p v-if="ticket.description" class="mt-4 text-sm whitespace-pre-line">
                        {{ ticket.description }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Lokasi</CardTitle>
                    <CardDescription>
                        TID
                        <span class="font-mono">{{ ticket.tid }}</span>
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Merchant</dt>
                        <dd>
                            <Link
                                :href="MerchantController.show(ticket.merchant.ulid)"
                                class="underline-offset-4 hover:underline"
                                >{{ ticket.merchant.name }}</Link
                            >
                        </dd>
                        <dt class="text-muted-foreground">Telepon</dt>
                        <dd>{{ ticket.merchant.phone ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Alamat</dt>
                        <dd>{{ ticket.site.address }}</dd>
                        <dt class="text-muted-foreground">Service Point</dt>
                        <dd>{{ ticket.site.service_point }}</dd>
                        <dt class="text-muted-foreground">PIC</dt>
                        <dd>
                            {{ ticket.site.pic_name ?? '—' }}
                            <template v-if="ticket.site.pic_phone">
                                · {{ ticket.site.pic_phone }}</template
                            >
                        </dd>
                    </dl>
                    <Button v-if="mapUrl" variant="outline" size="sm" class="mt-4" as-child>
                        <a :href="mapUrl" target="_blank" rel="noopener noreferrer"
                            >Buka peta</a
                        >
                    </Button>
                </CardContent>
            </Card>
        </div>

        <Card v-if="hasActions">
            <CardHeader>
                <CardTitle>Tindakan</CardTitle>
            </CardHeader>
            <CardContent class="space-y-6">
                <Form
                    v-if="can.assign"
                    v-bind="TicketController.assign.form(ticket.ulid)"
                    :options="{ preserveScroll: true }"
                    class="grid max-w-xl gap-4"
                    v-slot="{ errors, processing }"
                >
                    <h3 class="text-sm font-medium">
                        {{ ticket.technician ? 'Alihkan ke teknisi lain' : 'Tugaskan ke teknisi' }}
                    </h3>
                    <div class="grid gap-2">
                        <Label for="technician">Teknisi</Label>
                        <FormSelect
                            id="technician"
                            name="technician"
                            :options="technicianOptions"
                            placeholder="Pilih teknisi…"
                            required
                        />
                        <InputError :message="errors.technician" />
                        <InputError :message="errors.status" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scheduled_at">Jadwal kunjungan (opsional)</Label>
                        <Input id="scheduled_at" name="scheduled_at" type="datetime-local" />
                        <InputError :message="errors.scheduled_at" />
                    </div>
                    <div v-if="ticket.technician" class="grid gap-2">
                        <Label for="reason">Alasan pengalihan</Label>
                        <Input id="reason" name="reason" />
                    </div>
                    <div>
                        <Button type="submit" :disabled="processing">Tugaskan</Button>
                    </div>
                </Form>

                <div class="flex flex-wrap gap-2">
                    <ReasonDialog
                        v-if="can.unassign"
                        :form="TicketController.unassign.form(ticket.ulid)"
                        label="Cabut penugasan"
                        title="Cabut penugasan?"
                        description="Tiket kembali ke daftar belum ditugaskan. Jelaskan alasannya."
                    />
                    <ReasonDialog
                        v-if="can.cancel"
                        :form="TicketController.cancel.form(ticket.ulid)"
                        label="Batalkan tiket"
                        title="Batalkan tiket?"
                        description="Tiket yang dibatalkan tidak dapat dikerjakan lagi."
                        confirm="Batalkan"
                        destructive
                    />
                    <ReasonDialog
                        v-if="can.reopen"
                        :form="TicketController.reopen.form(ticket.ulid)"
                        label="Buka kembali"
                        title="Buka kembali tiket?"
                        description="Tiket kembali ke daftar belum ditugaskan. Penutupan sebelumnya tetap tercatat di riwayat."
                        confirm="Buka kembali"
                    />
                </div>
            </CardContent>
        </Card>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Kunjungan</h2>
            <p v-if="visits.length === 0" class="text-sm text-muted-foreground">
                Belum ada kunjungan.
            </p>
            <div v-else class="overflow-x-auto panel">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-4 py-3 font-medium">#</th>
                            <th class="px-4 py-3 font-medium">Teknisi</th>
                            <th class="px-4 py-3 font-medium">Tiba</th>
                            <th class="px-4 py-3 font-medium">Selesai</th>
                            <th class="px-4 py-3 font-medium">Hasil</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="visit in visits" :key="visit.visit_no">
                            <td class="px-4 py-3">{{ visit.visit_no }}</td>
                            <td class="px-4 py-3">{{ visit.technician }}</td>
                            <td class="px-4 py-3">
                                {{ formatDate(visit.arrived_at) }}
                                <span
                                    v-if="visit.distance_m !== null"
                                    class="block text-xs text-muted-foreground"
                                    >{{ visit.distance_m }} m dari site</span
                                >
                            </td>
                            <td class="px-4 py-3">{{ formatDate(visit.closed_at) }}</td>
                            <td class="px-4 py-3">
                                {{ visit.outcome_label }}
                                <span v-if="visit.reason" class="block text-xs text-muted-foreground">{{ visit.reason }}</span>
                                <span v-if="visit.notes" class="block text-xs text-muted-foreground">{{ visit.notes }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="assetLines.length > 0" class="space-y-3">
            <h2 class="text-lg font-medium">Aset pada kunjungan</h2>
            <div class="overflow-x-auto panel">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-4 py-3 font-medium">Kunjungan</th>
                            <th class="px-4 py-3 font-medium">Tindakan</th>
                            <th class="px-4 py-3 font-medium">Barang</th>
                            <th class="px-4 py-3 font-medium">Jumlah</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(line, index) in assetLines" :key="index">
                            <td class="px-4 py-3">{{ line.visit_no }}</td>
                            <td class="px-4 py-3">{{ line.action_label }}</td>
                            <td class="px-4 py-3">
                                {{ line.item }}
                                <span v-if="line.notes" class="block text-xs text-muted-foreground">{{ line.notes }}</span>
                            </td>
                            <td class="px-4 py-3">{{ line.quantity }}</td>
                            <td class="px-4 py-3">
                                {{ line.state }}
                                <span v-if="line.movement_no" class="block text-xs text-muted-foreground">{{ line.movement_no }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="beritaAcara.length > 0 ||(can.generate_ba && reportableVisits.length > 0)" class="space-y-3">
            <h2 class="text-lg font-medium">Berita Acara</h2>
            <ul v-if="beritaAcara.length > 0" class="divide-y panel text-sm">
                <li v-for="report in beritaAcara" :key="report.ulid" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <div>
                        <p class="font-medium">
                            {{ report.label }}
                            <Badge v-if="!report.is_current" variant="outline" class="ml-2">Digantikan</Badge>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Kunjungan {{ report.visit_no }} · {{ report.outcome_label }} · {{ formatDate(report.generated_at) }}
                        </p>
                        <p v-if="report.reason" class="text-xs text-muted-foreground">Alasan revisi: {{ report.reason }}</p>
                    </div>
                    <Button as-child variant="outline" size="sm">
                        <a :href="report.url">Unduh PDF</a>
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Berita Acara sedang dibuat atau belum ada.</p>
            <div v-if="can.generate_ba" class="flex flex-wrap gap-2">
                <ReasonDialog
                    v-for="visit in reportableVisits"
                    :key="visit.visit_no"
                    :form="BeritaAcaraController.store.form({ ticket: ticket.ulid, visitNo: visit.visit_no })"
                    :label="hasReport(visit.visit_no) ? `Buat ulang BA kunjungan ${visit.visit_no}` : `Buat BA kunjungan ${visit.visit_no}`"
                    title="Buat Berita Acara?"
                    description="Berita Acara lama tidak dihapus; yang baru menjadi revisi dengan nomor yang sama. Jelaskan alasannya."
                    confirm="Buat"
                />
            </div>
        </section>

        <section v-if="attachments.length > 0" class="space-y-4">
            <h2 class="text-lg font-medium">Bukti</h2>
            <div v-for="[visitNo, files] in evidenceByVisit" :key="visitNo" class="space-y-2">
                <h3 class="text-sm font-medium text-muted-foreground">Kunjungan {{ visitNo }}</h3>
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <li v-for="file in files" :key="file.url" class="overflow-hidden panel">
                        <a :href="file.url" target="_blank" rel="noopener noreferrer" class="block bg-muted/30">
                            <img :src="file.thumbnail_url" :alt="file.kind_label" loading="lazy" class="aspect-[4/3] w-full object-contain" />
                        </a>
                        <div class="space-y-0.5 p-2 text-xs">
                            <p class="font-medium">{{ file.kind_label }}</p>
                            <p class="text-muted-foreground">{{ formatDate(file.captured_at ?? file.uploaded_at) }} · {{ file.uploader }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Riwayat status</h2>
            <ol class="space-y-3">
                <li
                    v-for="(entry, index) in timeline"
                    :key="index"
                    class="panel p-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">
                            {{ entry.event_label }}
                            <span class="text-muted-foreground">→ {{ entry.to_status_label }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDate(entry.occurred_at) }}
                        </p>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        oleh {{ entry.actor }}
                        <template v-if="entry.reason"> · {{ entry.reason }}</template>
                        <template v-if="entry.notes"> · {{ entry.notes }}</template>
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Badge v-if="entry.on_behalf" variant="secondary"
                            >Dicatat kantor atas nama teknisi</Badge
                        >
                        <Badge v-if="entry.outside_radius" variant="destructive"
                            >Tiba {{ entry.distance_m }} m dari site</Badge
                        >
                    </div>
                </li>
            </ol>
        </section>

        <section v-if="assignments.length > 0" class="space-y-3">
            <h2 class="text-lg font-medium">Riwayat penugasan</h2>
            <ul class="divide-y panel text-sm">
                <li v-for="(assignment, index) in assignments" :key="index" class="px-4 py-3">
                    <span class="font-medium">{{ assignment.technician }}</span>
                    ditugaskan oleh {{ assignment.assigned_by }} pada
                    {{ formatDate(assignment.assigned_at) }}
                    <template v-if="assignment.unassigned_at">
                        · berakhir {{ formatDate(assignment.unassigned_at) }}
                        <template v-if="assignment.unassign_reason"> ({{ assignment.unassign_reason }})</template>
                    </template>
                </li>
            </ul>
        </section>
    </div>
</template>
