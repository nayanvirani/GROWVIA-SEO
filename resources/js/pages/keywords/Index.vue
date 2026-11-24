<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, path } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Download, Search, Sparkles, Wand2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    keywords: Paginated<any>;
    filters: { q?: string; intent?: string; cluster?: string };
    clusters: any[];
    pages: { id: string; canonical_url: string; title: string }[];
    seeds: string[];
    defaults: { locale: string; language: string };
    lastRun: { status: string; started_at: string; error_message: string | null; result_json: any } | null;
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const intents = ['informational', 'commercial', 'transactional', 'navigational'];
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));

const research = useForm({ seeds: '', locale: props.defaults.locale, language: props.defaults.language });
const manual = useForm({ keyword: '', locale: props.defaults.locale, language: props.defaults.language, intent: null as string | null });
const f = reactive({ q: props.filters.q ?? '', intent: props.filters.intent ?? null, cluster: props.filters.cluster ?? null });
watchDebounced(
    f,
    () => router.get(route('keywords.index', w.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }),
    { debounce: 300 },
);

const open = ref<string | null>(null);
const showAdvanced = ref(false);
const suggestion = computed(() => page.props.flash?.data?.page_suggestion as { keyword_id: string; suggestion: any } | undefined);
const runAuto = () => router.post(route('automation.run', [w.value.id, 'keyword_research']), {}, { preserveScroll: true });
const update = (k: any, data: Record<string, unknown>) => router.patch(route('keywords.update', [w.value.id, k.id]), data, { preserveScroll: true });
const map = (k: any, page_id: string | null) => router.post(route('keywords.map', [w.value.id, k.id]), { page_id }, { preserveScroll: true });
const suggest = (k: any) => router.post(route('keywords.map', [w.value.id, k.id]), { suggest: true }, { preserveScroll: true });
const remove = (k: any) => router.delete(route('keywords.destroy', [w.value.id, k.id]), { preserveScroll: true });
const pageOptions = computed(() => props.pages.map((p) => ({ value: p.id, label: path(p.canonical_url) })));
const scoreTone = (s: number | null) =>
    s === null ? 'bg-muted' : s >= 70 ? 'bg-emerald-100 text-emerald-800' : s >= 50 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600';
const hasVolume = computed(() => props.keywords.data.some((k) => k.volume !== null));
</script>

