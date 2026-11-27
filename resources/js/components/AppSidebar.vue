<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Activity, BadgeCheck, FileText, Gauge, Image, KeyRound, Link2, Plug, Plus, Search, Settings2, Users, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const website = computed(() => page.props.currentWebsite);

const siteNav = computed(() => {
    const w = website.value;
    if (!w) return [];
    return [
        { title: 'SEO Overview', url: route('websites.show', w.id), icon: Gauge },
        { title: 'Website Audit', url: route('audit.index', w.id), icon: Search },
        { title: 'Pages', url: route('audit.pages', w.id), icon: FileText },
        { title: 'Keyword Research', url: route('keywords.index', w.id), icon: KeyRound },
        { title: 'Content Studio', url: route('content.index', w.id), icon: Activity },
        { title: 'Image Studio', url: route('images.index', w.id), icon: Image },
        { title: 'Backlinks', url: route('backlinks.index', w.id), icon: Link2 },
        { title: 'Get listed', url: route('listings.index', w.id), icon: BadgeCheck },
        { title: 'Automation', url: route('automation.index', w.id), icon: Workflow },
        { title: 'Website Settings', url: route('websites.settings', w.id), icon: Settings2 },
    ];
});
const workspaceNav = [
    { title: 'Team', url: route('team.index'), icon: Users },
    { title: 'AI & data providers', url: route('providers.index'), icon: Plug },
];
const canManage = computed(() => ['owner', 'admin'].includes(page.props.workspace?.role ?? ''));
function switchWorkspace(e: Event) {
    const id = (e.target as HTMLSelectElement).value;
    if (id && id !== page.props.workspace?.id) router.post(route('workspaces.switch', id));
}

function switchWebsite(e: Event) {
    const id = (e.target as HTMLSelectElement).value;
    if (id === '__new') router.visit(route('websites.create'));
    else if (id) router.visit(route('websites.show', id));
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <div v-if="page.props.workspaces.length > 1" class="px-2 group-data-[collapsible=icon]:hidden">
                <label class="mb-1 block text-xs text-muted-foreground" for="workspace-switcher">Workspace</label>
                <select
                    id="workspace-switcher"
                    :value="page.props.workspace?.id"
                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    @change="switchWorkspace"
                >
                    <option v-for="w in page.props.workspaces" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
            </div>
            <div class="px-2 group-data-[collapsible=icon]:hidden">
                <label class="mb-1 block text-xs text-muted-foreground" for="website-switcher">Website</label>
                <select
                    id="website-switcher"
                    :value="website?.id ?? ''"
                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                    @change="switchWebsite"
                >
                    <option value="" disabled>Select a website</option>
                    <option v-for="w in page.props.websites" :key="w.id" :value="w.id">{{ w.name }} ({{ w.normalized_host }})</option>
                    <option v-if="canManage" value="__new">+ Add website…</option>
                </select>
            </div>
        </SidebarHeader>

        <SidebarContent>
            <NavMain v-if="siteNav.length" :items="siteNav" label="Website" />
            <NavMain :items="workspaceNav" label="Workspace" />
            <div v-if="!siteNav.length && canManage" class="px-4 group-data-[collapsible=icon]:hidden">
                <Link :href="route('websites.create')" class="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                    ><Plus class="h-4 w-4" /> Add website</Link
                >
            </div>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
