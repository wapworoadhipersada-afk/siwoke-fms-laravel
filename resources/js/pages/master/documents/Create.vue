<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import MerchantDocumentController from '@/actions/Modules/Master/Http/Web/Controllers/MerchantDocumentController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    vendors: { ulid: string; label: string }[];
    defaultVendor: string | null;
    places: { provinces: string[]; service_points: string[]; areas: string[] };
    today: string;
}>();

const mode = ref<'upload' | 'manual'>('upload');
const rows = ref<number[]>([0, 1, 2]);
let next = 3;

const addRow = (): void => {
    rows.value.push(next++);
};

const removeRow = (id: number): void => {
    if (rows.value.length > 1) {
        rows.value = rows.value.filter((row) => row !== id);
    }
};

const field = 'h-9 w-full min-w-28 rounded-md border bg-transparent px-2 text-sm';
</script>

<template>
    <Head title="Dokumen baru" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Dokumen baru</h1>
            <p class="text-sm text-muted-foreground">
                Catat nomor dokumen dari pemberi kerja, lalu isi merchant dan TID-nya, baik dari file Excel/CSV maupun
                diketik. Dokumen disimpan sebagai draf dan diperiksa dulu sebelum masuk ke data merchant.
            </p>
        </div>

        <Form
            v-bind="MerchantDocumentController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <section class="grid max-w-4xl gap-4 rounded-xl border bg-card p-5 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="document_number">Nomor dokumen</Label>
                    <Input id="document_number" name="document_number" required placeholder="B.38121-MMS-JKE/IJE/10/2024" />
                    <InputError :message="errors.document_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="received_on">Tanggal diterima</Label>
                    <Input id="received_on" name="received_on" type="date" :value="props.today" :max="props.today" required />
                    <InputError :message="errors.received_on" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor">Vendor (pemberi kerja)</Label>
                    <select id="vendor" name="vendor" required class="h-9 rounded-md border bg-transparent px-3 text-sm">
                        <option value="" disabled :selected="!defaultVendor">Pilih vendor</option>
                        <option v-for="vendor in vendors" :key="vendor.ulid" :value="vendor.ulid" :selected="vendor.ulid === defaultVendor">
                            {{ vendor.label }}
                        </option>
                    </select>
                    <InputError :message="errors.vendor" />
                </div>
                <div class="grid gap-2">
                    <Label for="notes">Catatan dokumen</Label>
                    <Input id="notes" name="notes" placeholder="Opsional" />
                </div>
            </section>

            <div class="flex gap-2">
                <Button type="button" :variant="mode === 'upload' ? 'default' : 'outline'" @click="mode = 'upload'">Unggah Excel / CSV</Button>
                <Button type="button" :variant="mode === 'manual' ? 'default' : 'outline'" @click="mode = 'manual'">Isi manual</Button>
            </div>

            <section v-if="mode === 'upload'" class="max-w-4xl space-y-3 rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Baris pertama berisi judul kolom: Nama Merchant, TID, MID, Alamat, Provinsi, Service Point, Area,
                    Keterangan TID. Urutan kolom bebas; hanya Nama Merchant dan TID yang wajib ada. Maksimal 2000 baris.
                </p>
                <input type="file" name="file" accept=".xlsx,.csv" required class="text-sm" />
                <InputError :message="errors.file" />
                <p>
                    <a :href="MerchantDocumentController.template.url()" class="text-sm underline">Unduh contoh file (CSV)</a>
                </p>
            </section>

            <section v-else class="space-y-3 rounded-xl border bg-card p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-1 pb-2">Nama merchant</th>
                                <th class="px-1 pb-2">TID</th>
                                <th class="px-1 pb-2">MID</th>
                                <th class="px-1 pb-2">Alamat</th>
                                <th class="px-1 pb-2">Provinsi</th>
                                <th class="px-1 pb-2">Service point</th>
                                <th class="px-1 pb-2">Area</th>
                                <th class="px-1 pb-2">Keterangan TID</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(id, index) in rows" :key="id">
                                <td class="p-1"><input :name="`lines[${id}][merchant_name]`" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][tid]`" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][mid]`" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][address]`" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][province]`" list="provinces" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][service_point]`" list="service_points" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][area]`" list="areas" :class="field" /></td>
                                <td class="p-1"><input :name="`lines[${id}][tid_note]`" :class="field" /></td>
                                <td class="p-1">
                                    <Button type="button" variant="ghost" size="sm" :disabled="rows.length === 1" :aria-label="`Hapus baris ${index + 1}`" @click="removeRow(id)">✕</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <datalist id="provinces"><option v-for="name in places.provinces" :key="name" :value="name" /></datalist>
                <datalist id="service_points"><option v-for="name in places.service_points" :key="name" :value="name" /></datalist>
                <datalist id="areas"><option v-for="name in places.areas" :key="name" :value="name" /></datalist>
                <Button type="button" variant="outline" size="sm" @click="addRow">Tambah baris</Button>
                <InputError :message="errors.lines" />
            </section>

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">Simpan sebagai draf</Button>
                <Button variant="outline" as-child>
                    <Link :href="MerchantDocumentController.index()">Batal</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
