<script setup lang="ts">
import type { ApexOptions } from 'apexcharts';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { useChartTheme } from '@/composables/useChartTheme';

const props = defineProps<{ trend: { date: string; created: number; closed: number }[] }>();

const theme = useChartTheme();

const options = computed<ApexOptions>(() => ({
    colors: ['#465FFF'],
    chart: { fontFamily: 'Outfit, sans-serif', type: 'bar', height: 200, toolbar: { show: false } },
    plotOptions: {
        bar: {
            horizontal: false,
            columnWidth: '38%',
            borderRadius: 8,
            borderRadiusApplication: 'around',
            dataLabels: { position: 'top' },
        },
    },
    dataLabels: {
        enabled: true,
        formatter: (value: number) => (value > 0 ? String(value) : ''),
        offsetY: -20,
        style: { fontSize: '12px', fontFamily: 'Outfit, sans-serif', fontWeight: 400, colors: [theme.label.value] },
        dropShadow: { enabled: false },
    },
    stroke: { show: false },
    xaxis: {
        categories: props.trend.map((day) => `${day.date.slice(8, 10)}/${day.date.slice(5, 7)}`),
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { colors: theme.label.value, fontFamily: 'Outfit, sans-serif' } },
    },
    legend: { show: false },
    yaxis: { min: 0, tickAmount: 4, labels: { show: false } },
    grid: {
        borderColor: theme.grid.value,
        strokeDashArray: 0,
        padding: { left: -10, right: 0 },
        yaxis: { lines: { show: true } },
        xaxis: { lines: { show: false } },
    },
    fill: {
        type: 'gradient',
        gradient: {
            type: 'vertical',
            shadeIntensity: 1,
            opacityFrom: 1,
            opacityTo: 1,
            stops: [0, 100],
            colorStops: [
                { offset: 0, color: '#7592FF', opacity: 1 },
                { offset: 100, color: '#465FFF', opacity: 1 },
            ],
        },
    },
    tooltip: { x: { show: false }, y: { formatter: (value: number) => `${value} tiket` } },
}));

const series = computed(() => [{ name: 'Tiket masuk', data: props.trend.map((day) => day.created) }]);
</script>

<template>
    <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 pb-5 sm:px-6 sm:pt-6 sm:pb-5 dark:border-gray-800 dark:bg-white/3"
    >
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Tiket masuk per hari</h3>
        </div>

        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <div class="min-w-162.5 xl:min-w-full">
                <VueApexCharts type="bar" :height="200" :options="options" :series="series" />
            </div>
        </div>
    </div>
</template>
