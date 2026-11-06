<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import NavigationController from '@/actions/Modules/IAM/Http/Web/Controllers/NavigationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MenuEntry } from '@/types/iam';

defineProps<{
    mode: 'group' | 'menu';
    groups: MenuEntry[];
    sections: string[];
    pages: { value: string; label: string }[];
    icons: { value: string; label: string }[];
}>();

const open = ref(false);
const target = ref<'page' | 'url'>('page');
const parent = ref('');
const selectClass = 'h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button :variant="mode === 'group' ? 'outline' : 'default'">
                {{ mode === 'group' ? 'Buat group menu' : 'Tambah menu' }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="mode === 'group' ? NavigationController.storeGroup.form() : NavigationController.storeMenu.form()"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                :on-success="() => (open = false)"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-2">
                    <DialogTitle>{{ mode === 'group' ? 'Buat group menu' : 'Tambah menu' }}</DialogTitle>
                    <DialogDescription>
                        {{
                            mode === 'group'
                                ? 'Group adalah judul yang menampung beberapa menu, misalnya Integrasi.'
                                : 'Menu baru bisa dimasukkan ke sebuah group atau berdiri sendiri. Siapa yang melihatnya diatur di Menu per Peran.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="new-label">Nama</Label>
                    <Input id="new-label" name="label" maxlength="60" required />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label for="new-icon">Ikon</Label>
                    <select id="new-icon" name="icon" :class="selectClass">
                        <option value="">(tanpa ikon)</option>
                        <option v-for="icon in icons" :key="icon.value" :value="icon.value">{{ icon.label }}</option>
                    </select>
                    <InputError :message="errors.icon" />
                </div>

                <template v-if="mode === 'menu'">
                    <div class="grid gap-2">
                        <Label for="new-parent">Masuk ke group</Label>
                        <select id="new-parent" v-model="parent" name="parent_id" :class="selectClass">
                            <option value="">(tanpa group, berdiri sendiri)</option>
                            <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.label }}</option>
                        </select>
                        <InputError :message="errors.parent_id" />
                    </div>

                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="target" type="radio" value="page" /> Halaman aplikasi</label>
                        <label class="flex items-center gap-2"><input v-model="target" type="radio" value="url" /> Alamat sendiri</label>
                    </div>

                    <div v-if="target === 'page'" class="grid gap-2">
                        <Label for="new-page">Halaman</Label>
                        <select id="new-page" name="page" :class="selectClass" required>
                            <option value="" disabled selected>Pilih halaman</option>
                            <option v-for="page in pages" :key="page.value" :value="page.value">{{ page.label }}</option>
                        </select>
                        <InputError :message="errors.page" />
                    </div>
                    <div v-else class="grid gap-2">
                        <Label for="new-url">Alamat (path di aplikasi ini)</Label>
                        <Input id="new-url" name="url" placeholder="/tickets" maxlength="255" required />
                        <InputError :message="errors.url" />
                    </div>
                </template>

                <div v-if="mode === 'group' || parent === ''" class="grid gap-2">
                    <Label for="new-section">Judul bagian</Label>
                    <Input id="new-section" name="section" list="sections" maxlength="40" :default-value="sections[0] ?? 'Menu'" required />
                    <datalist id="sections"><option v-for="section in sections" :key="section" :value="section" /></datalist>
                    <InputError :message="errors.section" />
                </div>

                <Button type="submit" :disabled="processing">Simpan</Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
