<script setup lang="ts">
import Badge from '@/components/seo/Badge.vue';
import EmptyState from '@/components/seo/EmptyState.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import TextArea from '@/components/seo/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SeoLayout from '@/layouts/SeoLayout.vue';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Sparkles, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ images: any[]; drafts: { id: string; title: string }[] }>();
const page = usePage<SharedData>();
const w = computed(() => page.props.currentWebsite!);
const form = useForm({ topic: '', content_draft_id: null as string | null, aspect_ratio: '16:9', placement: 'featured image', audience: '' });
const submit = () => form.post(route('images.store', w.value.id), { preserveScroll: true, onSuccess: () => form.reset('topic') });
const update = (img: any, data: Record<string, unknown>) => router.patch(route('images.update', [w.value.id, img.id]), data, { preserveScroll: true });
const destroy = (img: any) => router.delete(route('images.destroy', [w.value.id, img.id]), { preserveScroll: true });
const copy = (t: string) => navigator.clipboard?.writeText(t);
</script>

<template>
    <SeoLayout title="Image studio" description="Image briefs, prompts and alt text. Image generation is optional and off until a provider and budget are configured.">
        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="New image brief">
                <form class="grid gap-3" @submit.prevent="submit">
                    <div class="grid gap-1.5"><Label>Topic</Label><Input v-model="form.topic" placeholder="Shopify free gift campaigns" /></div>
                    <div class="grid gap-1.5"><Label>…or for a draft</Label><SelectField v-model="form.content_draft_id" :options="drafts.map((d) => ({ value: d.id, label: d.title }))" placeholder="None" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1.5"><Label>Aspect ratio</Label><SelectField v-model="form.aspect_ratio" :options="['16:9', '1:1', '4:3', '3:2', '9:16']" /></div>
                        <div class="grid gap-1.5"><Label>Placement</Label><Input v-model="form.placement" /></div>
                    </div>
                    <Button :disabled="form.processing || (!form.topic && !form.content_draft_id)"><Sparkles /> Create brief</Button>
                    <p class="text-xs text-muted-foreground">Never use generated images with fake product screenshots, logos or UI that could misrepresent the real product.</p>
                </form>
            </Panel>
            <div class="grid content-start gap-3 lg:col-span-2">
                <EmptyState v-if="!images.length" title="No image briefs yet" />
                <Panel v-for="img in images" :key="img.id" :title="img.suggested_filename ?? 'Image brief'" :description="`${img.aspect_ratio} · ${img.placement ?? ''}${img.draft ? ' · ' + img.draft.title : ''} · ${img.provider}`">
                    <template #actions>
                        <Badge :value="img.status" />
                        <Button size="sm" variant="ghost" @click="destroy(img)"><Trash2 /></Button>
                    </template>
                    <div class="grid gap-3 text-sm">
                        <p>{{ img.brief }}</p>
                        <div>
                            <Label>Prompt</Label>
                            <div class="mt-1 flex gap-2"><TextArea :model-value="img.prompt" :rows="2" @change="update(img, { prompt: ($event.target as HTMLTextAreaElement).value })" /><Button size="icon" variant="ghost" @click="copy(img.prompt)"><Copy /></Button></div>
                        </div>
                        <div>
                            <Label>Alt text (describe the actual image, no keyword stuffing)</Label>
                            <Input class="mt-1" :model-value="img.alt_text" @change="update(img, { alt_text: ($event.target as HTMLInputElement).value })" />
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" @click="update(img, { status: 'approved' })">Approve</Button>
                            <Button size="sm" variant="ghost" @click="update(img, { status: 'rejected' })">Reject</Button>
                        </div>
                    </div>
                </Panel>
            </div>
        </div>
    </SeoLayout>
</template>
