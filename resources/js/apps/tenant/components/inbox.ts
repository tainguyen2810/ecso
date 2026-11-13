import { ref } from 'vue';

/** The bell's data (GET /inbox) and what this browser remembers about it. */
export type InboxItem = {
    key: string;
    group: 'orders' | 'ops' | 'money';
    tone: 'red' | 'amber' | 'blue' | 'green';
    count: number;
    title: string;
    detail: string | null;
    to: string;
};

/** Last list the bell loaded, shared with the phone menu (badge on "Đơn hàng"). */
export const inboxItems = ref<InboxItem[]>([]);

/** Orders waiting for the member's hands (confirm or pack), shown on the phone menu. */
export function ordersWaiting(items: InboxItem[]): number {
    return items.filter((i) => i.key === 'orders.to_confirm' || i.key === 'orders.to_pack').reduce((s, i) => s + i.count, 0);
}

/** Kinds that can be switched off in "Cài đặt thông báo". */
export const inboxKinds: { key: string; label: string }[] = [
    { key: 'orders.failed_delivery', label: 'Đơn giao thất bại' },
    { key: 'orders.overdue', label: 'Đơn quá hạn giao' },
    { key: 'orders.due_soon', label: 'Đơn sắp quá hạn giao' },
    { key: 'orders.shortage', label: 'Đơn thiếu hàng' },
    { key: 'orders.to_confirm', label: 'Đơn chờ xác nhận' },
    { key: 'orders.to_pack', label: 'Đơn chờ đóng gói' },
    { key: 'sync.disconnected', label: 'Shop mất kết nối' },
    { key: 'sync.problems', label: 'Lỗi đồng bộ' },
    { key: 'sync.token_expiring', label: 'Kết nối sàn sắp hết hạn' },
    { key: 'stock.low', label: 'SKU dưới tồn an toàn' },
    { key: 'cash.pending', label: 'Phiếu chi chờ duyệt' },
    { key: 'suppliers.overdue', label: 'Nợ nhà cung cấp quá hạn' },
];

/** Rows that are new or grew since they were marked as seen. */
export function unseenCount(items: InboxItem[], seen: Record<string, number>): number {
    return items.filter((i) => i.count > (seen[i.key] ?? 0)).length;
}

export function readJson<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
        return fallback;
    }
}

export function writeJson(key: string, value: unknown): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage unavailable: the bell still works, it just forgets what was seen.
    }
}
