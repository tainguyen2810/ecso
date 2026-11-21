/** Kế toán, thuế: shapes the server returns and small helpers for periods. */
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
    channels: { label: string; kind: 'self' | 'market'; revenue: number; tax: number }[];
    self: number;
    market: number;
    tax: number;
    withheld: number;
    current: Declaration;
    todo: { declare: { period: string; label: string; deadline: string }; invoices_review: number; invoices_open: number };
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
