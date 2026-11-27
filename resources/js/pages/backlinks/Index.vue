<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, label, path } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Download, Mail, Radar, Trash2, Upload } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{
    competitors: { id: string; domain: string; active: boolean; source: string; seen_count: number }[];
    prospects: Paginated<any>;
    existingLinks: {
        id: string;
        source_url: string;
        source_domain: string;
        target_url: string;
        anchor_text: string | null;
        rel_attributes: string | null;
        last_seen_at: string;
    }[];
    lastDiscovery: { status: string; started_at: string; error_message: string | null; result_json: any } | null;
    filters: { status?: string; min_score?: number };
    statuses: string[];
    csvColumns: string[];
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));

const comp = useForm({ domain: '' });
const csv = useForm<{ file: File | null }>({ file: null });
const f = reactive({ status: props.filters.status ?? null, min_score: props.filters.min_score ?? null });
watch(f, () =>
    router.get(route('backlinks.index', w.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')), {
        preserveState: true,
    }),
);
const open = ref<string | null>(null);
const showMore = ref(false);

const discover = () => router.post(route('backlinks.discover', w.value.id), {}, { preserveScroll: true });
const toggleComp = (c: any) => router.patch(route('backlinks.competitors.toggle', [w.value.id, c.id]), {}, { preserveScroll: true });
const removeComp = (c: any) => router.delete(route('backlinks.competitors.destroy', [w.value.id, c.id]), { preserveScroll: true });
const setStatus = (p: any, status: string) =>
    router.patch(route('backlinks.prospects.update', [w.value.id, p.id]), { status }, { preserveScroll: true });
const outreach = (p: any) => router.post(route('backlinks.outreach.store', [w.value.id, p.id]), {}, { preserveScroll: true });
const saveOutreach = (o: any, data: Record<string, unknown>) =>
    router.patch(route('backlinks.outreach.update', [w.value.id, o.id]), data, { preserveScroll: true });
const copy = (o: any) => navigator.clipboard?.writeText(`Subject: ${o.subject}\n\n${o.body}`);
const copyEmail = (e: string) => navigator.clipboard?.writeText(e);
/** "apps.shopify.com/bundler-product-bundles" -> "Bundler product bundles" */
const competitorName = (id: string) => {
    if (!id.includes('/')) return id;
    const name = id.split('/').pop()!.replace(/-/g, ' ');
    return (name.charAt(0).toUpperCase() + name.slice(1)).slice(0, 40);
};
const typeLabel: Record<string, string> = {
    listicle: '"Best tools" list',
    resource_page: 'Resource page',
    blog: 'Blog article',
    existing_link: 'Already links to you',
    unlinked_mention: 'Mentions you, no link yet',
    competitor_link: 'Competitor link',
};
</script>

