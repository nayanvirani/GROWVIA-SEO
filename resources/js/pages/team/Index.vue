<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Badge from '@/components/seo/Badge.vue';
import Panel from '@/components/seo/Panel.vue';
import SelectField from '@/components/seo/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SeoLayout from '@/layouts/SeoLayout.vue';
import { date } from '@/lib/format';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Link2, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    workspace: { id: string; name: string };
    members: { id: number; name: string; email: string; role: string }[];
    invitations: { id: string; email: string; role: string; expires_at: string; inviter: { name: string } | null }[];
    myRole: string;
    canManage: boolean;
    roles: string[];
}>();
const page = usePage<SharedData>();
const me = page.props.auth.user.id;
const inviteLink = computed(() => page.props.flash?.data?.invite_link as string | undefined);
const copy = (t: string) => navigator.clipboard?.writeText(t);

const rename = useForm({ name: props.workspace.name });
const invite = useForm({ email: '', role: 'editor' });
const sendInvite = () => invite.post(route('team.invite'), { preserveScroll: true, onSuccess: () => invite.reset('email') });
const setRole = (id: number, role: string) => router.patch(route('team.members.update', id), { role }, { preserveScroll: true });
const remove = (m: { id: number; name: string }) => {
    const self = m.id === me;
    if (confirm(self ? 'Leave this workspace?' : `Remove ${m.name} from the workspace?`))
        router.delete(route('team.members.remove', m.id), { preserveScroll: true });
};
const revoke = (id: string) => router.delete(route('team.invitations.revoke', id), { preserveScroll: true });

const roleHelp: Record<string, string> = {
    owner: 'Everything, including managing owners',
    admin: 'Manage members, websites and integrations',
    editor: 'Run crawls, research keywords, write and approve content',
    viewer: 'Read-only access',
};
</script>

<template>
    <SeoLayout
        title="Team"
        :description="`Members of the ${workspace.name} workspace. Every website in this workspace is shared with them according to their role.`"
    >
        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Members" class="lg:col-span-2">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-4">Name</th>
                            <th class="pr-4">Email</th>
                            <th class="pr-4">Role</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in members" :key="m.id" class="border-t">
                            <td class="py-2 pr-4">{{ m.name }}<span v-if="m.id === me" class="text-muted-foreground"> (you)</span></td>
                            <td class="pr-4 text-muted-foreground">{{ m.email }}</td>
                            <td class="pr-4">
                                <select
                                    v-if="canManage && m.id !== me"
                                    :value="m.role"
                                    class="h-8 rounded border bg-background px-1 text-xs capitalize"
                                    @change="setRole(m.id, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="r in roles" :key="r" :value="r" :disabled="r === 'owner' && myRole !== 'owner'">{{ r }}</option>
                                </select>
                                <Badge v-else :value="m.role" />
                            </td>
                            <td class="text-right">
                                <Button v-if="canManage || m.id === me" size="sm" variant="ghost" @click="remove(m)"
                                    >{{ m.id === me ? 'Leave' : '' }}<Trash2 v-if="m.id !== me"
                                /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Panel>

            <div class="grid content-start gap-4">
                <Panel
                    v-if="canManage"
                    title="Invite someone"
                    description="Creates a private link valid for 7 days. No email is sent — you share the link yourself."
                >
                    <form class="grid gap-2" @submit.prevent="sendInvite">
                        <Input v-model="invite.email" type="email" placeholder="name@company.com" required />
                        <InputError :message="invite.errors.email" />
                        <SelectField v-model="invite.role" :options="roles.filter((r) => r !== 'owner' || myRole === 'owner')" />
                        <InputError :message="invite.errors.role" />
                        <p class="text-xs text-muted-foreground">{{ roleHelp[invite.role] }}</p>
                        <Button :disabled="invite.processing || !invite.email"><Link2 /> Create invite link</Button>
                    </form>
                    <div v-if="inviteLink" class="mt-3 grid gap-1 rounded-md border bg-muted/50 p-2 text-xs">
                        <span class="font-medium">Invite link (shown once):</span>
                        <div class="flex items-center gap-1">
                            <code class="flex-1 break-all">{{ inviteLink }}</code>
                            <Button size="icon" variant="ghost" @click="copy(inviteLink)"><Copy /></Button>
                        </div>
                    </div>
                </Panel>
                <Panel v-if="canManage" title="Workspace name">
                    <form class="flex gap-2" @submit.prevent="rename.patch(route('team.update'), { preserveScroll: true })">
                        <Input v-model="rename.name" />
                        <Button variant="outline" :disabled="rename.processing || rename.name === workspace.name">Save</Button>
                    </form>
                </Panel>
                <Panel title="Roles">
                    <dl class="space-y-1 text-sm">
                        <div v-for="r in roles" :key="r">
                            <dt class="inline font-medium capitalize">{{ r }}:</dt>
                            <dd class="inline text-muted-foreground">{{ roleHelp[r] }}</dd>
                        </div>
                    </dl>
                </Panel>
            </div>
        </div>

        <Panel v-if="invitations.length" title="Pending invitations">
            <ul class="divide-y text-sm">
                <li v-for="i in invitations" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ i.email }} <Badge :value="i.role" class="ml-1" /></span>
                    <span class="flex items-center gap-3 text-xs text-muted-foreground">
                        Invited by {{ i.inviter?.name ?? '—' }} · expires {{ date(i.expires_at) }}
                        <Button v-if="canManage" size="sm" variant="ghost" @click="revoke(i.id)">Revoke</Button>
                    </span>
                </li>
            </ul>
        </Panel>
    </SeoLayout>
</template>
