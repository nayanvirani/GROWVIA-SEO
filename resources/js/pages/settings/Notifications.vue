<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ preferences: { email_digest: boolean; email_reminders: boolean } }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notification settings', href: '/settings/notifications' }];
const form = useForm({ ...props.preferences });
const save = () => form.patch(route('notifications.preferences.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notification settings" />
        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Email notifications" description="In-app notifications are always on. Choose which ones are also emailed." />
                <form class="space-y-4" @submit.prevent="save">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.email_digest" type="checkbox" class="mt-0.5" />
                        <span
                            ><strong>Weekly SEO digest</strong><br /><span class="text-muted-foreground"
                                >New and resolved issues, drafts awaiting review, traffic and provider usage.</span
                            ></span
                        >
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.email_reminders" type="checkbox" class="mt-0.5" />
                        <span
                            ><strong>Stale draft reminders</strong><br /><span class="text-muted-foreground"
                                >Drafts untouched for 14+ days.</span
                            ></span
                        >
                    </label>
                    <div class="flex items-center gap-3">
                        <Button :disabled="form.processing">Save</Button>
                        <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</span>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
