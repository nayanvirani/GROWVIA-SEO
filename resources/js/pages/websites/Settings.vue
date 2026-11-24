<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { datetime } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    website: any;
    settings: Record<string, any>;
    verification: { verified_at: string | null; meta_name: string };
}>();
const page = usePage<SharedData>();
const token = computed(() => page.props.flash?.data?.verification_token as string | undefined);

const form = useForm({
    name: props.website.name,
    website_type: props.website.website_type,
    language: props.website.language,
    target_country: props.website.target_country,
    crawl_enabled: props.website.crawl_enabled,
    autopilot: props.settings.autopilot,
    ownership_confirmed: props.website.ownership_confirmed,
    crawl_max_pages: props.settings.crawl_max_pages,
    crawl_max_depth: props.settings.crawl_max_depth,
    crawl_frequency: props.settings.crawl_frequency,
    exclusion_paths: (props.settings.exclusion_paths ?? []).join('\n'),
    description: props.settings.description,
    brand_voice: props.settings.brand_voice,
    brand_facts: props.settings.brand_facts,
    forbidden_claims: props.settings.forbidden_claims,
});
const save = () =>
    form
        .transform((d) => ({ ...d, target_country: d.target_country || null }))
        .patch(route('websites.update', props.website.id), { preserveScroll: true });
const startVerify = () => router.post(route('websites.verification.start', props.website.id), {}, { preserveScroll: true });
const checkVerify = () => router.post(route('websites.verification.check', props.website.id), { token: token.value }, { preserveScroll: true });
const confirmDelete = ref('');
const destroy = () => router.delete(route('websites.destroy', props.website.id));
</script>

<template>
    <SeoLayout title="Website settings" :description="website.base_url">
        <form class="grid gap-4 lg:grid-cols-2" @submit.prevent="save">
            <Panel title="Project">
                <div class="grid gap-3">
                    <div class="grid gap-1.5"><Label>Name</Label><Input v-model="form.name" /><InputError :message="form.errors.name" /></div>
                    <div class="grid gap-1.5"><Label>Website type</Label><Input v-model="form.website_type" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1.5"><Label>Language</Label><Input v-model="form.language" /></div>
                        <div class="grid gap-1.5">
                            <Label>Target country</Label
                            ><Input v-model="form.target_country" maxlength="2" placeholder="Choose explicitly, e.g. us" />
                            <InputError :message="form.errors.target_country" />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.ownership_confirmed" type="checkbox" class="mt-0.5" />
                        I own or am authorised to analyse this website (required before crawling).
                    </label>
                </div>
            </Panel>
            <Panel title="Crawl policy" description="Safe defaults from the guide: 300 pages, depth 5, 1 request/second.">
                <div class="grid gap-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.crawl_enabled" type="checkbox" /> Crawling enabled</label>
                    <label class="flex items-start gap-2 text-sm"
                        ><input v-model="form.autopilot" type="checkbox" class="mt-0.5" />
                        <span
                            ><strong>Autopilot</strong> — every week, crawl the site, research keywords, plan articles and find link opportunities
                            automatically.</span
                        ></label
                    >
                    <div class="grid grid-cols-3 gap-2">
                        <div class="grid gap-1.5">
                            <Label>Max pages</Label><Input v-model="form.crawl_max_pages" type="number" min="1" /><InputError
                                :message="form.errors.crawl_max_pages"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Max depth</Label><Input v-model="form.crawl_max_depth" type="number" min="0" max="10" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Frequency</Label><SelectField v-model="form.crawl_frequency" :options="['weekly', 'monthly', 'manual']" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Excluded paths (one per line)</Label
                        ><TextArea v-model="form.exclusion_paths" :rows="3" placeholder="/cart&#10;/account" />
                    </div>
                </div>
            </Panel>
            <Panel title="Brand & AI context" description="AI drafts may only use facts you provide here or on crawled pages." class="lg:col-span-2">
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="grid gap-1.5"><Label>Description</Label><TextArea v-model="form.description" :rows="3" /></div>
                    <div class="grid gap-1.5"><Label>Brand voice</Label><TextArea v-model="form.brand_voice" :rows="3" /></div>
                    <div class="grid gap-1.5">
                        <Label>Approved brand facts</Label
                        ><TextArea v-model="form.brand_facts" :rows="6" placeholder="Features, integrations and claims you have verified." />
                    </div>
                    <div class="grid gap-1.5"><Label>Forbidden claims</Label><TextArea v-model="form.forbidden_claims" :rows="6" /></div>
                </div>
            </Panel>
            <div class="lg:col-span-2"><Button :disabled="form.processing">Save settings</Button></div>
        </form>

        <Panel title="Ownership verification (optional)" description="Proves control of the domain with a meta tag on the homepage.">
            <p class="mb-3 text-sm">
                Status:
                <Badge :value="verification.verified_at ? 'approved' : 'queued'" />
                <span v-if="verification.verified_at" class="ml-2 text-muted-foreground">verified {{ datetime(verification.verified_at) }}</span>
            </p>
            <div v-if="token" class="space-y-2">
                <p class="text-sm">Add this tag to the &lt;head&gt; of your homepage, deploy it, then check:</p>
                <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs">
&lt;meta name="{{ verification.meta_name }}" content="{{ token }}"&gt;</pre
                >
                <Button variant="outline" @click="checkVerify">Check now</Button>
            </div>
            <Button v-else variant="outline" @click="startVerify">Generate verification tag</Button>
        </Panel>

        <Panel title="Danger zone">
            <p class="mb-2 text-sm text-muted-foreground">
                Deleting removes all pages, issues, keywords, drafts and prospects for this website. Type the host to confirm.
            </p>
            <div class="flex gap-2">
                <Input v-model="confirmDelete" :placeholder="page.props.currentWebsite?.normalized_host" class="max-w-sm" />
                <Button variant="destructive" :disabled="confirmDelete !== page.props.currentWebsite?.normalized_host" @click="destroy"
                    >Delete website</Button
                >
            </div>
        </Panel>
    </SeoLayout>
</template>
