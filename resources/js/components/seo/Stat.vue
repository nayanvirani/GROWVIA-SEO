<script setup lang="ts">
defineProps<{ label: string; value: string | number; hint?: string; tone?: string }>();
</script>

<template>
    <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ label }}</p>
        <p :class="['mt-1 text-2xl font-semibold tabular-nums', tone]">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
