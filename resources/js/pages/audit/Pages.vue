<script setup lang="ts">
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import { Input } from '@/components/ui/input';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime, number, path } from '@/lib/format';
import type { Paginated, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { reactive } from 'vue';

const props = defineProps<{ pages: Paginated<any>; filters: { q?: string; status?: string } }>();
const page = usePage<SharedData>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? null });
watchDebounced(
    f,
    () =>
        router.get(route('audit.pages', page.props.currentWebsite!.id), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), {
            preserveState: true,
        }),
    { debounce: 300 },
);
</script>

<template>
    <SeoLayout title="Crawled pages" description="Latest snapshot of every URL the crawler discovered.">
        <Panel>
            <template #actions>
                <Input v-model="f.q" placeholder="Search URL or title" class="h-8 w-64" />
                <SelectField v-model="f.status" :options="['2xx', '3xx', '4xx', '5xx', 'error']" placeholder="Any status" class="h-8 w-32" />
            </template>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-4">URL</th>
                            <th class="pr-4">Status</th>
                            <th class="pr-4">Title</th>
                            <th class="pr-4">Words</th>
                            <th class="pr-4">Depth</th>
                            <th class="pr-4">Issues</th>
                            <th>Crawled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in pages.data" :key="p.id" class="border-t">
                            <td class="max-w-xs truncate py-1.5 pr-4">
                                <Link :href="route('audit.page', [page.props.currentWebsite!.id, p.id])" class="text-primary hover:underline">{{
                                    path(p.canonical_url)
                                }}</Link>
                            </td>
                            <td class="pr-4">
                                <span
                                    :class="[
                                        'tabular-nums',
                                        (p.status_code ?? 500) >= 400 ? 'text-red-600' : p.status_code >= 300 ? 'text-amber-600' : '',
                                    ]"
                                    >{{ p.status_code ?? 'error' }}</span
                                >
                            </td>
                            <td class="max-w-xs truncate pr-4">{{ p.title ?? '—' }}</td>
                            <td class="pr-4 tabular-nums">{{ number(p.word_count) }}</td>
                            <td class="pr-4 tabular-nums">{{ p.depth }}</td>
                            <td class="pr-4 tabular-nums">{{ p.open_issues_count }}</td>
                            <td class="whitespace-nowrap text-xs text-muted-foreground">{{ datetime(p.last_crawled_at) }}</td>
                        </tr>
                        <tr v-if="!pages.data.length">
                            <td colspan="7" class="py-3 text-muted-foreground">No pages yet. Run a crawl from Website Audit.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="pages.links" :total="pages.total" />
        </Panel>
    </SeoLayout>
</template>
