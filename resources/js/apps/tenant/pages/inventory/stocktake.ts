export interface CountRow {
    id: string;
    sku: string;
    barcode: string | null;
    name: string;
    /** System quantity the count is compared with: the one saved with the line, else stock when the page loaded. */
    on_hand: number;
    /** Stock now, when it differs from on_hand (goods sold or received since the line was counted). */
    on_hand_now?: number;
}

type Counts = Record<string, number | ''>;

/** Why a counted line differs (Sapo: Lý do), with a free note. */
export type LineNotes = Record<string, { reason?: string; note?: string }>;

export const varianceReasons: Record<string, string> = { damaged: 'Hỏng', lost: 'Mất, thất lạc', found: 'Tìm thấy thêm', correction: 'Sửa sai sót', other: 'Khác' };

/** Counted minus system quantity, or null when the SKU has not been counted. */
export function variance(row: CountRow, counts: Counts): number | null {
    const c = counts[row.id];
    return c === undefined || c === '' ? null : Number(c) - row.on_hand;
}

/** A scan adds one to the matching SKU (by barcode, then SKU, case-insensitive). Returns the SKU's id. */
export function applyScan(rows: CountRow[], counts: Counts, code: string): string | null {
    const lower = code.toLowerCase();
    const row = rows.find((r) => r.barcode === code) ?? rows.find((r) => r.sku.toLowerCase() === lower);
    if (!row) return null;
    const current = counts[row.id];
    counts[row.id] = (current === undefined || current === '' ? 0 : Number(current)) + 1;
    return row.id;
}

/** Adjustment lines in count mode: every counted SKU, plus uncounted ones as 0 when the whole warehouse is counted. */
export function stocktakeLines(
    rows: CountRow[],
    counts: Counts,
    zeroUncounted: boolean,
    notes: LineNotes = {},
): { variant_id: string; mode: 'count'; quantity: number; system_qty: number; reason?: string; note?: string }[] {
    // Reason and note only travel with a line that differs from the system.
    const extra = (r: CountRow, qty: number) => (qty === r.on_hand ? {} : { ...(notes[r.id]?.reason ? { reason: notes[r.id]!.reason } : {}), ...(notes[r.id]?.note ? { note: notes[r.id]!.note } : {}) });
    return rows.flatMap((r) => {
        const c = counts[r.id];
        if (c === undefined || c === '') {
            return zeroUncounted && r.on_hand !== 0 ? [{ variant_id: r.id, mode: 'count' as const, quantity: 0, system_qty: r.on_hand, ...extra(r, 0) }] : [];
        }
        const qty = Math.max(0, Math.floor(Number(c)));
        // The system quantity seen while counting travels with the line: approval applies only count − system_qty.
        return [{ variant_id: r.id, mode: 'count' as const, quantity: qty, system_qty: r.on_hand, ...extra(r, qty) }];
    });
}
