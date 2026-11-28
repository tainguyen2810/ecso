/** Kế toán, thuế: shapes the server returns and small helpers for periods. */
import { ref } from 'vue';

/** Invoices bought in not yet in a purchase order: the badge of the "Hoá đơn mua vào" tab, set by pages that know it. */
export const openInvoices = ref<number | null>(null);

/** Buttons of the page header (mẫu 28/11). */
export const BTN = 'inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] text-slate-700 hover:bg-slate-50 disabled:opacity-60';
export const BTN_PRIMARY = 'inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-60';
export const CARD = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
export type Group = 'goods' | 'service' | 'production' | 'other';

export interface TaxSettings {
    period: 'month' | 'quarter';
    threshold: number;
    taxpayer_name: string | null;
    default_group: Group;
    category_groups: Record<string, Group>;
    include_shipping: boolean;
    rates: Record<Group, { vat: number; pit: number }>;
    groups: Record<Group, string>;
}

export interface GroupRow {
    group: Group;
    label: string;
    self: number;
    market: number;
    vat: number;
    pit: number;
    vat_amount: number;
    pit_amount: number;
    withheld: number;
}

export interface Declaration {
    period: string;
    label: string;
    from: string;
    to: string;
    deadline: string;
    groups: GroupRow[];
    self: number;
    market: number;
    vat: number;
    pit: number;
    tax: number;
    withheld: number;
    taxpayer?: { name: string | null; store: string; tax_code: string | null; address: string | null; phone: string | null };
    returns?: number;
    orders?: number;
}

export interface Overview {
    year: number;
    settings: TaxSettings;
    revenue: number;
    threshold: number;
    reach_on: string | null;
    channels: { label: string; kind: 'self' | 'market'; mark: string; revenue: number; tax: number }[];
    periods: { period: string; label: string; self: number; market: number; vat: number; pit: number; tax: number; deadline: string }[];
    self: number;
    market: number;
    tax: number;
    withheld: number;
    current: Declaration;
    todo: { declare: { period: string; label: string; deadline: string }; invoices_review: number; invoices_open: number; invoices_open_vat: number };
}

export const BOOKS = [
    { key: 's1', code: 'S1-HKD', title: 'Sổ doanh thu bán hàng hoá, dịch vụ' },
    { key: 's2', code: 'S2-HKD', title: 'Sổ chi tiết vật liệu, hàng hoá' },
    { key: 's3', code: 'S3-HKD', title: 'Sổ chi phí sản xuất, kinh doanh' },
    { key: 's4', code: 'S4-HKD', title: 'Sổ theo dõi thuế phải nộp' },
] as const;
export type BookKey = (typeof BOOKS)[number]['key'];

/** "2026-Q4" / "2026-11" => the periods before and after, and a short label. */
export function shiftPeriod(period: string, by: number): string {
    const q = /^(\d{4})-Q([1-4])$/.exec(period);
    if (q) {
        const n = Number(q[1]) * 4 + Number(q[2]) - 1 + by;
        return `${Math.floor(n / 4)}-Q${(n % 4) + 1}`;
    }
    const m = /^(\d{4})-(\d{2})$/.exec(period);
    if (!m) return period;
    const n = Number(m[1]) * 12 + Number(m[2]) - 1 + by;
    return `${Math.floor(n / 12)}-${String((n % 12) + 1).padStart(2, '0')}`;
}

export function periodLabel(period: string): string {
    const q = /^(\d{4})-Q([1-4])$/.exec(period);
    if (q) return `Quý ${q[2]}/${q[1]}`;
    const m = /^(\d{4})-(\d{2})$/.exec(period);
    return m ? `Tháng ${Number(m[2])}/${m[1]}` : period;
}

export const dmy = (d: string | null | undefined) => (d ? d.split('-').reverse().join('/') : '—');

/** Share of the threshold reached, 0–100, and the money still left before it. */
export function thresholdProgress(revenue: number, threshold: number): { pct: number; left: number } {
    if (threshold <= 0) return { pct: 100, left: 0 };
    return { pct: Math.min(100, Math.max(0, Math.round((revenue / threshold) * 1000) / 10)), left: Math.max(0, threshold - revenue) };
}

export interface BooksSummary {
    s1: { self: number; market: number; total: number; withheld: number };
    s2: { items: number; closing_value: number; truncated: boolean } | null;
    s3: { amount: number; vouchers: number };
    s4: { tax: number; periods: number; year: number };
}

/** Whole days from today to a date (negative once it has passed). */
export function daysUntil(date: string, today = new Date()): number {
    const [y, m, d] = date.split('-').map(Number);
    const a = Date.UTC(y!, m! - 1, d!);
    const b = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    return Math.round((a - b) / 86400000);
}

/** "71,2 tr" / "1,3 tỷ" for small figures in cards. */
export function short(n: number): string {
    const a = Math.abs(n);
    const f = (x: number) => x.toLocaleString('vi-VN', { maximumFractionDigits: 1 });
    if (a >= 1e9) return `${f(n / 1e9)} tỷ`;
    if (a >= 1e6) return `${f(n / 1e6)} tr`;
    if (a >= 1e3) return `${f(n / 1e3)} k`;
    return f(n);
}
