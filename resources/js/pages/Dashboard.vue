<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import OwnershipNotice from '@/components/seo/OwnershipNotice.vue';
import Panel from '@/components/seo/Panel.vue';
import Stat from '@/components/seo/Stat.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, label, number, path } from '@/lib/format';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Loader2, Rocket } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

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
        top_keywords: { id: string; keyword: string; opportunity_score: number; intent: string; is_gap: boolean }[];
        prospects: number;
        existing_backlinks: number;
        autopilot: {
            enabled: boolean;
            ai_real: boolean;
            running: boolean;
            last_runs: { id: string; automation_key: string; status: string; started_at: string; error_message: string | null }[];
        };
        recent_activity: { id: string; action: string; created_at: string; user: { name: string } | null }[];
        todo: { text: string; why: string; route: string; priority: string }[];
    };
}>();

const page = usePage<SharedData>();
const website = computed(() => page.props.currentWebsite!);
const crawl = computed(() => props.overview.latest_crawl);
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));

const runAutopilot = () => router.post(route('automation.run', [website.value.id, 'autopilot']), {}, { preserveScroll: true });
const todoHref = (r: string) => (r === 'providers.index' ? route(r) : route(r, website.value.id));

let timer: number | undefined;
onMounted(() => (timer = window.setInterval(() => props.overview.autopilot.running && router.reload({ only: ['overview'] }), 6000)));
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <SeoLayout title="SEO Overview" :description="`Your plain-English SEO assistant for ${website.normalized_host}.`">
        <template #actions>
            <Button v-if="canWork" :disabled="overview.autopilot.running || !website.ownership_confirmed" @click="runAutopilot">
                <Loader2 v-if="overview.autopilot.running" class="animate-spin" /><Rocket v-else />
                {{ overview.autopilot.running ? 'Autopilot working…' : 'Run autopilot now' }}
            </Button>
        </template>

        <OwnershipNotice />

        <Panel title="Your to-do list" description="Do these in order. Each item explains why it matters.">
            <ol v-if="overview.todo.length" class="space-y-3">
                <li v-for="(t, i) in overview.todo" :key="t.text" class="flex gap-3">
                    <span
                        :class="[
                            'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                            t.priority === 'high'
                                ? 'bg-red-100 text-red-800'
                                : t.priority === 'medium'
                                  ? 'bg-amber-100 text-amber-800'
                                  : 'bg-slate-100 text-slate-700',
                        ]"
                        >{{ i + 1 }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <Link :href="todoHref(t.route)" class="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                            >{{ t.text }} <ArrowRight class="h-3.5 w-3.5"
                        /></Link>
                        <p class="text-xs text-muted-foreground">{{ t.why }}</p>
                    </div>
                </li>
            </ol>
            <p v-else class="text-sm text-muted-foreground">Nothing urgent. Autopilot keeps checking every week.</p>
        </Panel>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat
                label="Important problems"
                :value="overview.issues_by_severity.high"
                tone="text-red-600 dark:text-red-400"
                :hint="`${overview.issues_by_severity.medium} medium · ${overview.issues_by_severity.low} minor`"
            />
            <Stat label="Pages checked" :value="number(overview.pages_discovered)" :hint="`${number(overview.pages_ok)} load correctly`" />
            <Stat label="Keyword ideas" :value="number(overview.keywords)" hint="Searches people make in your topic" />
            <Stat
                label="Link opportunities"
                :value="number(overview.prospects)"
                :hint="`${overview.existing_backlinks} site(s) already link to you`"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel
                title="Best keyword opportunities"
                description="What to write about next. Score = our estimate, not search volume."
                class="lg:col-span-2"
            >
                <template #actions
                    ><Link :href="route('keywords.index', website.id)" class="text-sm text-primary hover:underline">All keywords</Link></template
                >
                <ul v-if="overview.top_keywords.length" class="divide-y">
                    <li v-for="k in overview.top_keywords" :key="k.id" class="flex items-center justify-between gap-2 py-2 text-sm">
                        <span>{{ k.keyword }} <span v-if="k.is_gap" class="ml-1 text-xs text-amber-700">no page yet</span></span>
                        <span class="flex items-center gap-2"
                            ><Badge :value="k.intent" /><span class="w-8 text-right font-semibold tabular-nums">{{ k.opportunity_score }}</span></span
                        >
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">Run autopilot to discover keyword ideas.</p>
            </Panel>

            <Panel title="Autopilot">
                <p class="text-sm">
                    <Badge :value="overview.autopilot.enabled ? 'connected' : 'partial'" />
                    {{ overview.autopilot.enabled ? 'On — runs every Monday' : 'Off' }}
                </p>
                <p class="mt-2 text-sm">
                    AI writer: <Badge :value="overview.autopilot.ai_real ? 'connected' : 'partial'" />
                    <Link v-if="!overview.autopilot.ai_real" :href="route('providers.index')" class="ml-1 text-xs text-primary hover:underline"
                        >connect a free one</Link
                    >
                </p>
                <ul class="mt-3 space-y-1 text-xs">
                    <li v-for="r in overview.autopilot.last_runs" :key="r.id" class="flex justify-between gap-2" :title="r.error_message ?? ''">
                        <span>{{ label(r.automation_key) }}</span>
                        <span class="flex items-center gap-1"
                            ><Badge :value="r.status" /><span class="text-muted-foreground">{{ datetime(r.started_at) }}</span></span
                        >
                    </li>
                </ul>
                <p v-if="crawl" class="mt-3 text-xs text-muted-foreground">
                    Last crawl: {{ crawl.status }} · {{ crawl.pages_crawled }} pages · {{ datetime(crawl.completed_at ?? crawl.created_at) }}
                </p>
            </Panel>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Problems to fix first" class="lg:col-span-2">
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
                <p v-else class="text-sm text-muted-foreground">No open problems found.</p>
            </Panel>
            <Panel title="Recent activity">
                <ul class="space-y-2 text-sm">
                    <li v-for="a in overview.recent_activity" :key="a.id" class="flex justify-between gap-2">
                        <span>{{ label(a.action.replace('.', ' ')) }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ datetime(a.created_at) }}</span>
                    </li>
                    <li v-if="!overview.recent_activity.length" class="text-muted-foreground">Nothing yet.</li>
                </ul>
            </Panel>
        </div>
    </SeoLayout>
</template>
