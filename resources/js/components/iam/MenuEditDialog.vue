<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const props = defineProps<{
    menu: MenuEntry;
    groups: MenuEntry[];
    icons: { value: string; label: string }[];
    roleId: number | null;
}>();

const open = ref(false);
const isPage = computed(() => props.menu.route_name !== null);
const selectClass = 'h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm">Ubah</Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="NavigationController.update.form(menu.id)"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                :on-success="() => (open = false)"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-2">
                    <DialogTitle>Ubah menu</DialogTitle>
                    <DialogDescription>
                        Tujuan halaman dan izin yang diperlukan tidak dapat diubah di sini. Menyembunyikan menu tidak
                        mencabut akses; halaman tetap memeriksa izin sendiri.
                    </DialogDescription>
                </DialogHeader>

                <input v-if="roleId" type="hidden" name="role" :value="roleId" />

                <div class="grid gap-2">
                    <Label :for="`label-${menu.id}`">Nama</Label>
                    <Input :id="`label-${menu.id}`" name="label" :default-value="menu.label" maxlength="60" required />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`icon-${menu.id}`">Ikon</Label>
                    <select :id="`icon-${menu.id}`" name="icon" :class="selectClass" :value="menu.icon ?? ''">
                        <option value="">(tanpa ikon)</option>
                        <option v-for="icon in icons" :key="icon.value" :value="icon.value" :selected="icon.value === menu.icon">
                            {{ icon.label }}
                        </option>
                    </select>
                    <InputError :message="errors.icon" />
                </div>

                <div v-if="menu.parent_id === null" class="grid gap-2">
                    <Label :for="`section-${menu.id}`">Judul bagian</Label>
                    <Input :id="`section-${menu.id}`" name="section" :default-value="menu.section" maxlength="40" />
                </div>

                <div v-if="isPage" class="grid gap-2">
                    <Label :for="`parent-${menu.id}`">Berada di grup</Label>
                    <select :id="`parent-${menu.id}`" name="parent_id" :class="selectClass">
                        <option value="">(tingkat atas, tanpa grup)</option>
                        <option v-for="group in groups" :key="group.id" :value="group.id" :selected="group.id === menu.parent_id">
                            {{ group.label }}
                        </option>
                    </select>
                    <InputError :message="errors.parent_id" />
                </div>

                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="hidden" name="is_active" value="0" />
                    <input type="checkbox" name="is_active" value="1" :checked="menu.is_active" class="size-4 rounded border-gray-300 accent-primary" />
                    Tampilkan di menu
                </label>

                <Button type="submit" :disabled="processing">Simpan</Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
