<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import { ChevronDownIcon, GearIcon, LogOutIcon, UserCircleIcon } from '@/icons/layout';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();
const isOpen = ref(false);

const itemClass =
    'flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300';

const handleLogout = (): void => {
    router.flushAll();
};
</script>

<template>
    <DropdownMenu :open="isOpen" @update:open="isOpen = $event">
        <DropdownMenuTrigger
            class="flex items-center text-gray-700 outline-none dark:text-gray-400"
            data-test="sidebar-menu-button"
        >
            <span
                class="me-3 flex h-11 w-11 items-center justify-center overflow-hidden rounded-lg bg-brand-50 text-sm font-semibold text-brand-500 dark:bg-brand-500/15 dark:text-brand-400"
            >
                {{ getInitials(user.name) }}
            </span>
            <span class="me-1 block max-w-40 truncate text-sm font-medium">{{ user.name }}</span>
            <ChevronDownIcon
                :class="cn('size-5 text-gray-500 duration-200 dark:text-gray-400', isOpen ? 'rotate-180' : 'rotate-0')"
            />
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            :side-offset="12"
            class="w-65 rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark"
        >
            <div class="px-3 py-2">
                <span class="block truncate text-sm font-medium text-gray-700 dark:text-gray-400">
                    {{ user.name }}
                </span>
                <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ user.email }}
                </span>
            </div>

            <DropdownMenuSeparator class="my-1 bg-gray-200 dark:bg-gray-800" />

            <DropdownMenuItem as-child class="p-0 focus:bg-transparent">
                <Link :href="edit()" :class="itemClass" prefetch>
                    <UserCircleIcon class="size-6" />
                    Edit profil
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child class="p-0 focus:bg-transparent">
                <Link :href="edit()" :class="itemClass" prefetch>
                    <GearIcon class="size-6" />
                    Pengaturan akun
                </Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator class="my-1 bg-gray-200 dark:bg-gray-800" />

            <DropdownMenuItem as-child class="p-0 focus:bg-transparent">
                <Link
                    :href="logout()"
                    as="button"
                    :class="cn(itemClass, 'text-error-600 hover:text-error-600 dark:text-error-500')"
                    data-test="logout-button"
                    @click="handleLogout"
                >
                    <LogOutIcon class="size-6" />
                    Keluar
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
