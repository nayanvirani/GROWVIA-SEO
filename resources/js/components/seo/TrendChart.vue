<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ points: { date: string; clicks: number; impressions: number }[] }>();

const W = 600;
const H = 140;
const paths = computed(() => {
    const pts = props.points;
    if (pts.length < 2) return null;
    const maxC = Math.max(1, ...pts.map((p) => p.clicks));
    const maxI = Math.max(1, ...pts.map((p) => p.impressions));
    const x = (i: number) => (i / (pts.length - 1)) * W;
    const line = (key: 'clicks' | 'impressions', max: number) =>
        pts.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${(H - (p[key] / max) * (H - 10) - 5).toFixed(1)}`).join(' ');
    return { clicks: line('clicks', maxC), impressions: line('impressions', maxI), maxC, maxI };
});
</script>

<template>
    <div v-if="paths">
        <svg :viewBox="`0 0 ${W} ${H}`" class="h-36 w-full" preserveAspectRatio="none" role="img" aria-label="Clicks and impressions trend">
            <path
                :d="paths.impressions"
                fill="none"
                stroke="currentColor"
                class="text-violet-400"
                stroke-width="2"
                vector-effect="non-scaling-stroke"
            />
            <path
                :d="paths.clicks"
                fill="none"
                stroke="currentColor"
                class="text-blue-600 dark:text-blue-400"
                stroke-width="2.5"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <div class="mt-2 flex justify-between text-xs text-muted-foreground">
            <span>{{ points[0].date }}</span>
            <span class="flex gap-4">
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-blue-600" />Clicks (max {{ paths.maxC }})</span>
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-violet-400" />Impressions (max {{ paths.maxI }})</span>
            </span>
            <span>{{ points[points.length - 1].date }}</span>
        </div>
    </div>
    <p v-else class="text-sm text-muted-foreground">Not enough data yet.</p>
</template>
