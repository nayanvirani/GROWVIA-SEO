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
    Loader2,
    PenLine,
    Search,
    Sparkles,
    Trash2,
    Wand2,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

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
    page_id?: string;
    write_kind: 'section' | 'title' | null;
    placement: { after: string | null; before: string | null; text: string } | null;
    content_status: 'writing' | 'ready' | 'failed' | null;
    content_error?: string | null;
    content: {
        kind: 'section' | 'title';
        heading?: string;
        paragraphs?: string[];
        bullets?: string[];
        faq?: { question: string; answer: string } | null;
        title?: string;
        meta_description?: string;
    } | null;
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
const write = (k: Row) =>
    router.post(
        route('keywords.write', [w.value.id, k.id]),
        { kind: k.advice.write_kind, page_id: k.advice.page_id },
        { preserveScroll: true, preserveState: true },
    );

/** The written section as plain text and as HTML, ready to paste into a website editor. */
const esc = (t: string) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const asText = (c: NonNullable<Advice['content']>) =>
    [c.heading, ...(c.paragraphs ?? []), ...(c.bullets ?? []).map((b) => '• ' + b), c.faq ? `\n${c.faq.question}\n${c.faq.answer}` : '']
        .filter(Boolean)
        .join('\n\n');
const asHtml = (c: NonNullable<Advice['content']>) =>
    [
        `<h2>${esc(c.heading ?? '')}</h2>`,
        ...(c.paragraphs ?? []).map((p) => `<p>${esc(p)}</p>`),
        c.bullets?.length ? `<ul>\n${c.bullets.map((b) => `  <li>${esc(b)}</li>`).join('\n')}\n</ul>` : '',
        c.faq ? `<h3>${esc(c.faq.question)}</h3>\n<p>${esc(c.faq.answer)}</p>` : '',
    ]
        .filter(Boolean)
        .join('\n');

// While the AI is writing, refresh every few seconds so the content appears by itself.
const writing = computed(() => props.keywords.data.some((k) => k.advice.content_status === 'writing'));
let timer: number | undefined;
onMounted(() => (timer = window.setInterval(() => writing.value && router.reload({ only: ['keywords'] }), 5000)));
onBeforeUnmount(() => clearInterval(timer));
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
                        <p
                            v-if="k.advice.placement"
                            class="rounded-md border border-blue-200 bg-blue-50 p-2 text-blue-950 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100"
                        >
                            📍 {{ k.advice.placement.text }}
                        </p>
                    </div>

                    <!-- WHAT TO WRITE: full content when written -->
                    <div v-if="k.advice.content?.kind === 'section'" class="grid gap-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-medium uppercase text-muted-foreground">What to write — ready to paste</p>
                            <span class="flex gap-2">
                                <Button size="sm" variant="outline" @click="copy(k.id + 'txt', asText(k.advice.content))"
                                    ><Check v-if="copied === k.id + 'txt'" /><Copy v-else /> Copy as text</Button
                                >
                                <Button size="sm" variant="outline" @click="copy(k.id + 'html', asHtml(k.advice.content))"
                                    ><Check v-if="copied === k.id + 'html'" /><Copy v-else /> Copy as HTML</Button
                                >
                            </span>
                        </div>
                        <div class="rounded-md border bg-background p-3">
                            <p class="text-xs text-muted-foreground">Heading (H2)</p>
                            <h3 class="mb-2 text-base font-semibold">{{ k.advice.content.heading }}</h3>
                            <p v-for="(p, i) in k.advice.content.paragraphs" :key="i" class="mb-2 leading-relaxed">{{ p }}</p>
                            <ul v-if="k.advice.content.bullets?.length" class="mb-2 list-disc pl-5">
                                <li v-for="b in k.advice.content.bullets" :key="b">{{ b }}</li>
                            </ul>
                            <div v-if="k.advice.content.faq" class="mt-2 border-t pt-2">
                                <p class="text-xs text-muted-foreground">FAQ (add to the page's FAQ, or as a small heading under the section)</p>
                                <p class="font-medium">{{ k.advice.content.faq.question }}</p>
                                <p>{{ k.advice.content.faq.answer }}</p>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Check anything marked [check] before publishing: the AI only knows what is on your page and in your brand facts.
                        </p>
                    </div>
                    <div v-else-if="k.advice.content?.kind === 'title'" class="grid gap-2">
                        <p class="text-xs font-medium uppercase text-muted-foreground">What to write — paste into the page's SEO settings</p>
                        <div
                            v-for="[label, value] in [
                                ['SEO title', k.advice.content.title],
                                ['Meta description', k.advice.content.meta_description],
                            ]"
                            :key="label"
                            class="flex items-start justify-between gap-2 rounded-md border bg-background p-2"
                        >
                            <span
                                ><span class="text-xs text-muted-foreground">{{ label }} ({{ (value ?? '').length }} chars):</span><br />{{
                                    value
                                }}</span
                            >
                            <button class="shrink-0 text-primary" :title="`Copy ${label}`" @click="copy(k.id + label, value ?? '')">
                                <Check v-if="copied === k.id + label" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <!-- WHAT TO WRITE: not written yet -->
                    <div v-else-if="k.advice.suggestion" class="grid gap-2">
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
                        <p v-if="k.advice.content_status === 'writing'" class="flex items-center gap-2 text-sm text-blue-700">
                            <Loader2 class="h-4 w-4 animate-spin" /> The AI is writing the
                            {{ k.advice.write_kind === 'title' ? 'new title and description' : 'full section' }}… about a minute. It appears here by
                            itself.
                        </p>
                        <p v-else-if="k.advice.content_status === 'failed'" class="text-sm text-red-700">
                            Writing failed: {{ k.advice.content_error }}
                        </p>
                    </div>

                    <!-- HOW -->
                    <div v-if="k.advice.steps.length" class="grid gap-1">
                        <p class="text-xs font-medium uppercase text-muted-foreground">How</p>
                        <ol class="list-decimal space-y-0.5 pl-5">
                            <li v-for="s in k.advice.steps" :key="s">{{ s }}</li>
                        </ol>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="canWork && k.advice.write_kind && k.advice.content_status !== 'writing'" size="sm" @click="write(k)"
                            ><Wand2 />
                            {{
                                k.advice.content
                                    ? 'Write it again'
                                    : k.advice.write_kind === 'title'
                                      ? 'Write the new title for me'
                                      : 'Write this section for me'
                            }}</Button
                        >
                        <Button v-if="canWork && k.advice.can_brief" size="sm" @click="brief(k)"><FilePlus2 /> Write the full page for me</Button>
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