<template>
    <SeoLayout
        title="Backlinks"
        description="A backlink is another website linking to yours. Google treats good links as votes of trust. Autopilot finds websites likely to mention you; you decide who to contact."
    >
        <template #actions>
            <a :href="route('backlinks.export', w.id)"
                ><Button variant="outline"><Download /> CSV</Button></a
            >
            <Button v-if="canWork" @click="discover"><Radar /> Find opportunities now</Button>
        </template>

        <div class="rounded-xl border bg-muted/30 p-4 text-sm">
            <p class="font-medium">How it works</p>
            <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-muted-foreground">
                <li>Autopilot searches for pages like "best [your topic] apps" and resource lists.</li>
                <li>
                    It opens each page and checks if it's current, genuine, and already lists your competitors (a strong sign they'd list you too).
                </li>
                <li>
                    Good ones get a score and a drafted message. <strong>You</strong> personalise it and send it from your own email. Nothing is ever
                    sent automatically.
                </li>
            </ol>
            <p v-if="lastDiscovery" class="mt-2 text-xs">
                Last search: <Badge :value="lastDiscovery.status" /> {{ datetime(lastDiscovery.started_at) }}
                <span v-if="lastDiscovery.result_json?.pages_checked !== undefined" class="text-muted-foreground">
                    · {{ lastDiscovery.result_json.pages_checked }} pages checked, {{ lastDiscovery.result_json.prospects_saved }} saved
                </span>
                <span v-if="lastDiscovery.result_json?.skipped" class="text-amber-700"> · {{ lastDiscovery.result_json.skipped }}</span>
                <span v-if="lastDiscovery.result_json?.errors?.length" class="text-amber-700"> · {{ lastDiscovery.result_json.errors[0] }}</span>
                <span v-if="lastDiscovery.result_json?.will_resume" class="text-muted-foreground"> · it will continue by itself shortly</span>
                <span v-if="lastDiscovery.error_message" class="text-red-600"> · {{ lastDiscovery.error_message }}</span>
            </p>
        </div>

        <Panel title="Opportunities" description="Sorted by score (the most free data can show is about 70). Green = worth contacting.">
            <template #actions>
                <SelectField v-model="f.status" :options="statuses" placeholder="Any status" class="h-8 w-36" />
                <Input v-model.number="f.min_score" type="number" min="0" max="100" placeholder="Min score" class="h-8 w-28" />
            </template>
            <EmptyState
                v-if="!prospects.data.length"
                title="No opportunities yet"
                text='Click "Find opportunities now". Autopilot uses your keyword topics, so run keyword research first.'
            />
            <ul class="divide-y">
                <li v-for="p in prospects.data" :key="p.id" class="py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <button class="min-w-0 flex-1 text-left" @click="open = open === p.id ? null : p.id">
                            <p class="break-words text-sm font-medium">{{ p.page_title || p.source_domain }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ typeLabel[p.prospect_type] ?? label(p.prospect_type) }} · {{ p.source_domain }}
                            </p>
                            <p v-if="p.evidence_json?.competitors_linked?.length" class="break-words text-xs text-emerald-700">
                                Already lists: {{ p.evidence_json.competitors_linked.map(competitorName).join(', ') }}
                            </p>
                        </button>
                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                :class="[
                                    'rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums',
                                    p.score >= 50
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : p.score >= 35
                                          ? 'bg-amber-100 text-amber-800'
                                          : 'bg-slate-100 text-slate-700',
                                ]"
                                >{{ p.score }}</span
                            >
                            <select
                                :value="p.status"
                                :disabled="!canWork"
                                class="h-8 rounded border bg-background px-1 text-xs"
                                @change="setStatus(p, ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <a :href="p.source_url" target="_blank" rel="noopener noreferrer nofollow"
                                ><Button size="sm" variant="ghost">Open</Button></a
                            >
                            <Button
                                v-if="canWork && !p.outreach_drafts.length"
                                size="sm"
                                variant="outline"
                                :disabled="p.status === 'rejected'"
                                @click="outreach(p)"
                                ><Mail /> Draft message</Button
                            >
                        </div>
                    </div>
                    <p v-if="p.risk_flags_json?.length" class="mt-1 text-xs text-red-700">
                        ⚠ {{ p.risk_flags_json.map((r: string) => label(r)).join(', ') }}
                    </p>
                    <p v-if="p.prospect_type !== 'existing_link'" class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span class="font-medium">Where to send your message:</span>
                        <a
                            v-if="p.evidence_json?.contact_url"
                            :href="p.evidence_json.contact_url"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="text-primary hover:underline"
                            >{{ p.evidence_json.contact_kind === 'submit' ? 'Submit / write-for-us page' : 'Contact page' }} ↗</a
                        >
                        <span v-for="e in p.evidence_json?.contact_emails ?? []" :key="e" class="inline-flex items-center gap-1">
                            <a :href="`mailto:${e}`" class="text-primary hover:underline">{{ e }}</a>
                            <button title="Copy email" @click="copyEmail(e)"><Copy class="h-3 w-3" /></button>
                        </span>
                        <span v-if="!p.evidence_json?.contact_url && !(p.evidence_json?.contact_emails ?? []).length" class="text-muted-foreground">
                            {{
                                p.evidence_json?.contact_checked_at
                                    ? 'No contact page found — look for the author’s name or the site’s social profiles.'
                                    : 'Not looked up yet.'
                            }}
                        </span>
                    </p>
                    <div
                        v-if="open === p.id || p.outreach_drafts.length"
                        class="mt-3 grid gap-3 rounded-lg bg-muted/50 p-3 text-sm md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                    >
                        <div>
                            <p class="mb-1 font-medium">Why this score</p>
                            <table class="w-full text-xs">
                                <tr v-for="(v, k) in p.score_breakdown_json" :key="k" class="border-t">
                                    <td class="py-0.5 pr-2">{{ label(String(k)) }}</td>
                                    <td class="pr-2 tabular-nums">{{ v.points }}</td>
                                    <td class="text-muted-foreground">{{ v.evidence }}</td>
                                </tr>
                            </table>
                            <p v-if="p.evidence_json?.found_by_search" class="mt-2 text-xs text-muted-foreground">
                                Found by searching “{{ p.evidence_json.found_by_search }}”.
                            </p>
                        </div>
                        <div class="grid content-start gap-3">
                            <div v-for="o in p.outreach_drafts" :key="o.id" class="grid gap-2 rounded border bg-background p-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-muted-foreground">Message to send yourself (edit the [brackets])</span
                                    ><Badge :value="o.status" />
                                </div>
                                <Input
                                    :model-value="o.subject"
                                    :disabled="!canWork"
                                    @change="saveOutreach(o, { subject: ($event.target as HTMLInputElement).value })"
                                />
                                <TextArea
                                    :model-value="o.body"
                                    :rows="8"
                                    @change="saveOutreach(o, { body: ($event.target as HTMLTextAreaElement).value })"
                                />
                                <div v-if="canWork" class="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" @click="copy(o)"><Copy /> Copy</Button>
                                    <Button v-if="o.status === 'draft'" size="sm" variant="outline" @click="saveOutreach(o, { status: 'approved' })"
                                        >Approve</Button
                                    >
                                    <Button v-if="o.status === 'approved'" size="sm" variant="outline" @click="saveOutreach(o, { status: 'sent' })"
                                        >I sent it</Button
                                    >
                                    <Button size="sm" variant="ghost" @click="saveOutreach(o, { status: 'discarded' })">Discard</Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
            <Pagination :links="prospects.links" :total="prospects.total" />
        </Panel>

        <div class="grid gap-4 lg:grid-cols-2">
            <Panel
                title="Sites already linking to you"
                description="Found while checking opportunities. Free sources can't list every link on the internet."
            >
                <ul class="space-y-1 text-sm">
                    <li v-for="l in existingLinks" :key="l.id" class="flex justify-between gap-2">
                        <a :href="l.source_url" target="_blank" rel="noopener noreferrer nofollow" class="truncate text-primary hover:underline"
                            >{{ l.source_domain }}{{ path(l.source_url) }}</a
                        >
                        <span class="shrink-0 text-xs text-muted-foreground"
                            >{{ l.rel_attributes || 'followed' }} · {{ datetime(l.last_seen_at) }}</span
                        >
                    </li>
                    <li v-if="!existingLinks.length" class="text-muted-foreground">None found yet.</li>
                </ul>
            </Panel>
            <Panel title="Competitors" description="Detected automatically from searches. Turn one off if it isn't really a competitor.">
                <form
                    v-if="canWork"
                    class="mb-3 flex gap-2"
                    @submit.prevent="comp.post(route('backlinks.competitors.store', w.id), { preserveScroll: true, onSuccess: () => comp.reset() })"
                >
                    <Input v-model="comp.domain" placeholder="Add one yourself: competitor.com" />
                    <Button variant="outline" :disabled="!comp.domain">Add</Button>
                </form>
                <ul class="divide-y text-sm">
                    <li v-for="c in competitors" :key="c.id" class="flex items-center justify-between py-1.5">
                        <span :class="!c.active && 'text-muted-foreground line-through'">
                            {{ c.domain }}
                            <span class="text-xs text-muted-foreground">{{
                                c.source === 'autopilot' ? `auto · seen ${c.seen_count}×` : 'added by you'
                            }}</span>
                        </span>
                        <span v-if="canWork" class="flex gap-1">
                            <Button size="sm" variant="ghost" @click="toggleComp(c)">{{ c.active ? 'Ignore' : 'Use' }}</Button>
                            <Button size="sm" variant="ghost" @click="removeComp(c)"><Trash2 /></Button>
                        </span>
                    </li>
                    <li v-if="!competitors.length" class="py-2 text-muted-foreground">None yet.</li>
                </ul>
            </Panel>
        </div>

        <Panel
            v-if="canWork"
            title="Optional: import a list"
            :description="`Have a spreadsheet of sites? Columns: ${csvColumns.join(', ')} (source_url required).`"
        >
            <template #actions
                ><Button size="sm" variant="ghost" @click="showMore = !showMore">{{ showMore ? 'Hide' : 'Show' }}</Button></template
            >
            <form
                v-if="showMore"
                class="flex flex-wrap items-center gap-2"
                @submit.prevent="
                    csv.post(route('backlinks.import', w.id), { forceFormData: true, preserveScroll: true, onSuccess: () => csv.reset() })
                "
            >
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="text-sm"
                    @change="csv.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                />
                <Button variant="outline" :disabled="!csv.file || csv.processing"><Upload /> Import</Button>
                <p v-if="csv.errors.file" class="text-xs text-red-600">{{ csv.errors.file }}</p>
            </form>
        </Panel>
    </SeoLayout>
</template>
