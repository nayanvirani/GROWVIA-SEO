<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import Stat from '@/components/seo/Stat.vue';
import TrendChart from '@/components/seo/TrendChart.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, label, number, path } from '@/lib/format';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Play } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    overview: {
        latest_crawl: any;
        pages_discovered: number;
        pages_ok: number;
        issues_by_severity: Record<'high' | 'medium' | 'low' | 'info', number>;
        priority_issues: { id: string; rule_key: string; severity: string; label: string; url: string; recommendation: string }[];
        pending_drafts: number;
        approved_drafts: number;
        keywords: number;
        prospects: number;
        traffic_trend: { date: string; clicks: number; impressions: number }[];
        recent_activity: { id: string; action: string; created_at: string; user: { name: string } | null; metadata_json: any }[];
        next_actions: { text: string; route: string }[];
    };
}>();

const page = usePage<SharedData>();
const website = computed(() => page.props.currentWebsite!);
const crawl = computed(() => props.overview.latest_crawl);
const running = computed(() => crawl.value && ['queued', 'running'].includes(crawl.value.status));

const startCrawl = () => router.post(route('audit.crawl', website.value.id), {}, { preserveScroll: true });
</script>

<template>
    <SeoLayout
        title="SEO Overview"
        :description="`What to look at next for ${website.normalized_host}. Findings are diagnostics from your own crawl — not ranking guarantees.`"
    >
        <template #actions>
            <Button :disabled="running || !website.ownership_confirmed" @click="startCrawl"
                ><Play /> {{ running ? 'Crawl running…' : 'Run crawl' }}</Button
            >
        </template>

        <div v-if="overview.next_actions.length" class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
            <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-100">Recommended next steps</h2>
            <ul class="mt-2 space-y-1">
                <li v-for="a in overview.next_actions" :key="a.text" class="flex items-center gap-2 text-sm text-blue-900 dark:text-blue-100">
                    <ArrowRight class="h-4 w-4 shrink-0" />
                    <Link :href="route(a.route, website.id)" class="hover:underline">{{ a.text }}</Link>
                </li>
            </ul>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat
                label="Pages discovered"
                :value="number(overview.pages_discovered)"
                :hint="`${number(overview.pages_ok)} returned 2xx. Discovered ≠ indexed.`"
            />
            <Stat
                label="High-severity issues"
                :value="overview.issues_by_severity.high"
                tone="text-red-600 dark:text-red-400"
                :hint="`${overview.issues_by_severity.medium} medium · ${overview.issues_by_severity.low} low · ${overview.issues_by_severity.info} info`"
            />
            <Stat label="Drafts awaiting review" :value="overview.pending_drafts" :hint="`${overview.approved_drafts} approved for export`" />
            <Stat
                label="Keywords / prospects"
                :value="`${number(overview.keywords)} / ${number(overview.prospects)}`"
                hint="Tracked keywords and backlink prospects"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Priority issues" description="Fix high-severity items first." class="lg:col-span-2">
                <template #actions
                    ><Link :href="route('audit.index', website.id)" class="text-sm text-primary hover:underline">View all</Link></template
                >
                <ul v-if="overview.priority_issues.length" class="divide-y">
                    <li
                        v-for="i in overview.priority_issues"
                        :key="i.id"
                        class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ i.label }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ path(i.url) }} — {{ i.recommendation }}</p>
                        </div>
                        <Badge :value="i.severity" />
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">No open issues. Run a crawl to check the site.</p>
            </Panel>

            <Panel title="Latest crawl">
                <dl v-if="crawl" class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Status</dt>
                        <dd><Badge :value="crawl.status" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Pages crawled</dt>
                        <dd>{{ crawl.pages_crawled }} / found {{ crawl.pages_found }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Open issues</dt>
                        <dd>{{ crawl.issues_found }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Started</dt>
                        <dd>{{ datetime(crawl.started_at ?? crawl.created_at) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Finished</dt>
                        <dd>{{ datetime(crawl.completed_at) }}</dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-muted-foreground">
                    No crawl yet.
                    <span v-if="!website.ownership_confirmed"
                        >Confirm ownership in
                        <Link :href="route('websites.settings', website.id)" class="text-primary hover:underline">settings</Link> first.</span
                    >
                </p>
            </Panel>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Search traffic (last 30 days)" description="From Search Console, or mock data until it is connected." class="lg:col-span-2">
                <TrendChart :points="overview.traffic_trend" />
            </Panel>
            <Panel title="Recent activity">
                <ul class="space-y-2 text-sm">
                    <li v-for="a in overview.recent_activity" :key="a.id" class="flex justify-between gap-2">
                        <span
                            >{{ label(a.action.replace('.', ' '))
                            }}<span v-if="a.user" class="text-muted-foreground"> · {{ a.user.name }}</span></span
                        >
                        <span class="shrink-0 text-xs text-muted-foreground">{{ datetime(a.created_at) }}</span>
                    </li>
                    <li v-if="!overview.recent_activity.length" class="text-muted-foreground">Nothing yet.</li>
                </ul>
            </Panel>
        </div>
    </SeoLayout>
</template>
