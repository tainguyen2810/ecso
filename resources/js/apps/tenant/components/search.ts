/** Helpers of the global search (Ctrl + K). */

/** Lower case without Vietnamese marks, so "tao don" finds "Tạo đơn". */
export function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim();
}

/** Every word of the query appears in the text (any order, marks ignored). */
export function matches(text: string, query: string): boolean {
    const t = fold(text);
    return fold(query)
        .split(/\s+/)
        .filter(Boolean)
        .every((w) => t.includes(w));
}

export type RecentItem = { kind: 'order' | 'customer' | 'product' | 'supplier'; title: string; detail: string; to: string };

const MAX_RECENT = 5;

/** Recently opened results, per tenant, newest first (browser storage; empty when it is unavailable). */
export function readRecent(tenant: string): RecentItem[] {
    try {
        const raw = localStorage.getItem(`ecso.search.recent.${tenant}`);
        const list = raw ? (JSON.parse(raw) as RecentItem[]) : [];
        return Array.isArray(list) ? list.slice(0, MAX_RECENT) : [];
    } catch {
        return [];
    }
}

export function pushRecent(tenant: string, item: RecentItem): RecentItem[] {
    const list = [item, ...readRecent(tenant).filter((r) => r.to !== item.to)].slice(0, MAX_RECENT);
    try {
        localStorage.setItem(`ecso.search.recent.${tenant}`, JSON.stringify(list));
    } catch {
        // Private mode or storage full: search still works without the recent list.
    }
    return list;
}
