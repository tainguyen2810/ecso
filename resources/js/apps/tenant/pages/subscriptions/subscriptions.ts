/** Thuê bao, gia hạn: labels and date helpers shared by the list, the detail page and the renew dialog. */
import type { SubscriptionRow } from '@/shared/types';

export interface SubscriptionPlan {
    id: string;
    sku: string;
    name: string | null;
    period_months: number | null;
    ref_price: string | null;
    wholesale_price: string | null;
}

export interface SubscriptionDetailData extends SubscriptionRow {
    note: string | null;
    remind: boolean;
    rules: { grace_days: number; auto_order_days: number | null; remind: boolean };
    customer_since: string | null;
    paid_total: string;
    periods: {
        id: number;
        starts_on: string;
        ends_on: string;
        months: number;
        amount: string;
        status: 'pending' | 'active' | 'void';
        current: boolean;
        variant: { id: string; sku: string; name: string | null } | null;
        order: { id: string; number: string; status: string; due: string } | null;
    }[];
    events: { id: number; kind: string; note: string | null; user: string | null; at: string | null }[];
    plans: SubscriptionPlan[];
}

export const stateLabels: Record<SubscriptionRow['state'], { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' | 'info' | 'default' }> = {
    pending: { label: 'Chờ xác nhận', tone: 'info' },
    active: { label: 'Đang dùng', tone: 'success' },
    expiring: { label: 'Sắp hết hạn', tone: 'warning' },
    overdue: { label: 'Quá hạn', tone: 'danger' },
    expired: { label: 'Đã hết hạn', tone: 'muted' },
    paused: { label: 'Tạm dừng', tone: 'muted' },
    cancelled: { label: 'Đã huỷ', tone: 'muted' },
};

export const day = (d: string | null | undefined) => (d ? d.split('-').reverse().join('/') : '—');

export function isoDay(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(iso: string, n: number): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);
    return isoDay(d);
}

/** Start + months − 1 day, the way the server counts a period (31/01 + 1 month ends 28/02). */
export function periodEnd(start: string, months: number): string {
    const [y, m, d] = start.split('-').map(Number) as [number, number, number];
    const target = new Date(y, m - 1 + months, 1);
    const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    return isoDay(new Date(target.getFullYear(), target.getMonth(), Math.min(d, last) - 1));
}

/** Where the next period starts: the day after the end while within the grace days, else today. */
export function nextStart(sub: Pick<SubscriptionRow, 'ends_on' | 'days_left' | 'grace_days'>): string {
    if (sub.ends_on && (sub.days_left ?? 0) >= -sub.grace_days) return addDays(sub.ends_on, 1);
    return isoDay(new Date());
}

/** "còn 12 ngày", "hết hạn hôm nay", "quá 7 ngày". */
export function leftText(left: number | null): string {
    if (left === null) return 'Chưa bắt đầu';
    if (left > 0) return `Còn ${left} ngày`;
    if (left === 0) return 'Hết hạn hôm nay';
    return `Quá ${-left} ngày`;
}

export const eventLabels: Record<string, string> = {
    created: 'Tạo thuê bao',
    renewed: 'Gia hạn',
    renewal_order: 'Đơn gia hạn',
    auto_order: 'Tự tạo đơn',
    period_void: 'Bỏ kỳ',
    reminded: 'Nhắc khách',
    paused: 'Tạm dừng',
    resumed: 'Dùng lại',
    cancelled: 'Huỷ',
    changed: 'Sửa thông tin',
};
