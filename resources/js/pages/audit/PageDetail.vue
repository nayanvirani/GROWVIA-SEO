<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, path } from '@/lib/format';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ page: any; issues: any[]; links: any[]; inlinks: any[]; rules: Record<string, any> }>();
const shared = usePage<SharedData>();
const w = computed(() => shared.props.currentWebsite!);
const suggestions = computed(() => shared.props.flash?.data?.meta_suggestions as { meta_title: string; meta_description: string }[] | undefined);
const loading = ref(false);
const suggest = () => {
    loading.value = true;
    router.post(route('content.meta-suggestions', [w.value.id, props.page.id]), {}, { preserveScroll: true, onFinish: () => (loading.value = false) });
};
const copy = (t: string) => navigator.clipboard?.writeText(t);
const internal = computed(() => props.links.filter((l) => l.link_type === 'internal'));
const external = computed(() => props.links.filter((l) => l.link_type === 'external'));
const images = computed<{ src: string; alt: string | null }[]>(() => props.page.images_json ?? []);
</script>

<template>
    <SeoLayout :title="path(page.canonical_url)" :description="page.canonical_url">
        <template #actions>
            <a :href="page.canonical_url" target="_blank" rel="noopener noreferrer"><Button variant="outline">Open page</Button></a>
            <Link :href="route('content.index', w.id) + `?page_id=${page.id}&title=${encodeURIComponent(page.title ?? '')}`"><Button variant="outline">Create content brief</Button></Link>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Page facts" class="lg:col-span-2">
                <dl class="grid gap-2 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-muted-foreground">Status</dt><dd>{{ page.status_code ?? 'error' }} <span v-if="page.fetch_error" class="text-red-600">({{ page.fetch_error }})</span></dd>
                    <dt class="text-muted-foreground">Final URL</dt><dd class="break-all">{{ page.final_url ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Content type</dt><dd>{{ page.content_type || '—' }}</dd>
                    <dt class="text-muted-foreground">Title</dt><dd>{{ page.title ?? '—' }} <span v-if="page.title" class="text-xs text-muted-foreground">({{ page.title.length }} chars)</span></dd>
                    <dt class="text-muted-foreground">Meta description</dt><dd>{{ page.meta_description ?? '—' }} <span v-if="page.meta_description" class="text-xs text-muted-foreground">({{ page.meta_description.length }} chars)</span></dd>
                    <dt class="text-muted-foreground">H1</dt><dd>{{ (page.headings_json?.h1 ?? []).join(' · ') || '—' }}</dd>
                    <dt class="text-muted-foreground">H2</dt><dd>{{ (page.headings_json?.h2 ?? []).slice(0, 8).join(' · ') || '—' }}</dd>
                    <dt class="text-muted-foreground">Canonical</dt><dd class="break-all">{{ page.canonical_url_found ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Robots</dt><dd>{{ [page.robots_meta, page.x_robots_tag].filter(Boolean).join(' / ') || '—' }}</dd>
                    <dt class="text-muted-foreground">Words</dt><dd>{{ page.word_count }}</dd>
                    <dt class="text-muted-foreground">Depth</dt><dd>{{ page.depth }}</dd>
                    <dt class="text-muted-foreground">Redirects</dt><dd>{{ (page.redirect_chain_json ?? []).map((h: any) => `${h.status} ${h.url}`).join(' → ') || 'none' }}</dd>
                    <dt class="text-muted-foreground">Last crawled</dt><dd>{{ datetime(page.last_crawled_at) }}</dd>
                </dl>
            </Panel>
            <Panel title="Issues on this page">
                <ul class="space-y-2 text-sm">
                    <li v-for="i in issues" :key="i.id" class="flex items-start justify-between gap-2">
                        <div><p class="font-medium">{{ rules[i.rule_key]?.label }}</p><p class="text-xs text-muted-foreground">{{ i.recommendation }}</p></div>
                        <Badge :value="i.status === 'open' ? i.severity : i.status" />
                    </li>
                    <li v-if="!issues.length" class="text-muted-foreground">No issues detected.</li>
                </ul>
            </Panel>
        </div>

        <Panel title="Title & meta suggestions" description="AI drafts based on this page's crawled content. Review before changing your live site.">
            <template #actions><Button size="sm" variant="outline" :disabled="loading" @click="suggest"><Sparkles /> Suggest</Button></template>
            <div v-if="suggestions" class="grid gap-3 md:grid-cols-2">
                <div v-for="(s, idx) in suggestions" :key="idx" class="rounded-lg border p-3 text-sm">
                    <p class="font-medium">{{ s.meta_title }} <span class="text-xs text-muted-foreground">({{ s.meta_title.length }})</span></p>
                    <p class="mt-1 text-muted-foreground">{{ s.meta_description }} <span class="text-xs">({{ s.meta_description.length }})</span></p>
                    <Button size="sm" variant="ghost" class="mt-2" @click="copy(`${s.meta_title}\n${s.meta_description}`)">Copy</Button>
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">Click Suggest to draft alternatives.</p>
        </Panel>

        <div class="grid gap-4 lg:grid-cols-2">
            <Panel :title="`Internal links (${internal.length})`">
                <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
                    <li v-for="l in internal" :key="l.id" class="flex justify-between gap-2">
                        <span class="truncate">{{ path(l.target_url) }} <span class="text-xs text-muted-foreground">“{{ l.anchor_text }}”</span></span>
                        <span :class="['shrink-0 tabular-nums text-xs', l.http_status >= 400 ? 'text-red-600' : 'text-muted-foreground']">{{ l.http_status ?? '?' }}</span>
                    </li>
                </ul>
            </Panel>
            <Panel :title="`External links (${external.length}) · Linked from (${inlinks.length})`">
                <ul class="max-h-40 space-y-1 overflow-y-auto text-sm">
                    <li v-for="l in external" :key="l.id" class="truncate">{{ l.target_url }} <span v-if="l.rel" class="text-xs text-muted-foreground">[{{ l.rel }}]</span></li>
                </ul>
                <hr class="my-3" />
                <ul class="max-h-40 space-y-1 overflow-y-auto text-sm">
                    <li v-for="p in inlinks" :key="p.id"><Link :href="route('audit.page', [w.id, p.id])" class="text-primary hover:underline">{{ path(p.canonical_url) }}</Link></li>
                </ul>
            </Panel>
        </div>

        <Panel :title="`Images (${images.length})`" description="Missing alt = no alt attribute. Empty alt is valid for decorative images.">
            <ul class="grid gap-1 text-sm md:grid-cols-2">
                <li v-for="(img, idx) in images" :key="idx" class="flex justify-between gap-2">
                    <span class="truncate">{{ path(img.src) }}</span>
                    <span :class="['shrink-0 text-xs', img.alt === null ? 'text-amber-600' : 'text-muted-foreground']">{{ img.alt === null ? 'missing alt' : img.alt === '' ? 'decorative (empty)' : img.alt }}</span>
                </li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
