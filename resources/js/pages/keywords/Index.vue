<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import {
    Check,
    CheckCircle2,
    CircleSlash,
    Copy,
    Download,
    ExternalLink,
    FilePlus2,
    Hourglass,
    PenLine,
    Search,
    Sparkles,
    Trash2,
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Advice {
    group: 'todo' | 'planned' | 'done' | 'skip';
    action: string;
    summary: string;
    page_url: string | null;
    page_path: string | null;
    steps: string[];
    suggestion: { label: string; text: string; where: string } | null;
    outline?: string[] | null;
    can_brief: boolean;
    recommendation_id: string | null;
    content_url?: string;
}
interface Row {
    id: string;
    keyword: string;
    intent: string | null;
    opportunity_score: number | null;
    opportunity_json: Record<string, { points: number; why: string }> | null;
    sources_json: string[] | null;
    clusters: string[];
    advice: Advice;
}

const props = defineProps<{
    keywords: Paginated<Row>;
    filters: { q?: string; cluster?: string; show: string };
    counts: Record<string, number>;
    groups: Record<string, string>;
    clusters: { id: string; name: string; keywords_count: number }[];
    seeds: string[];
    defaults: { locale: string; language: string };
    lastRun: { status: string; started_at: string; error_message: string | null; result_json: any } | null;
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));

const f = reactive({ q: props.filters.q ?? '', cluster: props.filters.cluster ?? null, show: props.filters.show });
watchDebounced(
    f,
    () =>
        router.get(route('keywords.index', w.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), {
            preserveState: true,
            preserveScroll: true,
        }),
    { debounce: 300 },
);

const open = ref<string | null>(props.keywords.data[0]?.id ?? null);
const showWhy = ref<string | null>(null);
const showMore = ref(false);
const copied = ref<string | null>(null);
const copy = (id: string, text: string) => {
    navigator.clipboard?.writeText(text);
    copied.value = id;
    setTimeout(() => (copied.value = null), 1500);
};
const brief = (k: Row) => router.post(route('keywords.brief', [w.value.id, k.id]));
const remove = (k: Row) => router.delete(route('keywords.destroy', [w.value.id, k.id]), { preserveScroll: true });
const runAuto = () => router.post(route('automation.run', [w.value.id, 'keyword_research']), {}, { preserveScroll: true });

const research = useForm({ seeds: '', locale: props.defaults.locale, language: props.defaults.language });
const tone = (s: number | null) =>
    s === null ? 'bg-muted' : s >= 70 ? 'bg-emerald-100 text-emerald-800' : s >= 50 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600';
const icons = { todo: PenLine, planned: Hourglass, done: CheckCircle2, skip: CircleSlash } as const;
const tabs = computed(() => [...Object.entries(props.groups), ['all', 'All']] as [string, string][]);
const intentHelp: Record<string, string> = {
    commercial: 'comparing tools',
    transactional: 'ready to install or buy',
    informational: 'wants to learn',
    navigational: 'looking for a specific site',
};
</script>

