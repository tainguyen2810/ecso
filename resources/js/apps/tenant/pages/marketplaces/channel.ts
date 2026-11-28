/** Shared bits of the Kênh bán pages (mẫu Tailwind 28/11). The card, button and pill classes come from the Vận chuyển pages. */
import { formatNumber } from '@/shared/lib/utils';

export { btn, card, iconTones, pill, th } from '../shipping/ui';

/** Colour of each marketplace in charts and bars. */
export const channelColors: Record<string, string> = {
    shopee: '#f97316',
    tiktok: '#0f172a',
    woocommerce: '#8b5cf6',
    lazada: '#2563eb',
    tiki: '#0ea5e9',
    fake: '#94a3b8',
};
export const channelColor = (key: string) => channelColors[key] ?? '#64748b';

/** One shop's numbers from GET marketplace-stats. */
export interface ShopStats {
    id: string;
    marketplace: string;
    shop_name: string;
    orders_today: number;
    orders: number;
    kept: number;
    revenue: string;
    cancelled: number;
    returned: number;
    previous: { orders: number; revenue: string };
    fees: string | null;
    daily: { orders: number; revenue: string }[];
    listings: { total: number; mapped: number; suggested: number; unmapped: number };
    token_expires_at: string | null;
    open_errors: number;
}

export interface ChannelStats {
    range: { from: string; to: string; days: number };
    dates: string[];
    accounts: ShopStats[];
    open: Record<string, number>;
    top: { name: string; variant_name: string | null; sku: string | null; quantity: number; revenue: string }[];
    pending_prices: number;
    can_finance: boolean;
}

/** "12,4%" with one decimal, or "—". */
export function rate(part: number, whole: number): string {
    if (!whole) return '—';
    return `${(Math.round((part / whole) * 1000) / 10).toLocaleString('vi-VN')}%`;
}

/** Change against the previous period: "+12,4%" / "−3%", null when there is nothing to compare with. */
export function change(now: number, before: number): { up: boolean; text: string } | null {
    if (!before) return null;
    const d = Math.round(((now - before) / before) * 1000) / 10;
    return { up: d >= 0, text: `${d >= 0 ? '+' : '−'}${Math.abs(d).toLocaleString('vi-VN')}% so với kỳ trước` };
}

/** "dd/MM" of a Y-m-d date. */
export const dayLabel = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;

/** Days until an ISO time, rounded down; null when there is no time. */
export function daysLeft(iso: string | null | undefined): number | null {
    if (!iso) return null;
    return Math.floor((new Date(iso).getTime() - Date.now()) / 86_400_000);
}

/** "2 phút trước", "3 giờ trước", "hôm qua", or the date. */
export function ago(iso: string | null | undefined): string {
    if (!iso) return '—';
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60_000);
    if (m < 1) return 'vừa xong';
    if (m < 60) return `${m} phút trước`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h} giờ trước`;
    const d = Math.round(h / 24);
    return d === 1 ? 'hôm qua' : d < 30 ? `${d} ngày trước` : new Date(iso).toLocaleDateString('vi-VN');
}

export const n = (v: number | string | null | undefined) => formatNumber(v ?? 0);
