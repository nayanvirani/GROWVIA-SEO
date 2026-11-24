<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Panel from '@/components/seo/Panel.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    base_url: 'https://',
    website_type: '',
    language: 'en',
    target_country: '',
    description: '',
    ownership_confirmed: false,
    competitors: ['', '', ''],
});

const submit = () =>
    form
        .transform((d) => ({ ...d, target_country: d.target_country || null, competitors: d.competitors.filter(Boolean) }))
        .post(route('websites.store'));
</script>

<template>
    <SeoLayout
        title="Add a website"
        description="Each website is a separate project. Its pages, issues, keywords and content never mix with other projects."
    >
        <form class="grid max-w-3xl gap-4" @submit.prevent="submit">
            <Panel title="Website">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="name">Project name</Label>
                        <Input id="name" v-model="form.name" placeholder="Growvia" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="base_url">Base URL</Label>
                        <Input id="base_url" v-model="form.base_url" placeholder="https://example.com/" required />
                        <InputError :message="form.errors.base_url" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="type">Website type</Label>
                        <Input id="type" v-model="form.website_type" placeholder="SaaS / Shopify app marketing website" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1.5">
                            <Label for="language">Language</Label>
                            <Input id="language" v-model="form.language" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="country">Target country</Label>
                            <Input id="country" v-model="form.target_country" maxlength="2" placeholder="us" />
                            <InputError :message="form.errors.target_country" />
                        </div>
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="desc">Short description (used as AI context)</Label>
                        <TextArea
                            id="desc"
                            v-model="form.description"
                            :rows="3"
                            placeholder="What the website offers and who it is for. Facts only."
                        />
                    </div>
                </div>
            </Panel>
            <Panel title="Competitors (optional)" description="Add domains you have confirmed are relevant competitors.">
                <div class="grid gap-2 sm:grid-cols-3">
                    <Input v-for="(_, i) in form.competitors" :key="i" v-model="form.competitors[i]" placeholder="competitor.com" />
                </div>
            </Panel>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.ownership_confirmed" type="checkbox" class="mt-0.5 rounded border-input" />
                <span>I own this website or am authorised to analyse it, and its hosting policy permits crawling.</span>
            </label>
            <InputError :message="form.errors.ownership_confirmed" />
            <div><Button :disabled="form.processing">Create project</Button></div>
        </form>
    </SeoLayout>
</template>
