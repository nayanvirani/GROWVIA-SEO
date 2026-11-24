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
import { label } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Download, Mail, Trash2, Upload } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{
    competitors: any[];
    prospects: Paginated<any>;
    filters: { status?: string; min_score?: number };
    statuses: string[];
    csvColumns: string[];
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);

const comp = useForm({ domain: '' });
const csv = useForm<{ file: File | null }>({ file: null });
const f = reactive({ status: props.filters.status ?? null, min_score: props.filters.min_score ?? null });
watch(f, () =>
    router.get(route('backlinks.index', w.value.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')), {
        preserveState: true,
    }),
);
const open = ref<string | null>(null);

const importComp = (c: any) => router.post(route('backlinks.competitors.import', [w.value.id, c.id]), {}, { preserveScroll: true });
const removeComp = (c: any) => router.delete(route('backlinks.competitors.destroy', [w.value.id, c.id]), { preserveScroll: true });
const setStatus = (p: any, status: string) =>
    router.patch(route('backlinks.prospects.update', [w.value.id, p.id]), { status }, { preserveScroll: true });
const outreach = (p: any) => router.post(route('backlinks.outreach.store', [w.value.id, p.id]), {}, { preserveScroll: true });
const saveOutreach = (o: any, data: Record<string, unknown>) =>
    router.patch(route('backlinks.outreach.update', [w.value.id, o.id]), data, { preserveScroll: true });
</script>

<template>
    <SeoLayout
        title="Backlinks"
        description="Research and prioritise genuine link opportunities. Scores are a prioritisation aid, not a Google metric. No automated submissions — you send every email yourself."
    >
        <template #actions>
            <a :href="route('backlinks.export', w.id)"
                ><Button variant="outline"><Download /> CSV</Button></a
            >
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Panel title="Competitors" :description="`Import their backlinks from the data provider (${page.props.providers.seo_data}).`">
                <form
                    class="mb-3 flex gap-2"
                    @submit.prevent="comp.post(route('backlinks.competitors.store', w.id), { preserveScroll: true, onSuccess: () => comp.reset() })"
                >
                    <Input v-model="comp.domain" placeholder="competitor.com" />
                    <Button variant="outline" :disabled="!comp.domain">Add</Button>
                </form>
                <ul class="divide-y text-sm">
                    <li v-for="c in competitors" :key="c.id" class="flex items-center justify-between py-1.5">
                        <span>{{ c.domain }}</span>
                        <span class="flex gap-1">
                            <Button size="sm" variant="outline" @click="importComp(c)">Import links</Button>
                            <Button size="sm" variant="ghost" @click="removeComp(c)"><Trash2 /></Button>
                        </span>
                    </li>
                    <li v-if="!competitors.length" class="py-2 text-muted-foreground">No competitors yet.</li>
                </ul>
            </Panel>
            <Panel title="Import prospects from CSV" :description="`Columns: ${csvColumns.join(', ')} (source_url required).`">
                <form
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
                </form>
                <p v-if="csv.errors.file" class="mt-1 text-xs text-red-600">{{ csv.errors.file }}</p>
            </Panel>
        </div>

        <Panel title="Prospects">
            <template #actions>
                <SelectField v-model="f.status" :options="statuses" placeholder="Any status" class="h-8 w-36" />
                <Input v-model.number="f.min_score" type="number" min="0" max="100" placeholder="Min score" class="h-8 w-28" />
            </template>
            <EmptyState v-if="!prospects.data.length" title="No prospects yet" text="Import competitor links or a CSV." />
            <ul class="divide-y">
                <li v-for="p in prospects.data" :key="p.id" class="py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <button class="min-w-0 text-left" @click="open = open === p.id ? null : p.id">
                            <p class="text-sm font-medium">{{ p.page_title || p.source_domain }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ p.source_url }}</p>
                        </button>
                        <div class="flex items-center gap-2">
                            <span
                                :class="[
                                    'rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums',
                                    p.score >= 60
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : p.score >= 35
                                          ? 'bg-amber-100 text-amber-800'
                                          : 'bg-slate-100 text-slate-700',
                                ]"
                                >{{ p.score }}</span
                            >
                            <select
                                :value="p.status"
                                class="h-8 rounded border bg-background px-1 text-xs"
                                @change="setStatus(p, ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <Button size="sm" variant="outline" :disabled="p.status === 'rejected'" @click="outreach(p)"
                                ><Mail /> Draft outreach</Button
                            >
                        </div>
                    </div>
                    <div v-if="p.risk_flags_json?.length" class="mt-1 flex flex-wrap gap-1">
                        <span v-for="r in p.risk_flags_json" :key="'t' + r" class="text-xs text-red-700">{{ label(r) }}</span>
                    </div>
                    <div v-if="open === p.id" class="mt-3 grid gap-3 rounded-lg bg-muted/50 p-3 text-sm md:grid-cols-2">
                        <div>
                            <p class="mb-1 font-medium">Score evidence</p>
                            <table class="w-full text-xs">
                                <tr v-for="(v, k) in p.score_breakdown_json" :key="k" class="border-t">
                                    <td class="py-0.5 pr-2">{{ label(String(k)) }}</td>
                                    <td class="pr-2 tabular-nums">{{ v.points }}</td>
                                    <td class="text-muted-foreground">{{ v.evidence }}</td>
                                </tr>
                            </table>
                            <p class="mt-2 text-xs text-muted-foreground">Discovered via {{ p.discovery_source }}. Target: {{ p.target_url }}</p>
                        </div>
                        <div class="grid content-start gap-3">
                            <div v-for="o in p.outreach_drafts" :key="o.id" class="grid gap-2 rounded border bg-background p-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-muted-foreground">Outreach draft</span><Badge :value="o.status" />
                                </div>
                                <Input :model-value="o.subject" @change="saveOutreach(o, { subject: ($event.target as HTMLInputElement).value })" />
                                <TextArea
                                    :model-value="o.body"
                                    :rows="8"
                                    @change="saveOutreach(o, { body: ($event.target as HTMLTextAreaElement).value })"
                                />
                                <div class="flex gap-2">
                                    <Button v-if="o.status === 'draft'" size="sm" variant="outline" @click="saveOutreach(o, { status: 'approved' })"
                                        >Approve</Button
                                    >
                                    <Button v-if="o.status === 'approved'" size="sm" variant="outline" @click="saveOutreach(o, { status: 'sent' })"
                                        >I sent it</Button
                                    >
                                    <Button size="sm" variant="ghost" @click="saveOutreach(o, { status: 'discarded' })">Discard</Button>
                                </div>
                            </div>
                            <p v-if="!p.outreach_drafts.length" class="text-xs text-muted-foreground">No outreach drafts yet.</p>
                        </div>
                    </div>
                </li>
            </ul>
            <Pagination :links="prospects.links" :total="prospects.total" />
        </Panel>
    </SeoLayout>
</template>