<template>
    <SeoLayout
        title="Keyword research"
        description="Keywords are the phrases people type into Google. Autopilot finds them for you and ranks the best ones to write about."
    >
        <template #actions>
            <a :href="route('keywords.export', w.id)"
                ><Button variant="outline"><Download /> CSV</Button></a
            >
            <Button v-if="canWork" @click="runAuto"><Sparkles /> Find keywords automatically</Button>
        </template>

        <div class="rounded-xl border bg-muted/30 p-4 text-sm">
            <p class="font-medium">How to read this page</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-muted-foreground">
                <li><strong>Opportunity</strong> (0–100) is our estimate of how worthwhile a phrase is for your site. Click a score to see why.</li>
                <li>
                    <strong>No page yet</strong> means nobody can find you for that search, so it's a good article idea. Autopilot creates briefs for
                    the best ones in Content Studio.
                </li>
                <li>Free sources do not reveal exact monthly searches, so we don't show made-up numbers.</li>
            </ul>
            <p v-if="lastRun" class="mt-2 text-xs">
                Last automatic research: <Badge :value="lastRun.status" /> {{ datetime(lastRun.started_at) }}
                <span v-if="lastRun.result_json?.seeds?.length" class="text-muted-foreground">
                    · topics: {{ lastRun.result_json.seeds.join(', ') }}</span
                >
                <span v-if="lastRun.error_message" class="text-red-600"> · {{ lastRun.error_message }}</span>
            </p>
        </div>

        <Panel v-if="clusters.length" title="Topics" description="Related keywords grouped together. Click one to filter.">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="c in clusters"
                    :key="c.id"
                    :class="['rounded-full border px-3 py-1 text-xs hover:bg-accent', f.cluster === c.id && 'bg-accent']"
                    @click="f.cluster = f.cluster === c.id ? null : c.id"
                >
                    {{ c.name }} · {{ c.keywords_count }}
                </button>
            </div>
        </Panel>

        <div v-if="suggestion" class="rounded-lg border bg-muted/40 p-3 text-sm">
            <template v-if="suggestion.suggestion">
                Best matching page: <strong>{{ path(suggestion.suggestion.url) }}</strong> ({{ Math.round(suggestion.suggestion.score * 100) }}%
                match).
                <Button size="sm" variant="outline" class="ml-2" @click="map({ id: suggestion.keyword_id }, suggestion.suggestion.page_id)"
                    >Use this page</Button
                >
            </template>
            <template v-else>No existing page fits — writing a new article is the way to rank for this.</template>
        </div>

        <Panel title="Keywords">
            <template #actions>
                <Input v-model="f.q" placeholder="Filter" class="h-8 w-48" />
                <SelectField v-model="f.intent" :options="intents" placeholder="Any intent" class="h-8 w-40" />
            </template>
            <EmptyState
                v-if="!keywords.data.length"
                title="No keywords yet"
                text='Click "Find keywords automatically". It uses your website pages to work out your topics.'
            />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-3">Keyword</th>
                            <th class="pr-3">Opportunity</th>
                            <th class="pr-3">Searcher wants</th>
                            <th v-if="hasVolume" class="pr-3">Volume</th>
                            <th class="pr-3">Your page for it</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="k in keywords.data" :key="k.id">
                            <tr class="border-t align-top">
                                <td class="py-1.5 pr-3">
                                    {{ k.keyword }}
                                    <div class="text-xs text-muted-foreground">
                                        {{ (k.sources_json ?? [k.provider]).join(' + ')
                                        }}<span v-if="k.clusters.length"> · {{ k.clusters.map((c: any) => c.name).join(', ') }}</span>
                                    </div>
                                </td>
                                <td class="pr-3">
                                    <button
                                        :class="['rounded-md px-2 py-0.5 font-semibold tabular-nums', scoreTone(k.opportunity_score)]"
                                        @click="open = open === k.id ? null : k.id"
                                    >
                                        {{ k.opportunity_score ?? '—' }}
                                    </button>
                                </td>
                                <td class="pr-3"><Badge :value="k.intent" /></td>
                                <td v-if="hasVolume" class="pr-3 tabular-nums">{{ k.volume ?? '—' }}</td>
                                <td class="min-w-48 pr-3">
                                    <select
                                        :value="k.page_targets[0]?.page_id ?? ''"
                                        :disabled="!canWork"
                                        class="h-8 w-full rounded border bg-background px-1 text-xs"
                                        @change="map(k, ($event.target as HTMLSelectElement).value || null)"
                                    >
                                        <option value="">No page yet</option>
                                        <option v-for="o in pageOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </select>
                                </td>
                                <td class="whitespace-nowrap">
                                    <Button v-if="canWork" size="sm" variant="ghost" title="Find best matching page" @click="suggest(k)"
                                        ><Wand2
                                    /></Button>
                                    <Link
                                        v-if="canWork"
                                        :href="route('content.index', w.id) + `?keyword_id=${k.id}&title=${encodeURIComponent(k.keyword)}`"
                                        ><Button size="sm" variant="ghost">Write</Button></Link
                                    >
                                    <Button v-if="canWork" size="sm" variant="ghost" class="text-red-600" title="Not relevant" @click="remove(k)"
                                        >×</Button
                                    >
                                </td>
                            </tr>
                            <tr v-if="open === k.id">
                                <td colspan="6" class="pb-3">
                                    <table class="w-full max-w-xl rounded bg-muted/50 text-xs">
                                        <tr v-for="(v, key) in k.opportunity_json ?? {}" :key="key" class="border-t">
                                            <td class="px-2 py-1 capitalize">{{ key }}</td>
                                            <td class="px-2 tabular-nums">+{{ v.points }}</td>
                                            <td class="px-2 text-muted-foreground">{{ v.why }}</td>
                                        </tr>
                                    </table>
                                    <div class="mt-1 flex items-center gap-2 text-xs">
                                        Priority:
                                        <select
                                            :value="k.priority ?? ''"
                                            class="h-7 rounded border bg-background px-1"
                                            @change="update(k, { priority: ($event.target as HTMLSelectElement).value || null })"
                                        >
                                            <option value="">—</option>
                                            <option>high</option>
                                            <option>medium</option>
                                            <option>low</option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <Pagination :links="keywords.links" :total="keywords.total" />
        </Panel>

        <Panel
            v-if="canWork"
            title="Optional: add your own topics"
            description="Autopilot already picks topics from your website. Use this only if you want to explore something specific."
        >
            <template #actions
                ><Button size="sm" variant="ghost" @click="showAdvanced = !showAdvanced">{{ showAdvanced ? 'Hide' : 'Show' }}</Button></template
            >
            <div v-if="showAdvanced" class="grid gap-4 lg:grid-cols-3">
                <form
                    class="grid gap-3 lg:col-span-2"
                    @submit.prevent="
                        research.post(route('keywords.research', w.id), { preserveScroll: true, onSuccess: () => research.reset('seeds') })
                    "
                >
                    <TextArea v-model="research.seeds" :rows="3" placeholder="One topic per line, e.g. shopify upsell app" />
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="grid gap-1"><Label>Country</Label><Input v-model="research.locale" maxlength="2" class="w-20" /></div>
                        <div class="grid gap-1"><Label>Language</Label><Input v-model="research.language" class="w-20" /></div>
                        <Button :disabled="research.processing || !research.seeds"><Search /> Research these</Button>
                    </div>
                    <p v-if="seeds.length" class="text-xs text-muted-foreground">Topics used so far: {{ seeds.join(', ') }}</p>
                </form>
                <form
                    class="grid content-start gap-2"
                    @submit.prevent="manual.post(route('keywords.store', w.id), { preserveScroll: true, onSuccess: () => manual.reset('keyword') })"
                >
                    <Label>Add one keyword</Label>
                    <Input v-model="manual.keyword" placeholder="keyword" />
                    <Button variant="outline" :disabled="!manual.keyword">Add</Button>
                </form>
            </div>
        </Panel>
    </SeoLayout>
</template>
