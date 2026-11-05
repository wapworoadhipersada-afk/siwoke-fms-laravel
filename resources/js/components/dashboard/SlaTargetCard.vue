<script setup lang="ts">
import type { ApexOptions } from 'apexcharts';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { useChartTheme } from '@/composables/useChartTheme';
import { ArrowUpIcon } from '@/icons/layout';

const props = defineProps<{
    percent: number | null;
    days: number;
    createdToday: number;
    closedToday: number;
    avgHours: number | null;
}>();

const theme = useChartTheme();

const series = computed(() => [props.percent ?? 0]);

const options = computed<ApexOptions>(() => ({
    colors: ['#9CB9FF'],
    chart: { fontFamily: 'Outfit, sans-serif', type: 'radialBar', height: 330, sparkline: { enabled: true } },
    plotOptions: {
        radialBar: {
            startAngle: -90,
            endAngle: 90,
            hollow: { size: '80%' },
            track: { background: theme.track.value, strokeWidth: '100%', margin: 5 },
            dataLabels: {
                name: { show: false },
                value: {
                    fontSize: '36px',
                    fontWeight: '600',
                    offsetY: -35,
                    color: theme.text.value,
                    formatter: (value: number) => `${value}%`,
                },
            },
        },
    },
    fill: {
        type: 'gradient',
        gradient: {
            shade: 'light',
            type: 'horizontal',
            shadeIntensity: 0.5,
            gradientToColors: ['#465FFF'],
            inverseColors: false,
            opacityFrom: 1,
            opacityTo: 1,
            stops: [0, 100],
            colorStops: [
                { offset: 0, color: '#9CB9FF', opacity: 1 },
                { offset: 100, color: '#465FFF', opacity: 1 },
            ],
        },
    },
    stroke: { lineCap: 'round' },
    labels: ['SLA'],
}));

const message = computed(() => {
    if (props.percent === null) {
        return `Belum ada tiket yang selesai dalam ${props.days} hari terakhir.`;
    }

    return props.percent >= 90
        ? `Bagus. ${props.percent}% tiket selesai tepat waktu dalam ${props.days} hari terakhir.`
        : `Perlu perhatian: baru ${props.percent}% tiket selesai tepat waktu dalam ${props.days} hari terakhir.`;
});
</script>

<template>
    <div
        class="flex h-full flex-col justify-between rounded-2xl border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/3"
    >
        <div
            class="flex flex-1 flex-col justify-between rounded-2xl bg-white px-5 pt-5 pb-5 shadow-theme-md sm:px-6 sm:pt-6 sm:pb-11 dark:bg-gray-900"
        >
            <div>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Ketepatan waktu (SLA)</h3>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Tiket selesai sebelum batas waktunya</p>
            </div>

            <div>
                <div class="relative">
                    <div id="chartDarkStyle" class="max-h-45 overflow-hidden">
                        <VueApexCharts type="radialBar" :height="330" :options="options" :series="series" />
                    </div>
                </div>
                <p class="mx-auto mt-4.5 w-full max-w-95 text-center text-sm text-gray-500 sm:text-base dark:text-gray-400">
                    {{ message }}
                </p>
            </div>
        </div>

        <div class="flex items-center justify-center gap-5 px-6 py-3 sm:gap-8 sm:py-5">
            <div>
                <p class="mb-1 text-center text-xs text-gray-500 sm:text-sm dark:text-gray-400">Masuk hari ini</p>
                <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 sm:text-lg dark:text-white/90">
                    {{ createdToday }}
                    <ArrowUpIcon class="text-green-500" />
                </p>
            </div>
            <div class="h-7 w-px bg-gray-200 dark:bg-gray-800" />
            <div>
                <p class="mb-1 text-center text-xs text-gray-500 sm:text-sm dark:text-gray-400">Selesai hari ini</p>
                <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 sm:text-lg dark:text-white/90">
                    {{ closedToday }}
                    <ArrowUpIcon class="text-green-500" />
                </p>
            </div>
            <div class="h-7 w-px bg-gray-200 dark:bg-gray-800" />
            <div>
                <p class="mb-1 text-center text-xs text-gray-500 sm:text-sm dark:text-gray-400">Rata-rata (jam)</p>
                <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 sm:text-lg dark:text-white/90">
                    {{ avgHours ?? '-' }}
                </p>
            </div>
        </div>
    </div>
</template>
