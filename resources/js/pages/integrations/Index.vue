<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    ai: { provider: string; configured: boolean; models: string[] };
    seoData: { provider: string };
    searchConsole: { oauth_configured: boolean; status: string; property: string | null; properties: string[] };
    crawler: { base_url: string; healthy: boolean };
    usage: { provider: string; requests: number; units: number; cost: number }[];
}>();
const setProperty = (e: Event) => router.patch(route('integrations.search-console.property'), { property: (e.target as HTMLSelectElement).value });
const disconnect = () => router.delete(route('integrations.search-console.disconnect'));
</script>

<template>
    <SeoLayout
        title="Integrations"
        description="External services are configured server-side in .env. Credentials are encrypted and never sent to the browser."
    >
        <div class="grid gap-4 md:grid-cols-2">
            <Panel title="Crawler service" :description="crawler.base_url">
                <Badge :value="crawler.healthy ? 'connected' : 'failed'" />
                <p v-if="!crawler.healthy" class="mt-2 text-sm text-muted-foreground">
                    Start it with <code class="rounded bg-muted px-1">uvicorn app.main:app --port 8100</code> from crawler-service/.
                </p>
            </Panel>
            <Panel title="LLM provider" description="AI_PROVIDER = mock | openai">
                <p class="text-sm">
                    Provider: <strong>{{ ai.provider }}</strong> <Badge :value="ai.configured ? 'connected' : 'failed'" class="ml-1" />
                </p>
                <p v-if="ai.provider !== 'mock'" class="mt-1 text-xs text-muted-foreground">
                    Models: {{ ai.models.join(' (short tasks), ') }} (long-form)
                </p>
                <p v-else class="mt-1 text-xs text-muted-foreground">
                    Mock mode returns deterministic templated drafts. Set AI_PROVIDER=openai and AI_API_KEY to use a real model.
                </p>
            </Panel>
            <Panel title="SEO data provider" description="Keyword and backlink metrics">
                <p class="text-sm">
                    Provider: <strong>{{ seoData.provider }}</strong>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Mock fixtures are labelled in the UI. A DataForSEO adapter can be added behind the same interface once budget is approved.
                </p>
            </Panel>
            <Panel title="Google Search Console" description="Read-only access to clicks, impressions, CTR and position.">
                <div class="grid gap-2 text-sm">
                    <p>Status: <Badge :value="searchConsole.status" /></p>
                    <template v-if="searchConsole.status === 'connected'">
                        <label class="text-xs text-muted-foreground">Property</label>
                        <select :value="searchConsole.property ?? ''" class="h-9 rounded-md border bg-background px-2" @change="setProperty">
                            <option v-for="p in searchConsole.properties" :key="p" :value="p">{{ p }}</option>
                        </select>
                        <div><Button size="sm" variant="ghost" @click="disconnect">Disconnect</Button></div>
                    </template>
                    <template v-else-if="searchConsole.oauth_configured">
                        <a :href="route('integrations.search-console.redirect')"><Button size="sm">Connect Search Console</Button></a>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env to enable. Mock analytics is used meanwhile.
                    </p>
                </div>
            </Panel>
        </div>
        <Panel title="Usage by provider (30 days)">
            <ul class="text-sm">
                <li v-for="u in props.usage" :key="u.provider" class="flex justify-between border-t py-1">
                    <span>{{ u.provider }}</span
                    ><span class="tabular-nums text-muted-foreground"
                        >{{ u.requests }} requests · {{ u.units }} units · ${{ Number(u.cost).toFixed(2) }}</span
                    >
                </li>
                <li v-if="!props.usage.length" class="text-muted-foreground">No usage yet.</li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
