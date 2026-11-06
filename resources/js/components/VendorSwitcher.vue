<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import VendorFocusController from '@/actions/Modules/Master/Http/Web/Controllers/VendorFocusController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type VendorOption = { ulid: string; code: string; name: string };

const page = usePage();
const focus = computed(
    () =>
        (page.props.vendorFocus ?? { current: null, options: [] }) as {
            current: string | null;
            options: VendorOption[];
        },
);

const current = computed(() =>
    focus.value.options.find((option) => option.ulid === focus.value.current),
);

// Switching changes only what lists and totals show; it never changes what the person may open.
const choose = (ulid: string | null): void => {
    router.put(
        VendorFocusController.update.url(),
        { vendor: ulid ?? '' },
        { preserveScroll: true, preserveState: false },
    );
};
</script>

<template>
    <DropdownMenu v-if="focus.options.length > 0">
        <DropdownMenuTrigger as-child>
            <Button variant="outline" class="gap-2" data-test="vendor-switcher">
                <Building2 class="size-4" />
                <span class="max-w-40 truncate">
                    {{ current ? current.name : 'Semua vendor' }}
                </span>
                <ChevronsUpDown class="size-4 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-56">
            <DropdownMenuLabel>Fokus vendor</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="choose(null)">
                <Check class="size-4" :class="current ? 'opacity-0' : ''" />
                Semua vendor
            </DropdownMenuItem>
            <DropdownMenuItem
                v-for="vendor in focus.options"
                :key="vendor.ulid"
                @select="choose(vendor.ulid)"
            >
                <Check
                    class="size-4"
                    :class="current?.ulid === vendor.ulid ? '' : 'opacity-0'"
                />
                {{ vendor.code }} — {{ vendor.name }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
