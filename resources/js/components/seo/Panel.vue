<script setup lang="ts">
defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-start justify-between gap-2 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
        >
            <div>
                <h2 class="text-sm font-semibold">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </header>
        <div class="p-4"><slot /></div>
    </section>
</template>
