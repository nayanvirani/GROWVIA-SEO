<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ title: string; description?: string }>();
const page = usePage<SharedData>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [];
    const w = page.props.currentWebsite;
    if (w) items.push({ title: w.name, href: route('websites.show', w.id) });
    items.push({ title: props.title, href: '#' });
    return items;
});
const errorList = computed(() => Object.values(page.props.errors ?? {}));
</script>

<template>
    <Head :title="title" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ description }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
            </div>
            <div
                v-if="page.props.flash?.success"
                class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
            >
                <CheckCircle2 class="h-4 w-4" /> {{ page.props.flash.success }}
            </div>
            <div
                v-if="page.props.flash?.error || errorList.length"
                class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
            >
                <p v-if="page.props.flash?.error" class="flex items-center gap-2"><XCircle class="h-4 w-4" /> {{ page.props.flash.error }}</p>
                <p v-for="(e, i) in errorList" :key="i" class="flex items-center gap-2"><XCircle class="h-4 w-4" /> {{ e }}</p>
            </div>
            <slot />
        </div>
    </AppLayout>
</template>
