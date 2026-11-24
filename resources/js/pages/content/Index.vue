<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, path } from '@/lib/format';
import type { SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Sparkles, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    briefs: any[];
    drafts: any[];
    keywords: { id: string; keyword: string; intent: string | null }[];
    pages: { id: string; canonical_url: string }[];
    prefill: { keyword_id?: string; page_id?: string; title?: string };
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);

const form = useForm({
    title: props.prefill.title ?? '',
    content_type: 'blog_post',
    primary_keyword_id: props.prefill.keyword_id ?? null,
    page_id: props.prefill.page_id ?? null,
    audience: '',
    intent: null as string | null,
    source_notes: '',
});
const create = () => form.post(route('content.briefs.store', w.value.id), { preserveScroll: true, onSuccess: () => form.reset() });
const generate = (b: any) => router.post(route('content.briefs.generate', [w.value.id, b.id]), {}, { preserveScroll: true });
const destroy = (b: any) =>
    confirm('Delete this brief?') && router.delete(route('content.briefs.destroy', [w.value.id, b.id]), { preserveScroll: true });

const generating = computed(() => props.briefs.some((b) => b.status === 'generating'));
let timer: number | undefined;
onMounted(() => (timer = window.setInterval(() => generating.value && router.reload({ only: ['briefs', 'drafts'] }), 4000)));
onBeforeUnmount(() => clearInterval(timer));
const highFlags = (d: any) => (d.quality_flags_json ?? []).filter((q: any) => q.severity === 'high').length;
</script>

<template>
    <SeoLayout title="Content studio" description="Idea → brief → AI draft → your edits → approval → export. Nothing is published automatically.">
        <div class="grid gap-4 lg:grid-cols-5">
            <Panel title="New content brief" class="lg:col-span-2">
                <form class="grid gap-3" @submit.prevent="create">
                    <div class="grid gap-1.5">
                        <Label>Working title</Label><Input v-model="form.title" required /><InputError :message="form.errors.title" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1.5">
                            <Label>Type</Label><SelectField v-model="form.content_type" :options="['blog_post', 'landing_page']" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Intent</Label
                            ><SelectField v-model="form.intent" :options="['informational', 'commercial', 'transactional']" placeholder="Auto" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Primary keyword</Label
                        ><SelectField
                            v-model="form.primary_keyword_id"
                            :options="keywords.map((k) => ({ value: k.id, label: k.keyword }))"
                            placeholder="None"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Existing page (optional)</Label
                        ><SelectField
                            v-model="form.page_id"
                            :options="pages.map((p) => ({ value: p.id, label: path(p.canonical_url) }))"
                            placeholder="New content"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Audience</Label><Input v-model="form.audience" placeholder="Shopify store owners with 100+ orders/month" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Factual source notes</Label
                        ><TextArea
                            v-model="form.source_notes"
                            :rows="4"
                            placeholder="Verified facts the draft may use. Anything not here will be flagged for review."
                        />
                    </div>
                    <Button :disabled="form.processing">Create brief</Button>
                </form>
            </Panel>

            <div class="grid gap-4 lg:col-span-3">
                <Panel title="Briefs">
                    <EmptyState v-if="!briefs.length" title="No briefs yet" text="Create one on the left, or start from a keyword." />
                    <ul class="divide-y">
                        <li v-for="b in briefs" :key="b.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ b.title }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ b.content_type.replace('_', ' ') }}<span v-if="b.primary_keyword"> · {{ b.primary_keyword.keyword }}</span
                                    ><span v-if="b.page"> · {{ path(b.page.canonical_url) }}</span> · {{ b.drafts_count }} drafts
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge :value="b.status" />
                                <Button size="sm" variant="outline" :disabled="b.status === 'generating'" @click="generate(b)"
                                    ><Sparkles /> {{ b.drafts_count ? 'New draft' : 'Generate draft' }}</Button
                                >
                                <Button size="sm" variant="ghost" @click="destroy(b)"><Trash2 /></Button>
                            </div>
                        </li>
                    </ul>
                </Panel>
                <Panel title="Drafts">
                    <EmptyState v-if="!drafts.length" title="No drafts yet" />
                    <ul class="divide-y">
                        <li v-for="d in drafts" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <Link :href="route('content.drafts.edit', [w.id, d.id])" class="text-sm font-medium text-primary hover:underline">{{
                                    d.title
                                }}</Link>
                                <p class="text-xs text-muted-foreground">
                                    {{ d.provider }} / {{ d.model }} · updated {{ datetime(d.updated_at)
                                    }}<span v-if="highFlags(d)" class="text-red-600"> · {{ highFlags(d) }} high-risk flags</span>
                                </p>
                            </div>
                            <Badge :value="d.status" />
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    </SeoLayout>
</template>
