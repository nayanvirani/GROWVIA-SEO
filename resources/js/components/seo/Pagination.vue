<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ links: { url: string | null; label: string; active: boolean }[]; total?: number }>();
</script>

<template>
    <nav v-if="links.length > 3" class="mt-4 flex flex-wrap items-center gap-1 text-sm">
        <template v-for="(l, i) in links" :key="i">
            <Link
                v-if="l.url"
                :href="l.url"
                preserve-scroll
                :class="['rounded-md px-3 py-1', l.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent']"
                ><span v-html="l.label"
            /></Link>
            <span v-else class="px-3 py-1 text-muted-foreground" v-html="l.label" />
        </template>
        <span v-if="total !== undefined" class="ml-auto text-xs text-muted-foreground">{{ total }} total</span>
    </nav>
</template>
