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
import { date, number, path } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Download, Search, Wand2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

const props = defineProps<{
    keywords: Paginated<any>;
    filters: { q?: string; intent?: string; cluster?: string };
    clusters: any[];
    pages: { id: string; canonical_url: string; title: string }[];
    seeds: string[];
    defaults: { locale: string; language: string };
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const intents = ['informational', 'commercial', 'transactional', 'navigational'];

const research = useForm({ seeds: '', locale: props.defaults.locale, language: props.defaults.language });
const manual = useForm({ keyword: '', locale: props.defaults.locale, language: props.defaults.language, intent: null as string | null });
const f = reactive({ q: props.filters.q ?? '', intent: props.filters.intent ?? null, cluster: props.filters.cluster ?? null });
watchDebounced(f, () => router.get(route('keywords.index', w.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }), { debounce: 300 });

const suggestion = computed(() => page.props.flash?.data?.page_suggestion as { keyword_id: string; suggestion: any } | undefined);
const update = (k: any, data: Record<string, unknown>) => router.patch(route('keywords.update', [w.value.id, k.id]), data, { preserveScroll: true });
const map = (k: any, page_id: string | null) => router.post(route('keywords.map', [w.value.id, k.id]), { page_id }, { preserveScroll: true });
const suggest = (k: any) => router.post(route('keywords.map', [w.value.id, k.id]), { suggest: true }, { preserveScroll: true });
const remove = (k: any) => router.delete(route('keywords.destroy', [w.value.id, k.id]), { preserveScroll: true });
const setPrimary = (c: any, id: string) => router.patch(route('keywords.clusters.update', [w.value.id, c.id]), { primary_keyword_id: id }, { preserveScroll: true });
const pageOptions = computed(() => props.pages.map((p) => ({ value: p.id, label: path(p.canonical_url) })));
</script>

<template>
    <SeoLayout title="Keyword research" description="Volume, difficulty and CPC are provider estimates. Mock data is labelled and must not be treated as real search volume.">
        <template #actions>
            <a :href="route('keywords.export', w.id)"><Button variant="outline"><Download /> CSV</Button></a>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Research seed terms" class="lg:col-span-2" :description="`Provider: ${page.props.providers.seo_data}. Results are cached for 30 days per term, country and language.`">
                <form class="grid gap-3" @submit.prevent="research.post(route('keywords.research', w.id), { preserveScroll: true, onSuccess: () => research.reset('seeds') })">
                    <TextArea v-model="research.seeds" :rows="3" placeholder="Shopify conversion app, Shopify upsell app, Shopify free gift app" />
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="grid gap-1"><Label>Country</Label><Input v-model="research.locale" maxlength="2" class="w-20" /></div>
                        <div class="grid gap-1"><Label>Language</Label><Input v-model="research.language" class="w-20" /></div>
                        <Button :disabled="research.processing || !research.seeds"><Search /> Research</Button>
                    </div>
                    <p v-if="seeds.length" class="text-xs text-muted-foreground">Recent seeds: {{ seeds.join(', ') }}</p>
                </form>
            </Panel>
            <Panel title="Add keyword manually">
                <form class="grid gap-2" @submit.prevent="manual.post(route('keywords.store', w.id), { preserveScroll: true, onSuccess: () => manual.reset('keyword') })">
                    <Input v-model="manual.keyword" placeholder="keyword" />
                    <SelectField v-model="manual.intent" :options="intents" placeholder="Intent (optional)" />
                    <Button variant="outline" :disabled="!manual.keyword">Add</Button>
                </form>
            </Panel>
        </div>

        <Panel v-if="clusters.length" title="Clusters" description="Auto-grouped by shared terms. Pick the primary keyword for each topic.">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="c in clusters"
                    :key="c.id"
                    :class="['rounded-full border px-3 py-1 text-xs hover:bg-accent', f.cluster === c.id && 'bg-accent']"
                    @click="f.cluster = f.cluster === c.id ? null : c.id"
                >
                    {{ c.name }} · {{ c.keywords_count }}<span v-if="c.primary_keyword" class="text-muted-foreground"> · ★ {{ c.primary_keyword.keyword }}</span>
                </button>
            </div>
        </Panel>

        <div v-if="suggestion" class="rounded-lg border bg-muted/40 p-3 text-sm">
            <template v-if="suggestion.suggestion">
                Suggested page: <strong>{{ path(suggestion.suggestion.url) }}</strong> (match {{ Math.round(suggestion.suggestion.score * 100) }}%).
                <Button size="sm" variant="outline" class="ml-2" @click="map({ id: suggestion.keyword_id }, suggestion.suggestion.page_id)">Map to this page</Button>
            </template>
            <template v-else>No existing page is a good match — a new content brief is probably justified.</template>
        </div>

        <Panel title="Keywords">
            <template #actions>
                <Input v-model="f.q" placeholder="Filter" class="h-8 w-48" />
                <SelectField v-model="f.intent" :options="intents" placeholder="Any intent" class="h-8 w-40" />
            </template>
            <EmptyState v-if="!keywords.data.length" title="No keywords yet" text="Enter seed terms above to start." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr><th class="py-1 pr-3">Keyword</th><th class="pr-3">Intent</th><th class="pr-3">Volume</th><th class="pr-3">KD</th><th class="pr-3">CPC</th><th class="pr-3">Source</th><th class="pr-3">Priority</th><th class="pr-3">Target page</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="k in keywords.data" :key="k.id" class="border-t align-top">
                            <td class="py-1.5 pr-3">
                                {{ k.keyword }}
                                <div v-if="k.clusters.length" class="text-xs text-muted-foreground">{{ k.clusters.map((c: any) => c.name).join(', ') }}</div>
                            </td>
                            <td class="pr-3"><Badge :value="k.intent" /></td>
                            <td class="pr-3 tabular-nums">{{ k.volume === null ? 'Not available' : number(k.volume) }}</td>
                            <td class="pr-3 tabular-nums">{{ k.difficulty ?? '—' }}</td>
                            <td class="pr-3 tabular-nums">{{ k.cpc ?? '—' }}</td>
                            <td class="whitespace-nowrap pr-3 text-xs text-muted-foreground" :title="k.is_estimate ? 'Estimate' : ''">
                                {{ k.provider }}<span v-if="k.provider === 'mock'" class="text-amber-600"> (fixture)</span><br />{{ k.locale }}-{{ k.language }} · {{ date(k.metric_date) }}
                            </td>
                            <td class="pr-3">
                                <select :value="k.priority ?? ''" class="h-8 rounded border bg-background px-1 text-xs" @change="update(k, { priority: ($event.target as HTMLSelectElement).value || null })">
                                    <option value="">—</option><option>high</option><option>medium</option><option>low</option>
                                </select>
                            </td>
                            <td class="min-w-48 pr-3">
                                <select :value="k.page_targets[0]?.page_id ?? ''" class="h-8 w-full rounded border bg-background px-1 text-xs" @change="map(k, ($event.target as HTMLSelectElement).value || null)">
                                    <option value="">Not mapped</option>
                                    <option v-for="o in pageOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                            </td>
                            <td class="whitespace-nowrap">
                                <Button size="sm" variant="ghost" title="Suggest page" @click="suggest(k)"><Wand2 /></Button>
                                <Link :href="route('content.index', w.id) + `?keyword_id=${k.id}&title=${encodeURIComponent(k.keyword)}`"><Button size="sm" variant="ghost">Brief</Button></Link>
                                <Button v-if="k.clusters.length" size="sm" variant="ghost" title="Make primary in cluster" @click="setPrimary(k.clusters[0], k.id)">★</Button>
                                <Button size="sm" variant="ghost" class="text-red-600" @click="remove(k)">×</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="keywords.links" :total="keywords.total" />
        </Panel>
    </SeoLayout>
</template>
