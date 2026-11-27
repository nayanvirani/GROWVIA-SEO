<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, RefreshCw, Search } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Submission {
    status: string;
    listing_url: string | null;
    checked_at: string | null;
    check_result: string | null;
}
interface Platform {
    key: string;
    name: string;
    url: string;
    why: string;
    steps: string[];
    minutes: number;
    verify: boolean;
    submission: Submission | null;
}

const props = defineProps<{
    pack: Record<string, any>;
    fields: Record<string, { label: string; max: number | null }>;
    platforms: Platform[];
    mentions: any[];
    lastMentionRun: { status: string; started_at: string; result_json: any; error_message: string | null } | null;
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const canWork = computed(() => ['owner', 'admin', 'editor'].includes(page.props.workspace?.role ?? ''));

const statusOf = (p: Platform) => p.submission?.status ?? 'todo';
const done = computed(() => props.platforms.filter((p) => ['live', 'submitted', 'skipped'].includes(statusOf(p))).length);
const live = computed(() => props.platforms.filter((p) => statusOf(p) === 'live').length);
const open = ref<string | null>(props.platforms.find((p) => statusOf(p) === 'todo')?.key ?? null);
const urls = reactive<Record<string, string>>(Object.fromEntries(props.platforms.map((p) => [p.key, p.submission?.listing_url ?? ''])));

const copied = ref<string | null>(null);
const asText = (v: any) => (Array.isArray(v) ? v.join('\n') : String(v ?? ''));
const copy = (key: string, v: any) => {
    navigator.clipboard?.writeText(asText(v));
    copied.value = key;
    setTimeout(() => (copied.value = null), 1500);
};
const save = (p: Platform, status: string) =>
    router.patch(route('listings.update', [w.value.id, p.key]), { status, listing_url: urls[p.key] || null }, { preserveScroll: true });
const regenerate = () => router.post(route('listings.regenerate', w.value.id), {}, { preserveScroll: true });
const findMentions = () => router.post(route('listings.mentions', w.value.id), {}, { preserveScroll: true });
const statusTone: Record<string, string> = { todo: 'queued', submitted: 'partial', live: 'connected', skipped: 'rejected' };
</script>

<template>
    <SeoLayout
        title="Get listed"
        description="Trusted sites where buyers look for apps like yours. Each one links back to you. Everything you need to type is already written — open the site, paste, done."
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <p class="text-xs uppercase text-muted-foreground">Progress</p>
                <p class="text-2xl font-semibold">{{ done }} / {{ platforms.length }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs uppercase text-muted-foreground">Live links</p>
                <p class="text-2xl font-semibold text-emerald-700">{{ live }}</p>
            </div>
            <div class="rounded-xl border p-4 text-sm text-muted-foreground">
                Do one a day, top to bottom. Each takes 5–20 minutes once. The app checks every week that your listings are still live.
            </div>
        </div>

        <Panel
            title="Your listing texts"
            :description="`Written ${pack.source === 'ai' ? 'by AI from your website' : 'from your website titles and descriptions'}. Check the facts once; they are reused for every site.`"
        >
            <template #actions>
                <Button v-if="canWork" size="sm" variant="outline" @click="regenerate"><RefreshCw /> Rewrite</Button>
            </template>
            <p v-if="pack.ai_note" class="mb-3 rounded-md bg-amber-50 p-2 text-xs text-amber-900">{{ pack.ai_note }}</p>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="(f, key) in fields"
                    :key="key"
                    :class="['grid gap-1', ['long_description', 'features'].includes(String(key)) && 'md:col-span-2']"
                >
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium">{{ f.label }}</span>
                        <span class="flex items-center gap-2 text-muted-foreground">
                            <span v-if="f.max && typeof pack[key] === 'string'">{{ pack[key].length }}/{{ f.max }}</span>
                            <button class="inline-flex items-center gap-1 text-primary hover:underline" @click="copy(String(key), pack[key])">
                                <Check v-if="copied === key" class="h-3 w-3" /><Copy v-else class="h-3 w-3" />
                                {{ copied === key ? 'Copied' : 'Copy' }}
                            </button>
                        </span>
                    </div>
                    <TextArea
                        v-if="String(key) === 'long_description' || Array.isArray(pack[key])"
                        :model-value="asText(pack[key])"
                        :rows="Array.isArray(pack[key]) ? 5 : 7"
                        readonly
                    />
                    <Input v-else :model-value="pack[key] ?? ''" readonly />
                </div>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">Also have ready: your logo (square PNG) and 3–5 screenshots of the app in use.</p>
        </Panel>

        <Panel title="Where to get listed" description="Best first. Click a row to see the steps.">
            <ul class="divide-y">
                <li v-for="(p, i) in platforms" :key="p.key" class="py-3">
                    <button class="flex w-full items-center justify-between gap-3 text-left" @click="open = open === p.key ? null : p.key">
                        <span class="flex items-center gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">{{
                                i + 1
                            }}</span>
                            <span>
                                <span class="text-sm font-medium">{{ p.name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ p.why }}</span>
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                            <span v-if="p.minutes">~{{ p.minutes }} min</span>
                            <Badge :value="statusTone[statusOf(p)]" /><span class="w-16 capitalize">{{ statusOf(p) }}</span>
                        </span>
                    </button>
                    <div v-if="open === p.key" class="mt-3 grid gap-3 rounded-lg bg-muted/40 p-3 text-sm md:ml-9">
                        <ol class="list-decimal space-y-1 pl-5">
                            <li>
                                Open
                                <a
                                    :href="p.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-primary hover:underline"
                                    >{{ p.url }} <ExternalLink class="h-3 w-3"
                                /></a>
                            </li>
                            <li v-for="s in p.steps" :key="s">{{ s }}</li>
                            <li>
                                When your listing is published, paste its link below and click <strong>It's live</strong>.
                                {{
                                    p.verify
                                        ? 'The app checks the link to your site automatically.'
                                        : 'This site blocks automatic checks, so the app trusts you.'
                                }}
                            </li>
                        </ol>
                        <div v-if="canWork" class="flex flex-wrap items-center gap-2">
                            <Input v-model="urls[p.key]" placeholder="https://… your listing page" class="max-w-md" />
                            <Button size="sm" @click="save(p, 'live')">It's live</Button>
                            <Button size="sm" variant="outline" @click="save(p, 'submitted')">Submitted, waiting for approval</Button>
                            <Button size="sm" variant="ghost" @click="save(p, statusOf(p) === 'skipped' ? 'todo' : 'skipped')">{{
                                statusOf(p) === 'skipped' ? 'Undo skip' : 'Skip'
                            }}</Button>
                        </div>
                        <p v-if="p.submission?.check_result" class="text-xs text-muted-foreground">
                            Last check {{ datetime(p.submission.checked_at) }}: {{ p.submission.check_result }}
                        </p>
                    </div>
                </li>
            </ul>
        </Panel>

        <Panel
            title="Pages that mention you without a link"
            description="The easiest links to get: they already wrote about you. A short thank-you note asking for a link is drafted for each."
        >
            <template #actions>
                <Button v-if="canWork" size="sm" variant="outline" @click="findMentions"><Search /> Search now</Button>
            </template>
            <p v-if="lastMentionRun" class="mb-2 text-xs text-muted-foreground">
                Last search {{ datetime(lastMentionRun.started_at) }} · <Badge :value="lastMentionRun.status" />
                <span v-if="lastMentionRun.result_json?.pages_checked !== undefined">
                    · {{ lastMentionRun.result_json.pages_checked }} pages checked, {{ lastMentionRun.result_json.unlinked_mentions }} found</span
                >
                <span v-if="lastMentionRun.result_json?.errors?.length" class="text-amber-700"> · {{ lastMentionRun.result_json.errors[0] }}</span>
            </p>
            <ul class="divide-y text-sm">
                <li v-for="m in mentions" :key="m.id" class="py-2">
                    <a :href="m.source_url" target="_blank" rel="noopener noreferrer nofollow" class="font-medium text-primary hover:underline">{{
                        m.page_title || m.source_domain
                    }}</a>
                    <p class="text-xs italic text-muted-foreground">“…{{ m.evidence_json?.mention }}…”</p>
                    <p v-if="m.outreach_drafts.length" class="text-xs">
                        Message ready in <a :href="route('backlinks.index', w.id)" class="text-primary hover:underline">Backlinks</a>.
                    </p>
                </li>
                <li v-if="!mentions.length" class="py-2 text-muted-foreground">
                    None found yet. New websites rarely have mentions; this grows as people write about you.
                </li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
