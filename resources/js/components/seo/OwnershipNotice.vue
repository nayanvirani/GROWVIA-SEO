<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const website = computed(() => page.props.currentWebsite);
const agreed = ref(false);
const confirm = () => router.patch(route('websites.update', website.value!.id), { ownership_confirmed: true }, { preserveScroll: true });
</script>

<template>
    <div
        v-if="website && !website.ownership_confirmed"
        class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
    >
        <p class="flex items-center gap-2 text-sm font-semibold"><ShieldAlert class="h-4 w-4" /> Crawling is locked until you confirm ownership</p>
        <p class="mt-1 text-sm">Only crawl websites you own or are authorised to analyse, and whose robots.txt and hosting policy allow it.</p>
        <label class="mt-3 flex items-start gap-2 text-sm">
            <input v-model="agreed" type="checkbox" class="mt-0.5" />
            I own {{ website.normalized_host }} or am authorised to analyse it.
        </label>
        <Button size="sm" class="mt-3" :disabled="!agreed" @click="confirm">Confirm and unlock crawling</Button>
    </div>
</template>
