export const number = (n: number | null | undefined) => (n === null || n === undefined ? '—' : new Intl.NumberFormat().format(n));
export const percent = (n: number | null | undefined, digits = 1) => (n === null || n === undefined ? '—' : `${(n * 100).toFixed(digits)}%`);
export const date = (s: string | null | undefined) => (s ? new Date(s).toLocaleDateString() : '—');
export const datetime = (s: string | null | undefined) => (s ? new Date(s).toLocaleString() : '—');
export const label = (s: string | null | undefined) => (s ? s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : '—');
export const path = (url: string | null | undefined) => {
    if (!url) return '—';
    try {
        const u = new URL(url);
        return u.pathname + u.search;
    } catch {
        return url;
    }
};
