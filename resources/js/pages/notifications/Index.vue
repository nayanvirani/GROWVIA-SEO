<script setup lang="ts">
import EmptyState from '@/components/seo/EmptyState.vue';
import Pagination from '@/components/seo/Pagination.vue';
import Panel from '@/components/seo/Panel.vue';
import { Button } from '@/components/ui/button';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/vue3';

defineProps<{
    notifications: Paginated<{ id: string; read_at: string | null; created_at: string; data: { title: string; body: string; type: string } }>;
}>();
const readAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
</script>

<template>
    <SeoLayout
        title="Notifications"
        description="Crawl results, drafts ready for review, weekly digests and reminders for every website in your workspace."
    >
        <template #actions>
            <Button variant="outline" @click="readAll">Mark all read</Button>
        </template>
        <Panel>
            <EmptyState v-if="!notifications.data.length" title="No notifications yet" />
            <ul class="divide-y">
                <li v-for="n in notifications.data" :key="n.id">
                    <Link :href="route('notifications.open', n.id)" class="flex items-start justify-between gap-3 py-3 hover:bg-muted/40">
                        <div class="flex min-w-0 items-start gap-2">
                            <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-blue-600']" />
                            <div class="min-w-0">
                                <p :class="['text-sm', !n.read_at && 'font-semibold']">{{ n.data.title }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ n.data.body }}</p>
                            </div>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ datetime(n.created_at) }}</span>
                    </Link>
                </li>
            </ul>
            <Pagination :links="notifications.links" :total="notifications.total" />
        </Panel>
    </SeoLayout>
</template>
