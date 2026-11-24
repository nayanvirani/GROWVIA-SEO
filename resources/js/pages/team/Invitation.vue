<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    token: string;
    invitation: { workspace: string; role: string; email: string; inviter: string | null; valid: boolean } | null;
    loggedInAs: string | null;
}>();
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string>)?.invitation);
const emailMatches = computed(() => props.loggedInAs?.toLowerCase() === props.invitation?.email);
const accept = () => router.post(route('invitations.accept', props.token));
</script>

<template>
    <AuthLayout title="Workspace invitation" :description="invitation ? `Join ${invitation.workspace}` : 'Invitation not found'">
        <Head title="Workspace invitation" />
        <div v-if="!invitation || !invitation.valid" class="text-center text-sm text-muted-foreground">
            This invitation link is invalid, expired or already used. Ask the workspace admin for a new one.
        </div>
        <div v-else class="grid gap-4 text-center text-sm">
            <p>
                <strong>{{ invitation.inviter ?? 'A teammate' }}</strong> invited <strong>{{ invitation.email }}</strong> to join
                <strong>{{ invitation.workspace }}</strong> as <strong class="capitalize">{{ invitation.role }}</strong
                >.
            </p>
            <InputError :message="error" />
            <template v-if="loggedInAs">
                <Button v-if="emailMatches" @click="accept">Accept invitation</Button>
                <p v-else class="text-amber-700">You're logged in as {{ loggedInAs }}. Log out and sign in as {{ invitation.email }} to accept.</p>
            </template>
            <div v-else class="flex justify-center gap-2">
                <Link :href="route('login')"><Button>Log in</Button></Link>
                <Link :href="route('register')"><Button variant="outline">Create account</Button></Link>
            </div>
        </div>
    </AuthLayout>
</template>
