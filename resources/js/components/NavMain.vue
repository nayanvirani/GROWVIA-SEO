<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';

defineProps<{
    items: { title: string; url: string; icon: Component }[];
    label?: string;
}>();

const page = usePage();
const isActive = (url: string) => {
    const path = new URL(url, window.location.origin).pathname;
    const current = page.url.split('?')[0];
    return current === path || (path.split('/').length > 3 && current.startsWith(path + '/'));
};
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label ?? 'Platform' }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="isActive(item.url)" :tooltip="item.title">
                    <Link :href="item.url">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
