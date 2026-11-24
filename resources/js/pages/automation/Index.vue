<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, label } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Pause, Play, PlayCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{
    automations: { key: string; label: string; schedule: string; paused: boolean; last_run: string | null }[];
    runs: any[];
    usage: { provider: string; operation: string; requests: number; units: number; cost: number }[];
}>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const run = (key: string) => router.post(route('automation.run', [w.value.id, key]), {}, { preserveScroll: true });
const toggle = (key: string) => router.post(route('automation.toggle', [w.value.id, key]), {}, { preserveScroll: true });
const open = ref<string | null>(null);
</script>

<template>
    <SeoLayout title="Automation center" description="Scheduled jobs run via the Laravel scheduler and queue workers. Every run is logged here.">
        <Panel title="Scheduled automations">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1 pr-4">Automation</th><th class="pr-4">Schedule</th><th class="pr-4">Last run</th><th class="pr-4">State</th><th /></tr></thead>
                <tbody>
                    <tr v-for="a in automations" :key="a.key" class="border-t">
                        <td class="py-1.5 pr-4 font-medium">{{ a.label }}</td>
                        <td class="pr-4 text-muted-foreground">{{ a.schedule }}</td>
                        <td class="pr-4">{{ datetime(a.last_run) }}</td>
                        <td class="pr-4"><Badge :value="a.paused ? 'partial' : 'connected'" />{{ a.paused ? ' paused' : '' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <Button size="sm" variant="ghost" @click="toggle(a.key)"><component :is="a.paused ? Play : Pause" /> {{ a.paused ? 'Resume' : 'Pause' }}</Button>
                            <Button size="sm" variant="outline" @click="run(a.key)"><PlayCircle /> Run now</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Panel>

        <Panel title="Run log">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1 pr-4">Started</th><th class="pr-4">Automation</th><th class="pr-4">Trigger</th><th class="pr-4">Status</th><th>Result</th></tr></thead>
                <tbody>
                    <template v-for="r in runs" :key="r.id">
                        <tr class="cursor-pointer border-t hover:bg-muted/40" @click="open = open === r.id ? null : r.id">
                            <td class="py-1.5 pr-4">{{ datetime(r.started_at) }}</td>
                            <td class="pr-4">{{ label(r.automation_key) }}</td>
                            <td class="pr-4 capitalize">{{ r.trigger }}</td>
                            <td class="pr-4"><Badge :value="r.status" /></td>
                            <td class="max-w-md truncate text-xs text-muted-foreground">{{ r.error_message ?? JSON.stringify(r.result_json) }}</td>
                        </tr>
                        <tr v-if="open === r.id"><td colspan="5"><pre class="overflow-x-auto rounded bg-muted p-2 text-xs">{{ JSON.stringify(r.result_json ?? r.error_message, null, 2) }}</pre></td></tr>
                    </template>
                    <tr v-if="!runs.length"><td colspan="5" class="py-3 text-muted-foreground">No runs yet.</td></tr>
                </tbody>
            </table>
        </Panel>

        <Panel title="Provider usage (30 days)" description="Track paid API usage. Costs are estimates; check current provider pricing.">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1 pr-4">Provider</th><th class="pr-4">Operation</th><th class="pr-4">Requests</th><th class="pr-4">Units</th><th>Est. cost</th></tr></thead>
                <tbody>
                    <tr v-for="u in usage" :key="u.provider + u.operation" class="border-t">
                        <td class="py-1.5 pr-4">{{ u.provider }}</td><td class="pr-4">{{ u.operation }}</td><td class="pr-4 tabular-nums">{{ u.requests }}</td><td class="pr-4 tabular-nums">{{ u.units }}</td><td class="tabular-nums">${{ Number(u.cost).toFixed(2) }}</td>
                    </tr>
                    <tr v-if="!usage.length"><td colspan="5" class="py-3 text-muted-foreground">No usage recorded.</td></tr>
                </tbody>
            </table>
        </Panel>
    </SeoLayout>
</template>
