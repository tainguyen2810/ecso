import { fromIso, isoDate } from '@/shared/lib/dates';

/** One lot of a receipt line as typed (quantity stays a string while editing). */
export interface LotDraft {
    lot_no: string;
    mfg_date: string;
    expiry_date: string;
    quantity: string;
}

/** A lot as the API returns it on a document line, an order item or a pick list row. */
export interface LotSplit {
    lot_no: string;
    mfg_date?: string | null;
    expiry_date: string | null;
    quantity: number;
}

export interface LotRules {
    shelf_life_months?: number | null;
    require_mfg_date?: boolean;
    expiry_warn_days?: number | null;
}

export const DEFAULT_WARN_DAYS = 60;

export const blankLot = (quantity = ''): LotDraft => ({ lot_no: '', mfg_date: '', expiry_date: '', quantity });

/** yyyy-mm-dd + whole months, clamped to the month's last day (31/01 + 1 month = 28/02), like the server. */
export function addMonths(iso: string, months: number): string {
    const d = fromIso(iso);
    if (!d) return '';
    const target = new Date(d.getFullYear(), d.getMonth() + months, 1);
    const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    target.setDate(Math.min(d.getDate(), last));
    return isoDate(target);
}

/** Days from today to the expiry date (negative once expired, null without a date). */
export function daysLeft(expiry: string | null | undefined, today = new Date()): number | null {
    const d = fromIso(expiry);
    if (!d) return null;
    const t = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    return Math.round((d.getTime() - t.getTime()) / 86_400_000);
}

/** The expiry date a lot will get: typed, or manufacturing date + the product's shelf life. */
export function effectiveExpiry(lot: Pick<LotDraft, 'mfg_date' | 'expiry_date'>, rules: LotRules): string {
    if (lot.expiry_date) return lot.expiry_date;
    return lot.mfg_date && rules.shelf_life_months ? addMonths(lot.mfg_date, rules.shelf_life_months) : '';
}

/** What still stops the lots of a line from being saved (the server checks the same), or null. */
export function lotProblem(lots: LotDraft[], quantity: number, rules: LotRules): string | null {
    const filled = lots.filter((l) => Number(l.quantity) > 0);
    if (!filled.length) return 'Nhập số lô và hạn sử dụng.';
    for (const l of filled) {
        if (!l.lot_no.trim()) return 'Thiếu số lô.';
        if (rules.require_mfg_date && !l.mfg_date) return `Lô ${l.lot_no}: thiếu ngày sản xuất.`;
        if (!effectiveExpiry(l, rules)) return `Lô ${l.lot_no}: thiếu hạn sử dụng.`;
    }
    const sum = filled.reduce((t, l) => t + Number(l.quantity), 0);
    if (sum !== quantity) return `Tổng số lượng các lô (${sum}) phải bằng số nhập (${quantity}).`;
    return null;
}

/** Lots for the API: only rows with a quantity, numbers as numbers, empty dates as null. */
export function lotPayload(lots: LotDraft[]): { lot_no: string; mfg_date: string | null; expiry_date: string | null; quantity: number }[] {
    return lots
        .filter((l) => Number(l.quantity) > 0)
        .map((l) => ({ lot_no: l.lot_no.trim(), mfg_date: l.mfg_date || null, expiry_date: l.expiry_date || null, quantity: Number(l.quantity) }));
}

/** "Chưa rõ lô" is stored as an empty lot number. */
export const lotLabel = (lotNo: string | null | undefined) => (lotNo ? lotNo : 'Chưa rõ lô');
