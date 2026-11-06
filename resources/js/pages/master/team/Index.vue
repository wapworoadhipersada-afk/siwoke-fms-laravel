<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

type Person = {
    ulid: string;
    name: string;
    user_code: string | null;
    photo_url: string | null;
    area_count: number;
    places: string[];
};
type Leader = Person & { engineers: Person[] };
type Coordinator = Person & { leaders: Leader[] };
type Team = {
    vendor: { code: string; name: string };
    coordinators: Coordinator[];
    loose_leaders: Leader[];
    loose_engineers: Person[];
};

defineProps<{ teams: Team[] }>();

</script>

<template>
    <Head title="Tim" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Tim</h1>
            <p class="text-sm text-muted-foreground">
                Per vendor: koordinator memegang lokasi, menaungi leader, dan
                leader menaungi engineer. Susunan ini dibaca dari area yang
                ditugaskan ke tiap orang. Ubah lewat Atur area dan vendor di
                data orangnya.
            </p>
        </div>

        <p v-if="teams.length === 0" class="text-sm text-muted-foreground">
            Belum ada vendor.
        </p>

        <section
            v-for="team in teams"
            :key="team.vendor.code"
            class="space-y-4 rounded-xl border bg-card p-5"
        >
            <h2 class="text-lg font-semibold">
                {{ team.vendor.name }}
                <span class="font-mono text-sm text-muted-foreground">{{ team.vendor.code }}</span>
            </h2>

            <p
                v-if="team.coordinators.length === 0 && team.loose_leaders.length === 0 && team.loose_engineers.length === 0"
                class="text-sm text-muted-foreground"
            >
                Belum ada koordinator, leader, atau engineer yang memegang area vendor ini.
            </p>

            <div v-for="coordinator in team.coordinators" :key="coordinator.ulid" class="rounded-lg border p-4">
                <p class="font-medium">
                    Koordinator: {{ coordinator.name }}
                    <span v-if="coordinator.user_code" class="font-mono text-xs text-muted-foreground">{{ coordinator.user_code }}</span>
                </p>
                <p class="text-xs text-muted-foreground">
                    Memegang {{ coordinator.places.join(', ') }} ({{ coordinator.area_count }} area)
                </p>

                <div class="mt-3 space-y-3 border-s ps-4">
                    <p v-if="coordinator.leaders.length === 0" class="text-sm text-muted-foreground">Belum ada leader di bawahnya.</p>
                    <div v-for="leader in coordinator.leaders" :key="leader.ulid">
                        <p class="text-sm font-medium">
                            Leader: {{ leader.name }}
                            <span v-if="leader.user_code" class="font-mono text-xs text-muted-foreground">{{ leader.user_code }}</span>
                            <span class="text-xs font-normal text-muted-foreground"> · {{ leader.places.join(', ') }}</span>
                        </p>
                        <ul class="mt-1 ms-4 list-disc text-sm">
                            <li v-for="engineer in leader.engineers" :key="engineer.ulid">
                                {{ engineer.name }}
                                <span v-if="engineer.user_code" class="font-mono text-xs text-muted-foreground">{{ engineer.user_code }}</span>
                                <span class="text-xs text-muted-foreground"> · {{ engineer.places.join(', ') }}</span>
                            </li>
                            <li v-if="leader.engineers.length === 0" class="list-none text-muted-foreground">Belum ada engineer.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div v-if="team.loose_leaders.length > 0" class="rounded-lg border border-dashed p-4">
                <p class="text-sm font-medium">Leader tanpa koordinator</p>
                <div v-for="leader in team.loose_leaders" :key="leader.ulid" class="mt-2 text-sm">
                    {{ leader.name }} <span class="text-xs text-muted-foreground">· {{ leader.places.join(', ') }}</span>
                    <ul class="ms-4 list-disc">
                        <li v-for="engineer in leader.engineers" :key="engineer.ulid">{{ engineer.name }}</li>
                    </ul>
                </div>
            </div>

            <div v-if="team.loose_engineers.length > 0" class="rounded-lg border border-dashed p-4">
                <p class="text-sm font-medium">Engineer tanpa leader</p>
                <ul class="mt-1 ms-4 list-disc text-sm">
                    <li v-for="engineer in team.loose_engineers" :key="engineer.ulid">
                        {{ engineer.name }}
                        <span class="text-xs text-muted-foreground">· {{ engineer.places.join(', ') || 'belum punya area' }}</span>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
