<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ExternalLink, ListRestart, PlugZap, Save } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Preset {
    label: string;
    base_url?: string | null;
    needs_key: boolean;
    needs_url?: boolean;
    signup_url: string | null;
    note: string;
    model_hint?: string;
}
interface Current {
    preset: string;
    base_url: string | null;
    model: string | null;
    configured: boolean;
    has_key: boolean;
    key_hint: string | null;
}

const props = defineProps<{
    ai: Current;
    search: Current;
    aiPresets: Record<string, Preset>;
    searchPresets: Record<string, Preset>;
    canManage: boolean;
    crawler: { base_url: string; healthy: boolean };
    usage: { provider: string; requests: number; units: number }[];
}>();
const page = usePage<SharedData>();

const ai = useForm({ preset: props.ai.preset, base_url: props.ai.base_url ?? '', model: props.ai.model ?? '', api_key: '', clear_key: false });
const aiPreset = computed(() => props.aiPresets[ai.preset]);
watch(
    () => ai.preset,
    (p) => {
        ai.base_url = props.aiPresets[p]?.base_url ?? '';
        if (p !== props.ai.preset) ai.model = '';
    },
);
const loadedModels = computed<string[]>(
    () => (page.props.flash?.data?.models_for === ai.preset ? (page.props.flash?.data?.models as string[]) : []) ?? [],
);
const loading = ref(false);
const loadModels = () => {
    loading.value = true;
    router.post(
        route('providers.models'),
        { preset: ai.preset, base_url: ai.base_url || null, api_key: ai.api_key || null },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => (loading.value = false),
            onSuccess: () => {
                const hint = aiPreset.value?.model_hint;
                if (!ai.model && loadedModels.value.length)
                    ai.model = loadedModels.value.find((m) => hint && m.includes(hint)) ?? loadedModels.value[0];
            },
        },
    );
};
const saveAi = () =>
    ai
        .transform((d) => ({ ...d, base_url: d.base_url || null, api_key: d.api_key || null }))
        .patch(route('providers.update', 'ai'), {
            preserveScroll: true,
            onSuccess: () => ai.reset('api_key', 'clear_key'),
        });

const search = useForm({ preset: props.search.preset, base_url: props.search.base_url ?? '', api_key: '', clear_key: false });
const searchPreset = computed(() => props.searchPresets[search.preset]);
const saveSearch = () =>
    search
        .transform((d) => ({ ...d, base_url: d.base_url || null, api_key: d.api_key || null }))
        .patch(route('providers.update', 'search'), {
            preserveScroll: true,
            onSuccess: () => search.reset('api_key', 'clear_key'),
        });
const testing = ref<string | null>(null);
const test = (kind: string) => {
    testing.value = kind;
    router.post(route('providers.test', kind), {}, { preserveScroll: true, onFinish: () => (testing.value = null) });
};
</script>

