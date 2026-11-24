<script setup lang="ts">
defineProps<{ title: string; text?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-8 text-center">
        <p class="text-sm font-medium">{{ title }}</p>
        <p v-if="text" class="max-w-md text-sm text-muted-foreground">{{ text }}</p>
        <slot />
    </div>
</template>
