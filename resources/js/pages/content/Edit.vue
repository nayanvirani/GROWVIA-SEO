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
import { AlertTriangle, CheckCircle2, Copy, Download, ExternalLink, ImagePlus, Plus, Save, Trash2, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
const save = () =>
    form.patch(route('content.drafts.update', [w.value.id, props.draft.id]), { preserveScroll: true, onSuccess: () => form.reset('change_summary') });
const approve = () => router.post(route('content.drafts.approve', [w.value.id, props.draft.id]), {}, { preserveScroll: true });
const setStatus = (status: string) =>
    router.patch(route('content.drafts.update', [w.value.id, props.draft.id]), { status }, { preserveScroll: true });
const imageBrief = () =>
    router.post(
        route('images.store', w.value.id),
        { content_draft_id: props.draft.id, aspect_ratio: '16:9', placement: 'featured image' },
        { preserveScroll: true },
    );
const exportUrl = (format: string) => route('content.drafts.export', [w.value.id, props.draft.id]) + `?format=${format}`;
const publishUrl = ref<string>(props.draft.published_url ?? '');
const checking = ref(false);
const checkPublished = () => {
    checking.value = true;
    router.post(
        route('content.drafts.published', [w.value.id, props.draft.id]),
        { url: publishUrl.value },
        { preserveScroll: true, onFinish: () => (checking.value = false) },
    );
};
const publishCheck = computed(
    () =>
        props.draft.publish_check_json as {
            checks: { key: string; state: string; label: string; detail: string | null }[];
            link_suggestions: { url: string; title: string; anchor: string; snippet: string | null }[];
        } | null,
);
const copyText = (t: string) => navigator.clipboard?.writeText(t);
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
                <a :href="exportUrl('md')"
                    ><Button variant="outline"><Download /> Markdown</Button></a
                >
                <a :href="exportUrl('html')"><Button variant="outline">HTML</Button></a>
                <a :href="exportUrl('csv')"><Button variant="outline">Meta CSV</Button></a>
            </template>
        </template>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="grid min-w-0 gap-4 xl:col-span-2">
                <Panel title="SEO fields">
                    <div class="grid gap-3">
                        <div class="grid gap-1.5"><Label>Title</Label><Input v-model="form.title" /></div>
                        <div class="grid gap-1.5">
                            <Label
                                >Meta title <span class="text-xs text-muted-foreground">({{ form.meta_title.length }} chars)</span></Label
                            ><Input v-model="form.meta_title" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label
                                >Meta description <span class="text-xs text-muted-foreground">({{ form.meta_description.length }} chars)</span></Label
                            ><TextArea v-model="form.meta_description" :rows="2" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Slug</Label><Input v-model="form.slug_suggestion" />
                            <p v-if="form.errors.slug_suggestion" class="text-xs text-red-600">Lowercase letters, numbers and dashes only.</p>
                        </div>
                    </div>
                </Panel>
                <Panel :title="`Body (Markdown, ${words} words)`">
                    <TextArea v-model="form.body_markdown" :rows="28" class="font-mono text-xs" />
                    <div class="mt-2 flex gap-2"><Input v-model="form.change_summary" placeholder="What did you change? (optional)" /></div>
                </Panel>
                <Panel title="FAQ">
                    <template #actions
                        ><Button size="sm" variant="outline" @click="form.faq_json.push({ question: '', answer: '' })"><Plus /> Add</Button></template
                    >
                    <div v-for="(q, i) in form.faq_json" :key="i" class="mb-3 grid gap-1.5 rounded-lg border p-3">
                        <div class="flex gap-2">
                            <Input v-model="q.question" placeholder="Question" /><Button
                                size="icon"
                                variant="ghost"
                                @click="form.faq_json.splice(i, 1)"
                                ><Trash2
                            /></Button>
                        </div>
                        <TextArea v-model="q.answer" :rows="2" placeholder="Answer" />
                    </div>
                </Panel>
            </div>

            <div class="grid min-w-0 content-start gap-4">
                <Panel
                    v-if="canExport"
                    title="Published on your website?"
                    description="After you publish this article on your blog, paste its link. The app checks the live post and tells you which pages should link to it."
                >
                    <form class="flex gap-2" @submit.prevent="checkPublished">
                        <Input v-model="publishUrl" type="url" placeholder="https://your-site.com/blog/…" required />
                        <Button :disabled="checking || !publishUrl" size="sm">{{
                            checking ? 'Checking…' : draft.published_url ? 'Recheck' : 'Check'
                        }}</Button>
                    </form>
                    <template v-if="publishCheck">
                        <p class="mt-2 text-xs text-muted-foreground">
                            Last checked {{ datetime(draft.publish_checked_at) }} ·
                            <a
                                :href="draft.published_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-primary hover:underline"
                                >open post <ExternalLink class="h-3 w-3"
                            /></a>
                        </p>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            <li v-for="c in publishCheck.checks" :key="c.key" class="flex gap-2">
                                <CheckCircle2 v-if="c.state === 'ok'" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                <AlertTriangle v-else-if="c.state === 'warn'" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                <XCircle v-else class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                                <span
                                    >{{ c.label }}<span v-if="c.detail" class="block text-xs text-muted-foreground">{{ c.detail }}</span></span
                                >
                            </li>
                        </ul>
                        <div v-if="publishCheck.link_suggestions.length" class="mt-4">
                            <p class="text-sm font-medium">Add a link to this article from these pages</p>
                            <p class="text-xs text-muted-foreground">
                                Edit each page on your site, select the suggested words (or similar) and link them to your article.
                            </p>
                            <ul class="mt-2 space-y-2 text-sm">
                                <li v-for="s in publishCheck.link_suggestions" :key="s.url" class="rounded border p-2">
                                    <a :href="s.url" target="_blank" rel="noopener noreferrer" class="font-medium text-primary hover:underline">{{
                                        s.title || s.url
                                    }}</a>
                                    <p v-if="s.snippet" class="text-xs italic text-muted-foreground">Good spot: “…{{ s.snippet }}…”</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                        Link text: <code class="rounded bg-muted px-1">{{ s.anchor }}</code>
                                        <button class="text-primary" title="Copy" @click="copyText(s.anchor)"><Copy class="h-3 w-3" /></button>
                                        → <code class="break-all rounded bg-muted px-1">{{ draft.published_url }}</code>
                                        <button class="text-primary" title="Copy" @click="copyText(draft.published_url)">
                                            <Copy class="h-3 w-3" />
                                        </button>
                                    </p>
                                </li>
                            </ul>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Done adding them? The next crawl confirms it and removes them from this list.
                            </p>
                        </div>
                        <p v-else class="mt-3 text-xs text-muted-foreground">No internal link suggestions right now.</p>
                    </template>
                </Panel>

                <Panel title="Quality checks" description="Advisory. Resolve high-risk items before approval.">
                    <ul class="space-y-2 text-sm">
                        <li v-for="(q, i) in draft.quality_flags_json ?? []" :key="i" class="flex items-start gap-2">
                            <Badge :value="q.severity" /><span>{{ q.message }}</span>
                        </li>
                        <li v-if="!(draft.quality_flags_json ?? []).length" class="text-muted-foreground">No flags.</li>
                    </ul>
                </Panel>
                <Panel title="Provenance">
                    <dl class="grid grid-cols-[7rem_1fr] gap-1 text-sm">
                        <dt class="text-muted-foreground">Provider</dt>
                        <dd>{{ draft.provider }}</dd>
                        <dt class="text-muted-foreground">Model</dt>
                        <dd>{{ draft.model }}</dd>
                        <dt class="text-muted-foreground">Prompt</dt>
                        <dd>{{ draft.prompt_version }}</dd>
                        <dt class="text-muted-foreground">Keyword</dt>
                        <dd>{{ draft.brief?.primary_keyword?.keyword ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Generated</dt>
                        <dd>{{ datetime(draft.created_at) }}</dd>
                        <dt class="text-muted-foreground">Approved</dt>
                        <dd>{{ datetime(draft.approved_at) }}</dd>
                        <dt class="text-muted-foreground">Exported</dt>
                        <dd>{{ datetime(draft.exported_at) }}</dd>
                        <dt class="text-muted-foreground">Sources</dt>
                        <dd class="break-all">{{ (draft.source_pages_json ?? []).join(', ') || 'Website settings only' }}</dd>
                    </dl>
                </Panel>
                <Panel title="Internal link suggestions">
                    <ul class="space-y-1 text-sm">
                        <li v-for="l in draft.internal_links_json ?? []" :key="l.url">
                            <a :href="l.url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ l.title }}</a>
                        </li>
                        <li v-if="!(draft.internal_links_json ?? []).length" class="text-muted-foreground">None found in crawled pages.</li>
                    </ul>
                </Panel>
                <Panel title="Images">
                    <template #actions
                        ><Button size="sm" variant="outline" @click="imageBrief"><ImagePlus /> Brief</Button></template
                    >
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
                        <Button
                            v-for="s in manualStatuses"
                            :key="s"
                            size="sm"
                            variant="outline"
                            :disabled="draft.status === s"
                            @click="setStatus(s)"
                            >{{ s.replace(/_/g, ' ') }}</Button
                        >
                    </div>
                </Panel>
                <Panel title="Revisions">
                    <ul class="space-y-1 text-xs">
                        <li v-for="r in revisions" :key="r.id" class="flex justify-between gap-2">
                            <span
                                >{{ r.change_summary }} <span v-if="r.user" class="text-muted-foreground">· {{ r.user.name }}</span></span
                            ><span class="text-muted-foreground">{{ datetime(r.created_at) }}</span>
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    </SeoLayout>
</template>
