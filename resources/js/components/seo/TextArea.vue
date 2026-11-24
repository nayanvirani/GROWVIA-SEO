<script setup lang="ts">
defineProps<{ rows?: number; placeholder?: string }>();
const model = defineModel<string | null | undefined>();
</script>

<template>
    <textarea
        v-model="model"
        :rows="rows ?? 4"
        :placeholder="placeholder"
        class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    />
</template>