<template>
    <SeoLayout
        title="Keywords"
        description="Keywords are what people type into Google. For each one, this page tells you exactly what to do: where, what to write, or that you can skip it."
    >
        <template #actions>
            <a :href="route('keywords.export', w.id)"
                ><Button variant="outline"><Download /> CSV</Button></a
            >
            <Button v-if="canWork" @click="runAuto"><Sparkles /> Find new keywords</Button>
        </template>

        <!-- Tabs: start with what needs doing -->
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="[key, label] in tabs"
                :key="key"
                :class="[
                    'rounded-full border px-3 py-1.5 text-sm',
                    f.show === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent',
                ]"
                @click="f.show = key"
            >
                {{ label }} <span class="ml-1 tabular-nums opacity-80">{{ counts[key] ?? 0 }}</span>
            </button>
            <Input v-model="f.q" placeholder="Search keywords" class="ml-auto h-9 w-56" />
        </div>
        <p class="text-sm text-muted-foreground">
            <template v-if="f.show === 'todo'"
                >Start at the top: these are the most valuable. Each one says <strong>where</strong> to make a change and <strong>what</strong> to
                write.</template
            >
            <template v-else-if="f.show === 'planned'">Content already planned or drafted for these. Finish them in Content Studio.</template>
            <template v-else-if="f.show === 'done'">Your website already targets these. Nothing to do.</template>
            <template v-else-if="f.show === 'skip'">Not worth your time: off-topic, too weak, or people looking for another website.</template>
        </p>

        <EmptyState
            v-if="!keywords.data.length"
            :title="f.show === 'todo' ? 'Nothing to do right now' : 'No keywords here'"
            :text="counts.all ? 'Check the other tabs.' : 'Click “Find new keywords”. Your website pages are used to work out your topics.'"
        />

        <div class="grid gap-3">
            <div v-for="k in keywords.data" :key="k.id" class="rounded-xl border bg-card">
                <button class="flex w-full items-start gap-3 p-4 text-left" @click="open = open === k.id ? null : k.id">
                    <component
                        :is="icons[k.advice.group]"
                        :class="[
                            'mt-0.5 h-5 w-5 shrink-0',
                            { todo: 'text-blue-600', planned: 'text-amber-600', done: 'text-emerald-600', skip: 'text-slate-400' }[k.advice.group],
                        ]"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">“{{ k.keyword }}”</span>
                        <span class="block text-sm">
                            <strong>{{ k.advice.action }}</strong>
                            <span v-if="k.advice.page_path"> · {{ k.advice.page_path }}</span>
                        </span>
                        <span class="block text-xs text-muted-foreground">{{ k.advice.summary }}</span>
                    </span>
                    <span
                        :class="['shrink-0 rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums', tone(k.opportunity_score)]"
                        :title="'Opportunity score (0–100): our estimate of how worthwhile this phrase is'"
                        >{{ k.opportunity_score ?? '—' }}</span
                    >
                </button>

                <div v-if="open === k.id" class="grid gap-3 border-t px-4 py-3 text-sm">
                    <!-- WHERE -->
                    <div v-if="k.advice.suggestion" class="grid gap-1">
                        <p class="text-xs font-medium uppercase text-muted-foreground">Where</p>
                        <p>
                            {{ k.advice.suggestion.where }}
                            <a
                                v-if="k.advice.page_url"
                                :href="k.advice.page_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-1 inline-flex items-center gap-1 text-primary hover:underline"
                                >open page <ExternalLink class="h-3 w-3"
                            /></a>
                        </p>
                    </div>
                    <!-- WHAT TO WRITE -->
                    <div v-if="k.advice.suggestion" class="grid gap-1">
                        <p class="text-xs font-medium uppercase text-muted-foreground">What to write — {{ k.advice.suggestion.label }}</p>
                        <div class="flex items-start justify-between gap-2 rounded-md border bg-muted/40 p-2">
                            <span class="font-medium">{{ k.advice.suggestion.text }}</span>
                            <button class="shrink-0 text-primary" title="Copy" @click="copy(k.id, k.advice.suggestion.text)">
                                <Check v-if="copied === k.id" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />
                            </button>
                        </div>
                        <ul v-if="k.advice.outline?.length" class="list-disc pl-5 text-muted-foreground">
                            <li v-for="o in k.advice.outline" :key="o">{{ o }}</li>
                        </ul>
                    </div>
                    <!-- HOW -->
                    <div v-if="k.advice.steps.length" class="grid gap-1">
                        <p class="text-xs font-medium uppercase text-muted-foreground">How</p>
                        <ol class="list-decimal space-y-0.5 pl-5">
                            <li v-for="s in k.advice.steps" :key="s">{{ s }}</li>
                        </ol>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="canWork && k.advice.can_brief" size="sm" @click="brief(k)"
                            ><FilePlus2 /> Create brief (AI writes the draft)</Button
                        >
                        <Link v-if="k.advice.content_url" :href="k.advice.content_url"><Button size="sm">Open Content Studio</Button></Link>
                        <Link v-if="k.advice.recommendation_id" :href="route('recommendations.index', w.id)"
                            ><Button size="sm" variant="outline">See in “What to update”</Button></Link
                        >
                        <Button size="sm" variant="ghost" @click="showWhy = showWhy === k.id ? null : k.id">Why this score?</Button>
                        <Button
                            v-if="canWork"
                            size="sm"
                            variant="ghost"
                            class="ml-auto text-muted-foreground"
                            title="Remove: not relevant to Growvia"
                            @click="remove(k)"
                            ><Trash2 /> Not relevant</Button
                        >
                    </div>

                    <div v-if="showWhy === k.id" class="rounded-md bg-muted/50 p-2 text-xs">
                        <p class="mb-1">
                            Searcher {{ intentHelp[k.intent ?? ''] ?? 'intent unknown' }} · found via {{ (k.sources_json ?? ['manual']).join(' + ') }}
                            <span v-if="k.clusters.length"> · topic: {{ k.clusters.join(', ') }}</span>
                        </p>
                        <table class="w-full max-w-xl">
                            <tr v-for="(v, key) in k.opportunity_json ?? {}" :key="key" class="border-t">
                                <td class="py-0.5 pr-2 capitalize">{{ key }}</td>
                                <td class="pr-2 tabular-nums">+{{ v.points }}</td>
                                <td class="text-muted-foreground">{{ v.why }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <Pagination :links="keywords.links" :total="keywords.total" />

        <Panel
            v-if="canWork"
            title="Optional: research a topic yourself"
            description="Autopilot already picks topics from your website every week. Use this only to explore something specific."
        >
            <template #actions
                ><Button size="sm" variant="ghost" @click="showMore = !showMore">{{ showMore ? 'Hide' : 'Show' }}</Button></template
            >
            <div v-if="showMore" class="grid gap-3">
                <form
                    class="grid gap-3"
                    @submit.prevent="
                        research.post(route('keywords.research', w.id), { preserveScroll: true, onSuccess: () => research.reset('seeds') })
                    "
                >
                    <TextArea v-model="research.seeds" :rows="3" placeholder="One topic per line, e.g. shopify upsell app" />
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="grid gap-1"><Label>Country</Label><Input v-model="research.locale" maxlength="2" class="w-20" /></div>
                        <div class="grid gap-1"><Label>Language</Label><Input v-model="research.language" class="w-20" /></div>
                        <Button :disabled="research.processing || !research.seeds"><Search /> Research</Button>
                    </div>
                </form>
                <p v-if="seeds.length" class="text-xs text-muted-foreground">Topics used so far: {{ seeds.join(', ') }}</p>
                <p v-if="lastRun" class="text-xs text-muted-foreground">
                    Last automatic research: <Badge :value="lastRun.status" /> {{ datetime(lastRun.started_at) }}
                    <span v-if="lastRun.result_json?.seeds?.length"> · topics: {{ lastRun.result_json.seeds.join(', ') }}</span>
                </p>
            </div>
        </Panel>
    </SeoLayout>
</template>
