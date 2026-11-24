<script setup lang="ts">
import Panel from '@/components/seo/Panel.vue';
import Stat from '@/components/seo/Stat.vue';
import TrendChart from '@/components/seo/TrendChart.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, number, path, percent } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row {
    label: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
}
const props = defineProps<{
    report: {
        totals: { clicks: number; impressions: number; ctr: number; position: number };
        trend: any[];
        queries: Row[];
        pages: Row[];
        opportunities: Row[];
        declines: { page_url: string; before: number; after: number }[];
        source: string | null;
    };
    range: { start: string; end: string; days: number };
    lastSync: any;
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const tab = ref<'queries' | 'pages' | 'opportunities'>('queries');
const rows = computed(() => props.report[tab.value]);
const setDays = (days: number) => router.get(route('analytics.index', w.value.id), { days }, { preserveState: true });
const sync = () => router.post(route('analytics.sync', w.value.id), {}, { preserveScroll: true });
</script>

<template>
    <SeoLayout
        title="Analytics"
        :description="`Search performance ${range.start} → ${range.end}. Source: ${report.source ?? 'none yet'}${report.source === 'mock' ? ' (fixture data — connect Search Console for real numbers)' : ''}.`"
    >
        <template #actions>
            <div class="flex rounded-md border">
                <button v-for="d in [7, 28, 90]" :key="d" :class="['px-3 py-1.5 text-sm', range.days === d && 'bg-accent']" @click="setDays(d)">
                    {{ d }}d
                </button>
            </div>
            <Button variant="outline" @click="sync"><RefreshCw /> Sync</Button>
        </template>
        <p v-if="lastSync" class="text-xs text-muted-foreground">Last sync {{ datetime(lastSync.started_at) }} — {{ lastSync.status }}</p>

        <div class="grid gap-4 sm:grid-cols-4">
            <Stat label="Clicks" :value="number(report.totals.clicks)" />
            <Stat label="Impressions" :value="number(report.totals.impressions)" />
            <Stat label="CTR" :value="percent(report.totals.ctr)" />
            <Stat label="Avg. position" :value="report.totals.position || '—'" hint="Impression-weighted" />
        </div>
        <Panel title="Trend"><TrendChart :points="report.trend" /></Panel>

        <Panel>
            <template #actions>
                <div class="flex rounded-md border">
                    <button
                        v-for="t in ['queries', 'pages', 'opportunities'] as const"
                        :key="t"
                        :class="['px-3 py-1.5 text-sm capitalize', tab === t && 'bg-accent']"
                        @click="tab = t"
                    >
                        {{ t }}
                    </button>
                </div>
            </template>
            <p v-if="tab === 'opportunities'" class="mb-2 text-xs text-muted-foreground">
                High impressions with comparatively low CTR. Review the title and snippet — don't rewrite automatically.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-4">{{ tab === 'pages' ? 'Page' : 'Query' }}</th>
                            <th class="pr-4">Clicks</th>
                            <th class="pr-4">Impr.</th>
                            <th class="pr-4">CTR</th>
                            <th>Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.label" class="border-t">
                            <td class="max-w-md truncate py-1.5 pr-4">{{ tab === 'pages' ? path(r.label) : r.label }}</td>
                            <td class="pr-4 tabular-nums">{{ number(r.clicks) }}</td>
                            <td class="pr-4 tabular-nums">{{ number(r.impressions) }}</td>
                            <td class="pr-4 tabular-nums">{{ percent(r.ctr) }}</td>
                            <td class="tabular-nums">{{ r.position }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="py-3 text-muted-foreground">No data. Click Sync.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Panel>

        <Panel
            title="Declines"
            description="Pages whose clicks dropped 30%+ between the first and second half of the range. Investigate before changing anything."
        >
            <ul class="space-y-1 text-sm">
                <li v-for="d in report.declines" :key="d.page_url" class="flex justify-between">
                    <span class="truncate">{{ path(d.page_url) }}</span
                    ><span class="tabular-nums text-red-600">{{ d.before }} → {{ d.after }}</span>
                </li>
                <li v-if="!report.declines.length" class="text-muted-foreground">No notable declines.</li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
