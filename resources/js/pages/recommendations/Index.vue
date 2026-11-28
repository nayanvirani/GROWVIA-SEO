<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, FilePlus2, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Rec {
    id: string;
    type: string;
    priority: string;
    score: number;
    title: string;
    why: string;
    steps_json: string[] | null;
    proposal_json: Record<string, any> | null;
    status: string;
    page: { id: string; canonical_url: string; title: string } | null;
    keyword: { keyword: string; opportunity_score: number; intent: string } | null;
}

const props = defineProps<{
    recommendations: Rec[];
    counts: Record<string, number>;
    status: string;
    types: Record<string, string>;
    lastRun: { status: string; started_at: string; result_json: any; error_message: string | null } | null;
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));
const groups = computed(() =>
    Object.entries(props.types)
        .map(([type, label]) => ({ type, label, items: props.recommendations.filter((r) => r.type === type) }))
        .filter((g) => g.items.length),
);
const open = ref<string | null>(props.recommendations[0]?.id ?? null);
const copied = ref<string | null>(null);
const copy = (key: string, text: string) => {
    navigator.clipboard?.writeText(text);
    copied.value = key;
    setTimeout(() => (copied.value = null), 1500);
};
const setStatus = (r: Rec, status: string) => router.patch(route('recommendations.update', [w.value.id, r.id]), { status }, { preserveScroll: true });
const brief = (r: Rec) => router.post(route('recommendations.brief', [w.value.id, r.id]));
const refresh = () => router.post(route('recommendations.refresh', w.value.id), {}, { preserveScroll: true });
const filter = (status: string) => router.get(route('recommendations.index', w.value.id), { status }, { preserveState: true });

/** Text fields worth copying, from the AI proposal first, then the basic suggestion. */
const fields = (r: Rec) => {
    const p = r.proposal_json ?? {};
    const ai = p.ai ?? {};
    return [
        ['Suggested title', ai.title ?? p.suggested_title],
        ['Meta description', ai.meta_description],
        ['Heading', ai.heading ?? p.suggested_heading],
        ['URL', p.suggested_url],
    ].filter(([, v]) => v) as [string, string][];
};
const priorityTone: Record<string, string> = { high: 'high', medium: 'medium', low: 'low' };
</script>

<template>
    <SeoLayout
        title="What to update"
        description="Specific changes for your website, worked out automatically every week. You make the changes in your website admin; the app notices on the next crawl and marks them done."
    >
        <template #actions>
            <Button v-if="canWork" variant="outline" @click="refresh"><RefreshCw /> Check again</Button>
        </template>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <button
                v-for="s in ['open', 'done', 'resolved', 'dismissed']"
                :key="s"
                :class="['rounded-full border px-3 py-1 capitalize', status === s ? 'bg-accent font-medium' : 'hover:bg-accent']"
                @click="filter(s)"
            >
                {{ s === 'resolved' ? 'Confirmed by crawl' : s }} · {{ counts[s] ?? 0 }}
            </button>
            <span v-if="lastRun" class="ml-auto text-xs text-muted-foreground">Last checked {{ datetime(lastRun.started_at) }}</span>
        </div>

        <EmptyState
            v-if="!recommendations.length"
            :title="status === 'open' ? 'Nothing to update right now' : 'Nothing here'"
            text="Suggestions appear after keyword research and each crawl. Run autopilot from the Overview if this is your first time."
        />

        <Panel v-for="g in groups" :key="g.type" :title="`${g.label} (${g.items.length})`">
            <ul class="divide-y">
                <li v-for="r in g.items" :key="r.id" class="py-3">
                    <button class="flex w-full items-start justify-between gap-3 text-left" @click="open = open === r.id ? null : r.id">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ r.title }}</span>
                            <span v-if="r.keyword" class="block text-xs text-muted-foreground"
                                >Search phrase: “{{ r.keyword.keyword }}” · opportunity {{ r.keyword.opportunity_score }}</span
                            >
                        </span>
                        <Badge :value="priorityTone[r.priority]" />
                    </button>

                    <div v-if="open === r.id" class="mt-3 grid gap-3 rounded-lg bg-muted/40 p-3 text-sm">
                        <p>{{ r.why }}</p>
                        <a
                            v-if="r.page"
                            :href="r.page.canonical_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            Open the page <ExternalLink class="h-3 w-3" />
                        </a>
                        <ol v-if="r.steps_json?.length" class="list-decimal space-y-1 pl-5">
                            <li v-for="s in r.steps_json" :key="s">{{ s }}</li>
                        </ol>

                        <div v-if="fields(r).length || r.proposal_json?.ai" class="grid gap-2 rounded-md border bg-background p-3">
                            <p class="text-xs font-medium uppercase text-muted-foreground">
                                Ready to paste {{ r.proposal_json?.ai ? '(written by AI — check facts marked [check])' : '' }}
                            </p>
                            <p v-if="r.proposal_json?.current_title" class="text-xs text-muted-foreground">
                                Current title: {{ r.proposal_json.current_title }}
                            </p>
                            <div v-for="[label, value] in fields(r)" :key="label" class="flex items-start justify-between gap-2">
                                <span
                                    ><span class="text-xs text-muted-foreground">{{ label }}:</span> {{ value }}</span
                                >
                                <button class="shrink-0 text-primary" :title="`Copy ${label}`" @click="copy(r.id + label, value)">
                                    <Check v-if="copied === r.id + label" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />
                                </button>
                            </div>
                            <div v-if="r.proposal_json?.ai?.outline?.length">
                                <span class="text-xs text-muted-foreground">Sections:</span>
                                <ul class="list-disc pl-5">
                                    <li v-for="o in r.proposal_json.ai.outline" :key="o">{{ o }}</li>
                                </ul>
                            </div>
                            <div v-if="r.proposal_json?.ai?.faq?.length">
                                <span class="text-xs text-muted-foreground">FAQ:</span>
                                <div v-for="f in r.proposal_json.ai.faq" :key="f.question" class="mt-1">
                                    <p class="font-medium">{{ f.question }}</p>
                                    <p class="text-muted-foreground">{{ f.answer }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="canWork && r.status === 'open'" class="flex flex-wrap gap-2">
                            <Button v-if="['new_post', 'new_landing'].includes(r.type) && r.keyword" size="sm" @click="brief(r)"
                                ><FilePlus2 /> Create brief</Button
                            >
                            <Button size="sm" variant="outline" @click="setStatus(r, 'done')">I did it</Button>
                            <Button size="sm" variant="ghost" @click="setStatus(r, 'dismissed')">Not relevant</Button>
                        </div>
                        <Button v-else-if="canWork && r.status !== 'resolved'" size="sm" variant="ghost" class="w-fit" @click="setStatus(r, 'open')"
                            >Reopen</Button
                        >
                    </div>
                </li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
