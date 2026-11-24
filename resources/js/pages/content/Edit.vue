<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Download, ImagePlus, Plus, Save, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ draft: any; revisions: any[]; manualStatuses: string[] }>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);

const form = useForm({
    title: props.draft.title,
    body_markdown: props.draft.body_markdown ?? '',
    meta_title: props.draft.meta_title ?? '',
    meta_description: props.draft.meta_description ?? '',
    slug_suggestion: props.draft.slug_suggestion ?? '',
    faq_json: (props.draft.faq_json ?? []) as { question: string; answer: string }[],
    change_summary: '',
});
const save = () => form.patch(route('content.drafts.update', [w.value.id, props.draft.id]), { preserveScroll: true, onSuccess: () => form.reset('change_summary') });
const approve = () => router.post(route('content.drafts.approve', [w.value.id, props.draft.id]), {}, { preserveScroll: true });
const setStatus = (status: string) => router.patch(route('content.drafts.update', [w.value.id, props.draft.id]), { status }, { preserveScroll: true });
const imageBrief = () => router.post(route('images.store', w.value.id), { content_draft_id: props.draft.id, aspect_ratio: '16:9', placement: 'featured image' }, { preserveScroll: true });
const exportUrl = (format: string) => route('content.drafts.export', [w.value.id, props.draft.id]) + `?format=${format}`;
const canExport = computed(() => ['approved_for_export', 'exported', 'published_externally', 'performance_review'].includes(props.draft.status));
const words = computed(() => (form.body_markdown.match(/\w+/g) ?? []).length);
</script>

<template>
    <SeoLayout :title="draft.title" description="Edit freely. Any change after approval sends the draft back to review.">
        <template #actions>
            <Badge :value="draft.status" />
            <Button variant="outline" :disabled="form.processing || !form.isDirty" @click="save"><Save /> Save</Button>
            <Button v-if="!canExport" :disabled="form.isDirty" @click="approve"><CheckCircle2 /> Approve for export</Button>
            <template v-else>
                <a :href="exportUrl('md')"><Button variant="outline"><Download /> Markdown</Button></a>
                <a :href="exportUrl('html')"><Button variant="outline">HTML</Button></a>
                <a :href="exportUrl('csv')"><Button variant="outline">Meta CSV</Button></a>
            </template>
        </template>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="grid gap-4 xl:col-span-2">
                <Panel title="SEO fields">
                    <div class="grid gap-3">
                        <div class="grid gap-1.5"><Label>Title</Label><Input v-model="form.title" /></div>
                        <div class="grid gap-1.5"><Label>Meta title <span class="text-xs text-muted-foreground">({{ form.meta_title.length }} chars)</span></Label><Input v-model="form.meta_title" /></div>
                        <div class="grid gap-1.5"><Label>Meta description <span class="text-xs text-muted-foreground">({{ form.meta_description.length }} chars)</span></Label><TextArea v-model="form.meta_description" :rows="2" /></div>
                        <div class="grid gap-1.5"><Label>Slug</Label><Input v-model="form.slug_suggestion" /><p v-if="form.errors.slug_suggestion" class="text-xs text-red-600">Lowercase letters, numbers and dashes only.</p></div>
                    </div>
                </Panel>
                <Panel :title="`Body (Markdown, ${words} words)`">
                    <TextArea v-model="form.body_markdown" :rows="28" class="font-mono text-xs" />
                    <div class="mt-2 flex gap-2"><Input v-model="form.change_summary" placeholder="What did you change? (optional)" /></div>
                </Panel>
                <Panel title="FAQ">
                    <template #actions><Button size="sm" variant="outline" @click="form.faq_json.push({ question: '', answer: '' })"><Plus /> Add</Button></template>
                    <div v-for="(q, i) in form.faq_json" :key="i" class="mb-3 grid gap-1.5 rounded-lg border p-3">
                        <div class="flex gap-2"><Input v-model="q.question" placeholder="Question" /><Button size="icon" variant="ghost" @click="form.faq_json.splice(i, 1)"><Trash2 /></Button></div>
                        <TextArea v-model="q.answer" :rows="2" placeholder="Answer" />
                    </div>
                </Panel>
            </div>

            <div class="grid content-start gap-4">
                <Panel title="Quality checks" description="Advisory. Resolve high-risk items before approval.">
                    <ul class="space-y-2 text-sm">
                        <li v-for="(q, i) in draft.quality_flags_json ?? []" :key="i" class="flex items-start gap-2"><Badge :value="q.severity" /><span>{{ q.message }}</span></li>
                        <li v-if="!(draft.quality_flags_json ?? []).length" class="text-muted-foreground">No flags.</li>
                    </ul>
                </Panel>
                <Panel title="Provenance">
                    <dl class="grid grid-cols-[7rem_1fr] gap-1 text-sm">
                        <dt class="text-muted-foreground">Provider</dt><dd>{{ draft.provider }}</dd>
                        <dt class="text-muted-foreground">Model</dt><dd>{{ draft.model }}</dd>
                        <dt class="text-muted-foreground">Prompt</dt><dd>{{ draft.prompt_version }}</dd>
                        <dt class="text-muted-foreground">Keyword</dt><dd>{{ draft.brief?.primary_keyword?.keyword ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Generated</dt><dd>{{ datetime(draft.created_at) }}</dd>
                        <dt class="text-muted-foreground">Approved</dt><dd>{{ datetime(draft.approved_at) }}</dd>
                        <dt class="text-muted-foreground">Exported</dt><dd>{{ datetime(draft.exported_at) }}</dd>
                        <dt class="text-muted-foreground">Sources</dt><dd class="break-all">{{ (draft.source_pages_json ?? []).join(', ') || 'Website settings only' }}</dd>
                    </dl>
                </Panel>
                <Panel title="Internal link suggestions">
                    <ul class="space-y-1 text-sm">
                        <li v-for="l in draft.internal_links_json ?? []" :key="l.url"><a :href="l.url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ l.title }}</a></li>
                        <li v-if="!(draft.internal_links_json ?? []).length" class="text-muted-foreground">None found in crawled pages.</li>
                    </ul>
                </Panel>
                <Panel title="Images">
                    <template #actions><Button size="sm" variant="outline" @click="imageBrief"><ImagePlus /> Brief</Button></template>
                    <ul class="space-y-2 text-sm">
                        <li v-for="img in draft.images" :key="img.id" class="rounded border p-2">
                            <p class="text-xs text-muted-foreground">{{ img.aspect_ratio }} · {{ img.placement }}</p>
                            <p>{{ img.prompt }}</p>
                            <p class="text-xs"><span class="text-muted-foreground">Alt:</span> {{ img.alt_text }}</p>
                        </li>
                    </ul>
                    <Link :href="route('images.index', w.id)" class="mt-2 inline-block text-xs text-primary hover:underline">Open Image Studio</Link>
                </Panel>
                <Panel title="Manual status" description="Publishing happens outside this app; record it here.">
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="s in manualStatuses" :key="s" size="sm" variant="outline" :disabled="draft.status === s" @click="setStatus(s)">{{ s.replace(/_/g, ' ') }}</Button>
                    </div>
                </Panel>
                <Panel title="Revisions">
                    <ul class="space-y-1 text-xs">
                        <li v-for="r in revisions" :key="r.id" class="flex justify-between gap-2"><span>{{ r.change_summary }} <span v-if="r.user" class="text-muted-foreground">· {{ r.user.name }}</span></span><span class="text-muted-foreground">{{ datetime(r.created_at) }}</span></li>
                    </ul>
                </Panel>
            </div>
        </div>
    </SeoLayout>
</template>
