<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { ChevronLeftIcon } from '@/icons/layout';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
        <div class="relative flex h-screen w-full flex-col justify-center sm:p-0 lg:flex-row dark:bg-gray-900">
            <div class="flex w-full flex-1 flex-col lg:w-1/2">
                <div class="mx-auto mb-5 w-full max-w-md sm:pt-10">
                    <Link
                        :href="home()"
                        class="inline-flex items-center text-sm text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    >
                        <ChevronLeftIcon />
                        Kembali ke beranda
                    </Link>
                </div>

                <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center">
                    <div>
                        <div class="mb-5 sm:mb-8">
                            <h1 class="mb-2 text-title-sm font-semibold text-gray-800 sm:text-title-md dark:text-white/90">
                                {{ title }}
                            </h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ description }}
                            </p>
                        </div>

                        <slot />
                    </div>
                </div>
            </div>

            <div class="relative hidden h-full w-full items-center justify-center bg-brand-950 lg:grid lg:w-1/2 dark:bg-white/5">
                <div class="z-1 flex h-full items-center justify-center">
                    <div>
                        <div class="absolute top-0 right-1 -z-1 w-full max-w-62.5 xl:max-w-112.5">
                            <img src="/images/shape/grid-01.svg" alt="" />
                        </div>
                        <div class="absolute bottom-0 start-0 -z-1 w-full max-w-62.5 rotate-180 xl:max-w-112.5">
                            <img src="/images/shape/grid-01.svg" alt="" />
                        </div>
                    </div>

                    <div class="flex max-w-xs flex-col items-center">
                        <Link :href="home()" class="mb-4 block">
                            <img width="231" height="48" src="/images/logo/auth-logo.svg" alt="Logo" />
                        </Link>
                        <p class="text-center text-gray-400 dark:text-white/60">
                            Kelola tiket lapangan, aset, dan Berita Acara EDC dalam satu tempat.
                        </p>
                    </div>
                </div>
            </div>

            <div class="fixed end-6 bottom-6 z-50 hidden sm:block">
                <ThemeToggle />
            </div>
        </div>
    </div>
</template>
