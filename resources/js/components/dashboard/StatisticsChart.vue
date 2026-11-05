<script setup lang="ts">
import type { ApexOptions } from 'apexcharts';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { useChartTheme } from '@/composables/useChartTheme';

const props = defineProps<{ trend: { date: string; created: number; closed: number }[] }>();

const theme = useChartTheme();
const categories = computed(() => props.trend.map((day) => `${day.date.slice(8, 10)}/${day.date.slice(5, 7)}`));

const series = computed(() => [
    { name: 'Masuk', data: props.trend.map((day) => day.created) },
    { name: 'Selesai', data: props.trend.map((day) => day.closed) },
]);

const options = computed<ApexOptions>(() => ({
    legend: { show: false },
    colors: ['#465FFF', '#9CB9FF'],
    chart: { fontFamily: 'Outfit, sans-serif', height: 310, type: 'area', toolbar: { show: false } },
    stroke: { curve: 'straight', width: [2, 2] },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.55, opacityTo: 0 } },
    markers: { size: 0, strokeColors: '#fff', strokeWidth: 2, hover: { size: 6 } },
    grid: { xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
    dataLabels: { enabled: false },
    tooltip: { enabled: true, shared: true, intersect: false },
    xaxis: {
        type: 'category',
        categories: categories.value,
        axisBorder: { show: false },
        axisTicks: { show: false },
        tooltip: { enabled: false },
        labels: { style: { fontSize: '12px', fontFamily: 'Outfit, sans-serif', colors: theme.axis.value } },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (value: number) => String(Math.round(value)),
            style: { fontSize: '12px', fontFamily: 'Outfit, sans-serif', colors: theme.axis.value },
        },
    },
}));
</script>

<template>
    <div
        class="rounded-2xl border border-gray-200 bg-white px-5 pt-5 pb-5 sm:px-6 sm:pt-6 dark:border-gray-800 dark:bg-white/3"
    >
        <div class="mb-6 flex flex-col gap-5 sm:flex-row sm:justify-between">
            <div class="w-full">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Statistik tiket</h3>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Tiket masuk dan selesai per hari</p>
            </div>
            <div class="flex items-center gap-4 text-theme-sm text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-brand-500" /> Masuk</span>
                <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-brand-300" /> Selesai</span>
            </div>
        </div>

        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <div class="min-w-250 xl:min-w-full">
                <VueApexCharts type="area" :height="310" :options="options" :series="series" />
            </div>
        </div>
    </div>
</template>
