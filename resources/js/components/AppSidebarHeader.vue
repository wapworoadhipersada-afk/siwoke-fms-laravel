<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import HeaderUserMenu from '@/components/HeaderUserMenu.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { HorizontalDotsIcon, MenuTwoIcon, SearchIcon, XIcon } from '@/icons/layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

const { openMobile, toggleSidebar } = useSidebar();
const isApplicationMenuOpen = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const query = ref('');

// The search box looks things up in the ticket list (number, vendor reference, TID or merchant).
const search = (): void => {
    router.get(TicketController.index.url(), query.value.trim() === '' ? {} : { q: query.value.trim() });
};

const handleKeyDown = (event: KeyboardEvent): void => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault();
        searchInput.value?.focus();
    }
};

onMounted(() => document.addEventListener('keydown', handleKeyDown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeyDown));
</script>

<template>
    <header
        class="sticky top-0 z-50 flex w-full shrink-0 border-gray-200 bg-white xl:border-b dark:border-gray-800 dark:bg-gray-900"
    >
        <div class="flex grow flex-col items-center justify-between xl:flex-row xl:px-6">
            <div
                class="flex w-full items-center justify-between gap-2 border-b border-gray-200 px-3 py-3 sm:gap-4 xl:justify-normal xl:border-b-0 xl:px-0 xl:py-4 dark:border-gray-800"
            >
                <Button
                    variant="outline"
                    size="icon"
                    class="text-gray-500 lg:size-11 dark:text-gray-400"
                    aria-label="Buka atau tutup menu samping"
                    @click="toggleSidebar"
                >
                    <XIcon v-if="openMobile" class="size-6" />
                    <MenuTwoIcon v-else class="size-auto!" />
                </Button>

                <Link :href="dashboard()" class="xl:hidden">
                    <img width="154" height="32" class="dark:hidden" src="/images/logo/logo.svg" alt="Logo" />
                    <img width="154" height="32" class="hidden dark:block" src="/images/logo/logo-dark.svg" alt="Logo" />
                </Link>

                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Buka menu aplikasi"
                    class="text-gray-700 xl:hidden dark:text-gray-400"
                    @click="isApplicationMenuOpen = !isApplicationMenuOpen"
                >
                    <HorizontalDotsIcon />
                </Button>

                <div class="hidden xl:block">
                    <form @submit.prevent="search">
                        <div class="relative xl:w-107.5">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                                <SearchIcon class="size-5 text-gray-500 dark:text-gray-400" />
                            </span>
                            <input
                                ref="searchInput"
                                v-model="query"
                                type="text"
                                placeholder="Cari tiket, TID, merchant..."
                                class="h-11 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pr-14 pl-12 text-sm text-gray-800 shadow-theme-xs outline-none placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:bg-white/3 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                            />
                            <span
                                class="pointer-events-none absolute top-1/2 right-2.5 inline-flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-gray-200 bg-gray-50 px-1.75 py-[4.5px] text-xs tracking-[-0.2px] text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
                            >
                                <span>⌘</span><span>K</span>
                            </span>
                        </div>
                    </form>
                </div>
            </div>

            <div
                :class="
                    cn(
                        'flex w-full items-center justify-between gap-4 px-5 py-4 shadow-theme-md xl:flex xl:justify-end xl:px-0 xl:shadow-none',
                        isApplicationMenuOpen ? 'flex' : 'hidden',
                    )
                "
            >
                <div class="2xsm:gap-3 flex items-center gap-2">
                    <ThemeToggle />
                    <NotificationBell />
                </div>
                <HeaderUserMenu />
            </div>
        </div>
    </header>
</template>
