<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
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

// A button that opens a small form asking for a mandatory reason before a consequential action.
// `form` is the Wayfinder form definition of the endpoint (`Controller.action.form(id)`).
defineProps<{
    form: { action: string; method: 'get' | 'post' | 'put' | 'patch' | 'delete' };
    title: string;
    description: string;
    label: string;
    confirm?: string;
    destructive?: boolean;
}>();

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                :class="destructive ? 'text-destructive' : ''"
            >
                {{ label }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="form"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                :on-success="() => (open = false)"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="reason">Alasan</Label>
                    <Input id="reason" name="reason" required />
                    <InputError :message="errors.reason" />
                    <InputError :message="errors.status" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="secondary" @click="open = false"
                        >Batal</Button
                    >
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="processing"
                    >
                        {{ confirm ?? label }}
                    </Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
