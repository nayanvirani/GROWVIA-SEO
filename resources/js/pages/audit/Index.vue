<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { date, datetime, path } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Play, Sparkles } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

interface Rule {
    severity: string;
    heuristic: boolean;
    label: string;
    why: string;
    recommendation: string;
}
const props = defineProps<{
    issues: Paginated<any>;
    filters: { severity?: string; status: string; rule?: string };
    rules: Record<string, Rule>;
    ruleCounts: Record<string, number>;
    crawlRuns: any[];
}>();

const page = usePage<SharedData>();
const website = computed(() => page.props.currentWebsite!);
const running = computed(() => props.crawlRuns.some((r) => ['queued', 'running'].includes(r.status)));
const f = reactive({ severity: props.filters.severity ?? null, status: props.filters.status, rule: props.filters.rule ?? null });
watch(f, () =>
    router.get(route('audit.index', website.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), {
        preserveState: true,
        preserveScroll: true,
    }),
);

const open = ref<string | null>(null);
const crawl = () => router.post(route('audit.crawl', website.value.id), {}, { preserveScroll: true });
const setStatus = (id: string, status: string) =>
    router.patch(route('audit.issues.update', [website.value.id, id]), { status }, { preserveScroll: true });
const explaining = ref<string | null>(null);
const explain = (id: string) => {
    explaining.value = id;
    router.post(route('audit.issues.explain', [website.value.id, id]), {}, { preserveScroll: true, onFinish: () => (explaining.value = null) });
};
const evidence = (e: Record<string, unknown>) =>
    Object.entries(e ?? {}).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length));

let timer: number | undefined;
onMounted(() => {
    timer = window.setInterval(() => running.value && router.reload({ only: ['crawlRuns', 'issues', 'ruleCounts'] }), 5000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <SeoLayout
        title="Website audit"
        description="Technical findings from the latest crawl. Heuristic checks are review prompts, not search-engine rules."
    >
        <template #actions>
            <Button :disabled="running || !website.ownership_confirmed" @click="crawl"
                ><Play /> {{ running ? 'Crawl running…' : 'Re-run audit' }}</Button
            >
        </template>

        <Panel title="Crawl history">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-4">Started</th>
                            <th class="pr-4">Status</th>
                            <th class="pr-4">Trigger</th>
                            <th class="pr-4">Crawled / found</th>
                            <th class="pr-4">Open issues</th>
                            <th>Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in crawlRuns" :key="r.id" class="border-t">
                            <td class="py-1.5 pr-4">{{ datetime(r.started_at ?? r.created_at) }}</td>
                            <td class="pr-4"><Badge :value="r.status" /></td>
                            <td class="pr-4 capitalize">{{ r.trigger }}</td>
                            <td class="pr-4 tabular-nums">{{ r.pages_crawled }} / {{ r.pages_found }}</td>
                            <td class="pr-4 tabular-nums">{{ r.issues_found }}</td>
                            <td class="max-w-md truncate text-xs text-muted-foreground" :title="JSON.stringify(r.error_summary)">
                                {{
                                    Array.isArray(r.error_summary)
                                        ? `${r.error_summary.length} (e.g. ${r.error_summary[0]?.error})`
                                        : (r.error_summary?.message ?? '—')
                                }}
                            </td>
                        </tr>
                        <tr v-if="!crawlRuns.length">
                            <td colspan="6" class="py-3 text-muted-foreground">No crawls yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Panel>

        <div class="grid gap-4 lg:grid-cols-4">
            <Panel title="Issue types" class="lg:col-span-1">
                <ul class="space-y-1 text-sm">
                    <li>
                        <button :class="['w-full rounded px-2 py-1 text-left hover:bg-accent', !f.rule && 'bg-accent']" @click="f.rule = null">
                            All types
                        </button>
                    </li>
                    <li v-for="(count, key) in ruleCounts" :key="key">
                        <button
                            :class="['flex w-full justify-between rounded px-2 py-1 text-left hover:bg-accent', f.rule === key && 'bg-accent']"
                            @click="f.rule = key"
                        >
                            <span>{{ rules[key]?.label ?? key }}</span
                            ><span class="tabular-nums text-muted-foreground">{{ count }}</span>
                        </button>
                    </li>
                </ul>
            </Panel>

            <Panel title="Issues" class="lg:col-span-3">
                <template #actions>
                    <SelectField v-model="f.severity" :options="['high', 'medium', 'low', 'info']" placeholder="All severities" class="h-8 w-36" />
                    <SelectField v-model="f.status" :options="['open', 'dismissed', 'resolved']" class="h-8 w-32" />
                </template>
                <EmptyState v-if="!issues.data.length" title="No issues match" text="Run a crawl, or change the filters." />
                <ul class="divide-y">
                    <li v-for="i in issues.data" :key="i.id" class="py-3">
                        <button class="flex w-full items-start justify-between gap-3 text-left" @click="open = open === i.id ? null : i.id">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ rules[i.rule_key]?.label }}
                                    <span v-if="rules[i.rule_key]?.heuristic" class="ml-1 text-xs font-normal text-muted-foreground"
                                        >(heuristic)</span
                                    >
                                </p>
                                <p class="truncate text-xs text-muted-foreground">{{ path(i.page?.canonical_url) }}</p>
                            </div>
                            <Badge :value="i.severity" />
                        </button>
                        <div v-if="open === i.id" class="mt-3 grid gap-3 rounded-lg bg-muted/50 p-3 text-sm">
                            <div>
                                <span class="font-medium">Problem:</span> {{ rules[i.rule_key]?.label }} on
                                <a :href="i.page?.canonical_url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{
                                    path(i.page?.canonical_url)
                                }}</a>
                            </div>
                            <div><span class="font-medium">Why it matters:</span> {{ rules[i.rule_key]?.why }}</div>
                            <div>
                                <span class="font-medium">Evidence:</span>
                                <ul class="mt-1 space-y-0.5 text-xs">
                                    <li v-for="[k, v] in evidence(i.evidence_json)" :key="k" class="break-all">
                                        <span class="text-muted-foreground">{{ k }}:</span> {{ typeof v === 'object' ? JSON.stringify(v) : v }}
                                    </li>
                                </ul>
                            </div>
                            <div><span class="font-medium">Suggested action:</span> {{ i.recommendation }}</div>
                            <div v-if="i.ai_explanation" class="whitespace-pre-line rounded border bg-background p-2">
                                <span class="font-medium">AI explanation:</span> {{ i.ai_explanation }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Confidence: high (directly detected) · First seen {{ date(i.first_seen_at) }} · Last seen {{ date(i.last_seen_at) }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link :href="route('audit.page', [website.id, i.page_id])"
                                    ><Button size="sm" variant="outline">Page detail</Button></Link
                                >
                                <Button size="sm" variant="outline" :disabled="explaining === i.id" @click="explain(i.id)"
                                    ><Sparkles /> Explain</Button
                                >
                                <Button v-if="i.status === 'open'" size="sm" variant="ghost" @click="setStatus(i.id, 'dismissed')">Dismiss</Button>
                                <Button v-else-if="i.status === 'dismissed'" size="sm" variant="ghost" @click="setStatus(i.id, 'open')"
                                    >Reopen</Button
                                >
                            </div>
                        </div>
                    </li>
                </ul>
                <Pagination :links="issues.links" :total="issues.total" />
            </Panel>
        </div>
    </SeoLayout>
</template>
