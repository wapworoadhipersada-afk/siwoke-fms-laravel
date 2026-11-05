<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import IntegrationLogController from '@/actions/Modules/Integration/Http/Web/Controllers/IntegrationLogController';
import TicketController from '@/actions/Modules/Ticket/Http/Web/Controllers/TicketController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { BellIcon } from '@/icons/layout';

type Alert = { title: string; text: string; href: string };

const page = usePage();
const alerts = computed<Alert[]>(() => (page.props.alerts ?? []) as Alert[]);
const permissions = computed(() => page.props.auth.permissions);

// Kept separate from the shared props on purpose: this bell only shows what the server decided is worth interrupting for.
const hasAlerts = computed(() => alerts.value.length > 0);
const canSeeTickets = computed(() => permissions.value.includes('ticket.view'));
const canSeeLogs = computed(() => permissions.value.includes('integration.log.view'));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="icon-lg" class="relative text-gray-500 dark:text-gray-400" aria-label="Notifikasi">
                <span
                    v-if="hasAlerts"
                    class="absolute top-0.5 right-0 z-1 h-2 w-2 rounded-full bg-orange-400"
                >
                    <span class="absolute -z-1 inline-flex h-full w-full animate-ping rounded-full bg-orange-400 opacity-75" />
                </span>
                <BellIcon />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            :side-offset="12"
            class="w-[320px] rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg sm:w-[361px] dark:border-gray-800 dark:bg-gray-dark"
        >
            <div class="mb-3 flex items-center justify-between border-b border-gray-100 pb-3 dark:border-gray-800">
                <h5 class="text-lg font-semibold text-gray-800 dark:text-white/90">Notifikasi</h5>
            </div>

            <ul v-if="hasAlerts" class="custom-scrollbar flex max-h-[420px] flex-col overflow-y-auto">
                <li v-for="alert in alerts" :key="alert.title">
                    <Link
                        :href="alert.href"
                        class="flex gap-3 rounded-lg border-b border-gray-100 p-3 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-white/5"
                    >
                        <span class="block">
                            <span class="mb-1.5 block text-sm font-medium text-gray-800 dark:text-white/90">{{ alert.title }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ alert.text }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
            <p v-else class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                Tidak ada yang perlu perhatian saat ini.
            </p>

            <div class="mt-3 flex gap-2">
                <Link
                    v-if="canSeeTickets"
                    :href="TicketController.index()"
                    class="flex-1 rounded-lg border border-gray-300 bg-white p-3 text-center text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/3"
                >
                    Semua tiket
                </Link>
                <Link
                    v-if="canSeeLogs"
                    :href="IntegrationLogController.index()"
                    class="flex-1 rounded-lg border border-gray-300 bg-white p-3 text-center text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/3"
                >
                    Log integrasi
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