<template>
    <SeoLayout
        title="AI & data providers"
        description="Everything here can be free. Keys are stored encrypted on this server and are never shown again after saving."
    >
        <p v-if="!canManage" class="rounded-lg border bg-muted/50 p-3 text-sm">Only workspace owners and admins can change these settings.</p>

        <Panel title="1. AI writer" description="Writes article drafts, titles, explanations and outreach messages. Pick any free provider.">
            <template #actions>
                <Badge :value="ai.preset === 'mock' ? 'partial' : props.ai.configured && props.ai.model ? 'connected' : 'failed'" />
                <Button size="sm" variant="outline" :disabled="testing === 'ai'" @click="test('ai')"><PlugZap /> Test</Button>
            </template>
            <form class="grid gap-4" @submit.prevent="saveAi">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        v-for="(p, key) in aiPresets"
                        :key="key"
                        :class="[
                            'cursor-pointer rounded-lg border p-3 text-sm hover:bg-accent',
                            ai.preset === key && 'border-primary ring-1 ring-primary',
                        ]"
                    >
                        <input v-model="ai.preset" type="radio" :value="key" class="sr-only" :disabled="!canManage" />
                        <span class="font-medium">{{ p.label }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ p.note }}</span>
                    </label>
                </div>

                <template v-if="ai.preset !== 'mock'">
                    <ol v-if="aiPreset?.signup_url" class="list-decimal space-y-1 rounded-lg bg-muted/50 p-3 pl-8 text-sm">
                        <li>
                            Open
                            <a
                                :href="aiPreset.signup_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-primary hover:underline"
                                >{{ aiPreset.signup_url }} <ExternalLink class="h-3 w-3"
                            /></a>
                            {{ ai.preset === 'ollama' ? 'and install Ollama, then run: ollama pull llama3.2' : 'and sign in (free).' }}
                        </li>
                        <li v-if="aiPreset.needs_key">Create an API key and paste it below.</li>
                        <li>Click <strong>Load models</strong>, pick one, then <strong>Save</strong> and <strong>Test</strong>.</li>
                    </ol>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div v-if="aiPreset?.needs_key" class="grid gap-1.5">
                            <Label
                                >API key
                                <span v-if="props.ai.has_key && props.ai.preset === ai.preset" class="text-xs text-muted-foreground"
                                    >(saved: {{ props.ai.key_hint }} — leave empty to keep)</span
                                ></Label
                            >
                            <Input v-model="ai.api_key" type="password" autocomplete="off" placeholder="Paste key" :disabled="!canManage" />
                        </div>
                        <div v-if="ai.preset === 'custom' || ai.preset === 'ollama'" class="grid gap-1.5">
                            <Label>Base URL</Label>
                            <Input v-model="ai.base_url" placeholder="https://…/v1" :disabled="!canManage" />
                            <InputError :message="ai.errors.base_url" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Model</Label>
                            <div class="flex gap-2">
                                <select
                                    v-if="loadedModels.length"
                                    v-model="ai.model"
                                    class="h-10 w-full rounded-md border border-input bg-background px-2 text-sm"
                                >
                                    <option v-for="m in loadedModels" :key="m" :value="m">{{ m }}</option>
                                </select>
                                <Input v-else v-model="ai.model" placeholder="Click Load models" :disabled="!canManage" />
                                <Button type="button" variant="outline" :disabled="loading || !canManage" @click="loadModels"
                                    ><ListRestart /> Load models</Button
                                >
                            </div>
                            <InputError :message="ai.errors.model" />
                        </div>
                    </div>
                    <label v-if="props.ai.has_key" class="flex items-center gap-2 text-xs text-muted-foreground"
                        ><input v-model="ai.clear_key" type="checkbox" /> Remove the saved key</label
                    >
                </template>
                <div>
                    <Button :disabled="ai.processing || !canManage"><Save /> Save AI settings</Button>
                </div>
            </form>
        </Panel>

        <Panel title="2. Web search" description="Used by autopilot to find websites that could link to you. DuckDuckGo needs no setup.">
            <template #actions>
                <Badge :value="props.search.configured && search.preset !== 'none' ? 'connected' : 'partial'" />
                <Button size="sm" variant="outline" :disabled="testing === 'search'" @click="test('search')"><PlugZap /> Test</Button>
            </template>
            <form class="grid gap-4" @submit.prevent="saveSearch">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        v-for="(p, key) in searchPresets"
                        :key="key"
                        :class="[
                            'cursor-pointer rounded-lg border p-3 text-sm hover:bg-accent',
                            search.preset === key && 'border-primary ring-1 ring-primary',
                        ]"
                    >
                        <input v-model="search.preset" type="radio" :value="key" class="sr-only" :disabled="!canManage" />
                        <span class="font-medium">{{ p.label }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ p.note }}</span>
                    </label>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div v-if="searchPreset?.needs_key" class="grid gap-1.5">
                        <Label>
                            API key
                            <a
                                v-if="searchPreset.signup_url"
                                :href="searchPreset.signup_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-1 text-xs text-primary hover:underline"
                                >get a free key</a
                            >
                            <span v-if="props.search.has_key && props.search.preset === search.preset" class="text-xs text-muted-foreground">
                                (saved: {{ props.search.key_hint }})</span
                            >
                        </Label>
                        <Input v-model="search.api_key" type="password" autocomplete="off" :disabled="!canManage" />
                    </div>
                    <div v-if="searchPreset?.needs_url" class="grid gap-1.5">
                        <Label>SearXNG URL</Label>
                        <Input v-model="search.base_url" placeholder="https://search.example.com" :disabled="!canManage" />
                        <InputError :message="search.errors.base_url" />
                    </div>
                </div>
                <div>
                    <Button :disabled="search.processing || !canManage"><Save /> Save search settings</Button>
                </div>
            </form>
        </Panel>

        <div class="grid gap-4 md:grid-cols-2">
            <Panel title="3. Keyword ideas" description="Always free, no setup.">
                <p class="text-sm">
                    Google and DuckDuckGo autocomplete: the real phrases people type. Free sources do not reveal search volume, so the app ranks ideas
                    with its own <strong>opportunity score</strong> instead of inventing numbers.
                </p>
            </Panel>
            <Panel title="Crawler service" :description="crawler.base_url">
                <Badge :value="crawler.healthy ? 'connected' : 'failed'" />
                <p v-if="!crawler.healthy" class="mt-2 text-sm text-muted-foreground">
                    Not running. Start everything with <code class="rounded bg-muted px-1">composer dev</code>.
                </p>
            </Panel>
        </div>

        <Panel title="Usage in the last 30 days">
            <ul class="text-sm">
                <li v-for="u in usage" :key="u.provider" class="flex justify-between border-t py-1">
                    <span>{{ u.provider }}</span
                    ><span class="tabular-nums text-muted-foreground">{{ u.requests }} requests · {{ u.units }} units</span>
                </li>
                <li v-if="!usage.length" class="text-muted-foreground">Nothing yet.</li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
