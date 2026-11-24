<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value: string | null | undefined; kind?: 'severity' | 'status' | 'intent' | 'neutral' }>();

const tones: Record<string, string> = {
    high: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    medium: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    low: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    info: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    approved_for_export: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    connected: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    placed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    resolved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    exported: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    published_externally: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    queued: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    generating: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    partial: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    human_edit: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    content_draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    generation_failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    transactional: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    commercial: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    informational: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    navigational: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
};

const cls = computed(() => tones[props.value ?? ''] ?? 'bg-muted text-muted-foreground');
const text = computed(() => (props.value ?? '—').replace(/_/g, ' '));
</script>

<template>
    <span :class="['inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium capitalize', cls]">{{ text }}</span>
</template>
