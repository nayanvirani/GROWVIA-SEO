<script setup lang="ts">
defineProps<{ options: (string | { value: string | number | null; label: string })[]; placeholder?: string }>();
const model = defineModel<string | number | null | undefined>();
</script>

<template>
    <select
        v-model="model"
        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
        <option v-if="placeholder !== undefined" :value="null">{{ placeholder }}</option>
        <option v-for="o in options" :key="typeof o === 'string' ? o : String(o.value)" :value="typeof o === 'string' ? o : o.value">
            {{ typeof o === 'string' ? o.replace(/_/g, ' ') : o.label }}
        </option>
    </select>
</template>
