import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface WebsiteSummary {
    id: string;
    name: string;
    normalized_host: string;
    base_url?: string;
    ownership_confirmed?: boolean;
    status?: string;
}

export interface SharedData {
    name: string;
    auth: Auth;
    workspace: { id: string; name: string } | null;
    websites: WebsiteSummary[];
    currentWebsite: WebsiteSummary | null;
    providers: { ai: string; seo_data: string };
    flash: { success: string | null; error: string | null; data: Record<string, any> | null };
    errors: Record<string, string>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export type BreadcrumbItemType = BreadcrumbItem;
